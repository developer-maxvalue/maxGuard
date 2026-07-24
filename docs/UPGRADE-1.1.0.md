# Upgrade MaxGuard 1.0.3 → 1.1.0

## 1. Merge source changes

Merge `app`, `config`, `database/migrations`, `public`, `resources/views`, `routes`, and `tests` into the Laravel 10 project.

## 2. Install the Excel dependency

```bash
composer require phpoffice/phpspreadsheet:^1.29
```

## 3. Run the new migration

```bash
php artisan optimize:clear
php artisan migrate
```

Migration `2026_07_22_000003_add_ai_and_live_progress_to_scans.php` adds per-scan URL limits, AI counters and the current URL field.

## 4. Configure optional AI analysis

```dotenv
OPENAI_API_KEY=your-project-api-key
MAXGUARD_AI_ENABLED=true
MAXGUARD_AI_MODEL=gpt-5.6-terra
MAXGUARD_AI_REASONING_EFFORT=low
MAXGUARD_AI_MAX_PAGES_PER_SCAN=100
MAXGUARD_AI_MIN_CONFIDENCE=70
```

Do not commit the real key. Leave `MAXGUARD_AI_ENABLED=false` to run the deterministic rule engine only.

## 5. Restart workers

```bash
php artisan optimize:clear
php artisan queue:restart
php artisan maxguard:queue-doctor
php artisan queue:work database --queue=scans --sleep=2 --tries=3 --timeout=21600 --memory=1024
```

## 6. Verify

- Open `/scan-center`.
- Choose a website and enter a small `Maximum URLs` value such as `10`.
- Enable AI if configured and queue the scan.
- Confirm scanned/discovered/cap and current URL update without a full page reload.
- Confirm findings appear in **Live findings report** before the scan finishes.
- Download **Export Excel** and open the `.xlsx` file.
