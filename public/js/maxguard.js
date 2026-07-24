(function () {
    'use strict';

    function setSidebar(open) {
        document.body.classList.toggle('mg-sidebar-open', open);
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-mg-sidebar-open]')) setSidebar(true);
        if (event.target.closest('[data-mg-sidebar-close]')) setSidebar(false);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') setSidebar(false);
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            var search = document.querySelector('.mg-search input');
            if (search) search.focus();
        }
    });

    function initComplianceChart() {
        var element = document.getElementById('compliance-trend-chart');
        if (!element || typeof ApexCharts === 'undefined' || !window.MaxGuardPage) return;

        var chart = new ApexCharts(element, {
            series: [{ name: 'Compliance score', data: window.MaxGuardPage.trend }],
            chart: {
                type: 'area',
                height: 240,
                toolbar: { show: false },
                zoom: { enabled: false },
                fontFamily: 'Inter, sans-serif'
            },
            colors: ['#2563eb'],
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 3 },
            fill: {
                type: 'gradient',
                gradient: { shadeIntensity: 1, opacityFrom: 0.22, opacityTo: 0.02, stops: [0, 95, 100] }
            },
            grid: { borderColor: '#e2e8f0', strokeDashArray: 0, padding: { left: 8, right: 8 } },
            xaxis: {
                categories: window.MaxGuardPage.trendLabels,
                axisBorder: { show: false },
                axisTicks: { show: false },
                labels: { style: { colors: '#94a3b8', fontSize: '10px' } }
            },
            yaxis: {
                min: 40,
                max: 100,
                tickAmount: 3,
                labels: { style: { colors: '#94a3b8', fontSize: '10px' } }
            },
            tooltip: { theme: 'light', y: { formatter: function (value) { return value + '/100'; } } },
            markers: { size: 0, hover: { size: 5 } }
        });

        chart.render();
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>'"]/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#039;',
                '"': '&quot;'
            }[character];
        });
    }

    function badge(value) {
        var tones = {
            completed: 'success', healthy: 'success',
            running: 'primary', scanning: 'primary', queued: 'info',
            partial: 'warning', review: 'warning', high: 'warning',
            failed: 'danger', critical: 'danger', cancelled: 'secondary', info: 'info'
        };
        var tone = tones[value] || 'secondary';
        return '<span class="badge badge-light-' + tone + '">' + escapeHtml(value) + '</span>';
    }

    function renderLiveScans(scans) {
        var body = document.getElementById('mg-live-scans-body');
        if (!body) return;
        if (!scans.length) {
            body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-10">No scans have been queued yet.</td></tr>';
            return;
        }

        body.innerHTML = scans.map(function (scan) {
            var current = scan.current_url
                ? '<span class="d-block text-primary fs-9 mt-1 text-truncate mw-250px" title="' + escapeHtml(scan.current_url) + '">Now: ' + escapeHtml(scan.current_url) + '</span>'
                : '';
            var error = scan.error_message
                ? '<span class="d-block text-danger fs-9 mt-1 text-truncate mw-200px" title="' + escapeHtml(scan.error_message) + '">' + escapeHtml(scan.error_message) + '</span>'
                : '';
            var ai = scan.ai_enabled
                ? '<span class="badge badge-light-info mt-2"><i class="bi bi-stars me-1"></i>AI ' + Number(scan.ai_pages_analyzed).toLocaleString() + ' pages</span>'
                : '<span class="d-block text-muted fs-9 mt-2">Rules only</span>';
            if (scan.ai_enabled && scan.ai_limit_reached) {
                ai += '<span class="d-block text-warning fs-9 mt-1">AI safety cap reached</span>';
            }
            if (scan.ai_enabled && Number(scan.ai_errors) > 0) {
                ai += '<span class="d-block text-danger fs-9 mt-1">' + Number(scan.ai_errors).toLocaleString() + ' AI errors</span>';
            }
            var aiFindings = scan.ai_findings_count > 0
                ? '<span class="d-block text-info fs-9 mt-1">' + Number(scan.ai_findings_count).toLocaleString() + ' from AI</span>'
                : '';
            var reused = Number(scan.pages_skipped_unchanged) > 0
                ? '<span class="d-block text-success fs-9 mt-1">' + Number(scan.pages_skipped_unchanged).toLocaleString() + ' unchanged · analysis skipped</span>'
                : '';
            var analyzed = Math.max(0, Number(scan.pages_scanned) - Number(scan.pages_skipped_unchanged));
            var forced = scan.force_rescan
                ? '<span class="d-block text-warning fs-9 mt-1">Forced re-analysis</span>'
                : '';
            var sampleBadge = scan.is_sampled
                ? '<span class="badge badge-light-info mt-1">Latest sample</span>'
                : '';
            var sampleDetail = scan.is_sampled
                ? '<span class="d-block text-info fs-9 mt-1">Latest ' + Number(scan.pages_discovered).toLocaleString() + ' of ' + Number(scan.available_urls).toLocaleString() + ' posts selected by lastmod</span>'
                : '';
            var batchDetail = scan.parallel_scan
                ? '<span class="d-block text-primary fs-9 mt-1">' + Number(scan.batches_completed).toLocaleString() + ' / ' + Number(scan.batches_total).toLocaleString() + ' batches · ' + Number(scan.targets_running).toLocaleString() + ' URLs active · ' + Number(scan.targets_queued).toLocaleString() + ' waiting</span>'
                : '';
            if (scan.parallel_scan && Number(scan.targets_failed) > 0) {
                batchDetail += '<span class="d-block text-danger fs-9 mt-1">' + Number(scan.targets_failed).toLocaleString() + ' URL jobs failed</span>';
            }
            var limitLabel = scan.is_sampled ? 'newest-post sample' : 'URL cap';

            return '<tr>' +
                '<td><a class="fw-semibold" href="' + escapeHtml(scan.detail_url) + '">' + escapeHtml(scan.website) + '</a><a class="d-block fs-9 text-primary mt-1" href="' + escapeHtml(scan.detail_url) + '">View URL details →</a></td>' +
                '<td>' + escapeHtml(scan.type) + '</td>' +
                '<td>' + badge(scan.status) + sampleBadge + error + '</td>' +
                '<td><div class="d-flex align-items-center gap-3"><div class="progress h-6px w-100px"><div class="progress-bar bg-primary" style="width:' + Number(scan.progress) + '%"></div></div><span>' + Number(scan.progress) + '%</span></div></td>' +
                '<td><strong>' + Number(scan.pages_scanned).toLocaleString() + ' / ' + Number(scan.pages_discovered).toLocaleString() + '</strong><span class="d-block text-muted fs-9">checked / selected</span><span class="d-block text-muted fs-9 mt-1">' + analyzed.toLocaleString() + ' analyzed</span>' + reused + sampleDetail + '<span class="d-block text-muted fs-9 mt-1">' + Number(scan.sitemaps).toLocaleString() + ' sitemaps · ' + Number(scan.failed).toLocaleString() + ' failed · ' + Number(scan.blocked).toLocaleString() + ' blocked</span>' + batchDetail + current + '</td>' +
                '<td><strong>' + (scan.max_urls ? Number(scan.max_urls).toLocaleString() : 'Global') + '</strong><span class="d-block text-muted fs-9">' + limitLabel + '</span>' + ai + forced + '</td>' +
                '<td><strong>' + Number(scan.findings_count).toLocaleString() + '</strong><span class="d-block text-muted fs-9">detected this scan</span>' + aiFindings + '</td>' +
                '<td class="text-muted">' + escapeHtml(scan.started) + '</td>' +
                '</tr>';
        }).join('');
    }

    function renderLiveFindings(findings) {
        var body = document.getElementById('mg-live-findings-body');
        if (!body) return;
        if (!findings.length) {
            body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-10">No findings have been detected yet. This table updates while scans run.</td></tr>';
            return;
        }

        body.innerHTML = findings.map(function (finding) {
            var analyzerClass = finding.source === 'AI' ? 'badge-light-info' : 'badge-light';
            return '<tr>' +
                '<td><strong>' + escapeHtml(finding.website) + '</strong><span class="d-block text-muted fs-9 text-truncate mw-300px" title="' + escapeHtml(finding.url) + '">' + escapeHtml(finding.url) + '</span></td>' +
                '<td><strong>' + escapeHtml(finding.title) + '</strong><span class="d-block text-muted fs-9">' + escapeHtml(finding.category) + '</span></td>' +
                '<td><span class="badge ' + analyzerClass + '">' + escapeHtml(finding.source) + '</span></td>' +
                '<td>' + badge(finding.severity) + '</td>' +
                '<td><strong>' + Number(finding.confidence) + '%</strong></td>' +
                '<td class="text-muted">' + escapeHtml(finding.detected) + '</td>' +
                '<td class="text-end"><a href="' + escapeHtml(finding.detail_url) + '" class="btn btn-sm btn-icon btn-light"><i class="bi bi-arrow-right"></i></a></td>' +
                '</tr>';
        }).join('');
    }

    function initLiveScanCenter() {
        if (!window.MaxGuardLive || !document.getElementById('mg-live-scans-body')) return;
        var busy = false;

        function refresh() {
            if (busy || document.hidden) return;
            busy = true;
            fetch(window.MaxGuardLive.endpoint, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            }).then(function (response) {
                if (!response.ok) throw new Error('Live report request failed.');
                return response.json();
            }).then(function (payload) {
                renderLiveScans(payload.scans || []);
                renderLiveFindings(payload.findings || []);
            }).catch(function () {
                // Preserve the last successful report; the next polling cycle retries automatically.
            }).finally(function () {
                busy = false;
            });
        }

        window.setTimeout(refresh, 1000);
        window.setInterval(refresh, Math.max(2000, Number(window.MaxGuardLive.pollMs) || 4000));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initComplianceChart();
            initLiveScanCenter();
        });
    } else {
        initComplianceChart();
        initLiveScanCenter();
    }
})();
