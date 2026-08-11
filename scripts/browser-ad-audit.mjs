import { chromium } from 'playwright';
import dns from 'node:dns/promises';
import net from 'node:net';

const input = await new Promise((resolve, reject) => {
  let body = '';
  process.stdin.setEncoding('utf8');
  process.stdin.on('data', chunk => { body += chunk; });
  process.stdin.on('end', () => {
    try { resolve(JSON.parse(body)); } catch (error) { reject(error); }
  });
  process.stdin.on('error', reject);
});

const dnsCache = new Map();

function isBlockedIp(address) {
  if (net.isIPv4(address)) {
    const octets = address.split('.').map(Number);
    const [a, b, c] = octets;
    return a === 0 || a === 10 || a === 127 || a >= 224 ||
      (a === 100 && b >= 64 && b <= 127) || (a === 169 && b === 254) ||
      (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168) ||
      (a === 192 && ((b === 0 && [0, 2].includes(c)) || (b === 31 && c === 196) ||
        (b === 52 && c === 193) || (b === 88 && c === 99) || (b === 175 && c === 48))) ||
      (a === 198 && (b === 18 || b === 19 || (b === 51 && c === 100))) ||
      (a === 203 && b === 0 && c === 113);
  }
  if (net.isIPv6(address)) {
    const value = address.toLowerCase();
    const mapped = value.match(/^::ffff:(\d+\.\d+\.\d+\.\d+)$/);
    if (mapped) return isBlockedIp(mapped[1]);
    return value === '::' || value === '::1' || value.startsWith('fc') ||
      value.startsWith('fd') || /^fe[89ab]/.test(value) || value.startsWith('ff') ||
      value.startsWith('100:') || value.startsWith('2001:db8:');
  }
  return true;
}

async function isSafeUrl(rawUrl) {
  let parsed;
  try { parsed = new URL(rawUrl); } catch { return false; }
  if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) return false;
  const port = parsed.port || (parsed.protocol === 'https:' ? '443' : '80');
  if (!['80', '443'].includes(port)) return false;
  const hostname = parsed.hostname.toLowerCase().replace(/^\[|\]$/g, '').replace(/\.$/, '');
  if (hostname === 'localhost' || hostname.endsWith('.localhost') || hostname.endsWith('.local') || hostname.endsWith('.internal')) return false;
  if (net.isIP(hostname)) return !isBlockedIp(hostname);
  if (!dnsCache.has(hostname)) {
    dnsCache.set(hostname, dns.lookup(hostname, { all: true, verbatim: true }).catch(() => []));
  }
  const addresses = await dnsCache.get(hostname);
  return addresses.length > 0 && addresses.every(item => !isBlockedIp(item.address));
}

const adSelector = [
  'ins.adsbygoogle', '[data-ad-client]', '[data-ad-slot]',
  '[id*="ad-slot" i]', 'iframe[src*="googlesyndication.com" i]',
  'iframe[src*="doubleclick.net" i]', 'iframe[id*="google_ads" i]'
].join(',');

async function auditViewport(browser, viewport, input) {
  const context = await browser.newContext({
    viewport: { width: viewport.width, height: viewport.height },
    userAgent: input.userAgent || undefined,
    locale: 'vi-VN',
    ignoreHTTPSErrors: false,
  });
  let blockedRequests = 0;
  await context.route('**/*', async route => {
    if (await isSafeUrl(route.request().url())) await route.continue();
    else { blockedRequests++; await route.abort('blockedbyclient'); }
  });
  await context.addInitScript(() => {
    window.__maxGuardPopupAttempts = 0;
    window.open = function () {
      window.__maxGuardPopupAttempts++;
      return null;
    };
  });

  const page = await context.newPage();
  await page.goto(input.url, { waitUntil: 'domcontentloaded', timeout: input.timeoutMs });
  await page.waitForTimeout(input.settleMs);
  const beforeSources = await page.locator(adSelector).evaluateAll(nodes => nodes.map(node =>
    node.getAttribute('src') || node.getAttribute('data-ad-slot') || node.innerHTML.length.toString()
  ));
  await page.waitForTimeout(Math.min(1500, input.settleMs));

  const result = await page.evaluate(({ adSelector, proximityPx, beforeSources }) => {
    const visible = element => {
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity) > 0 && rect.width > 2 && rect.height > 2;
    };
    const rectData = rect => ({ x: Math.round(rect.x), y: Math.round(rect.y), width: Math.round(rect.width), height: Math.round(rect.height) });
    const distance = (a, b) => Math.max(0, Math.max(b.left - a.right, a.left - b.right), Math.max(b.top - a.bottom, a.top - b.bottom));
    const ads = [...document.querySelectorAll(adSelector)].filter(visible);
    const controls = [...document.querySelectorAll('a[href],button,input,select,textarea,[role="button"],[onclick]')].filter(visible);
    const misleadingPattern = /(download|tải xuống|tiếp theo|next|menu|điều hướng|navigation|tài nguyên|resources|helpful links|liên kết hữu ích)/i;
    let nearInteractiveCount = 0;
    let misleadingLabelCount = 0;
    let intrusiveOverlayCount = 0;
    let adArea = 0;
    const samples = [];
    for (const ad of ads) {
      const rect = ad.getBoundingClientRect();
      adArea += Math.max(0, Math.min(innerWidth, rect.right) - Math.max(0, rect.left)) * Math.max(0, Math.min(innerHeight, rect.bottom) - Math.max(0, rect.top));
      const nearby = controls.filter(control => control !== ad && !ad.contains(control) && distance(rect, control.getBoundingClientRect()) <= proximityPx);
      if (nearby.length) nearInteractiveCount++;
      const contextText = [ad.previousElementSibling?.textContent, ad.parentElement?.previousElementSibling?.textContent, ad.parentElement?.textContent]
        .filter(Boolean).join(' ').slice(0, 500);
      if (misleadingPattern.test(contextText)) misleadingLabelCount++;
      let parent = ad;
      while (parent && parent !== document.body) {
        const style = getComputedStyle(parent);
        const parentRect = parent.getBoundingClientRect();
        if (['fixed', 'sticky'].includes(style.position) && parentRect.width * parentRect.height >= innerWidth * innerHeight * 0.20) {
          intrusiveOverlayCount++;
          break;
        }
        parent = parent.parentElement;
      }
      if (samples.length < 8) samples.push({ selector: ad.tagName.toLowerCase() + (ad.id ? '#' + ad.id : ''), rect: rectData(rect), nearbyControls: nearby.length });
    }
    const afterSources = ads.map(node => node.getAttribute('src') || node.getAttribute('data-ad-slot') || node.innerHTML.length.toString());
    const adRefreshCount = afterSources.filter((value, index) => beforeSources[index] !== undefined && value !== beforeSources[index]).length;
    return {
      adCount: ads.length,
      nearInteractiveCount,
      misleadingLabelCount,
      intrusiveOverlayCount,
      viewportCoverage: Math.min(1, Number((adArea / Math.max(1, innerWidth * innerHeight)).toFixed(4))),
      popupAttempts: Number(window.__maxGuardPopupAttempts || 0),
      adRefreshCount,
      samples,
      finalUrl: location.href,
    };
  }, { adSelector, proximityPx: input.proximityPx, beforeSources });

  await context.close();
  return { ...result, name: viewport.name, width: viewport.width, height: viewport.height, blockedRequests };
}

let browser;
try {
  if (!(await isSafeUrl(input.url))) throw new Error('Target URL is not a public HTTP(S) destination.');
  browser = await chromium.launch({ headless: true });
  const results = [];
  for (const viewport of [
    { name: 'desktop', width: 1366, height: 768 },
    { name: 'mobile', width: 390, height: 844 },
  ]) {
    results.push(await auditViewport(browser, viewport, input));
  }
  const blockedRequests = results.reduce((total, item) => total + item.blockedRequests, 0);
  process.stdout.write(JSON.stringify({ ok: true, blockedRequests, viewports: results.map(({ blockedRequests: _, ...item }) => item) }));
} catch (error) {
  process.stdout.write(JSON.stringify({ ok: false, error: String(error?.message || error) }));
  process.exitCode = 1;
} finally {
  if (browser) await browser.close();
}
