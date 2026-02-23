# Project Context (main snapshot, fast agent onboarding)

## 1) What this project is
Laravel 12 + Filament ERP-style app with:
- admin panel (`/admin`) for configuration/integrations
- operational panel (`/`) for day-to-day work (products, offers, customers)
- integration pipelines:
  - WooCommerce catalog import (categories/products)
  - WinMentor CSV stock/price sync, including deferred Woo price pushes

This file is optimized to reduce context-loading tokens for future agents.
For issue-focused reliability notes, also read `docs/MAIN_ANALYSIS_NOTES.md`.

---

## 2) Stack and runtime
- PHP 8.2
- Laravel 12
- Filament 3
- Queue-driven background processing
- Vite/Tailwind frontend assets
- Main extra package: `awcodes/filament-table-repeater`

Important composer scripts:
- `composer setup`
- `composer dev` (server + queue listener + logs + vite)
- `composer test`

---

## 3) Panels and access model
- `AdminPanelProvider` (`/admin`):
  - for admin users
  - includes integrations, settings, sync run monitoring
  - has `IntegrationImportStatusWidget`
- `AppPanelProvider` (`/`):
  - for operational users (and super admin)
  - includes sales/workflow resources (offers, customers, Woo product browsing)

User access is driven by:
- `User::isAdmin()`
- `User::isSuperAdmin()`
- location scope via `User::operationalLocationIds()`

---

## 4) Core domain modules

### A) Integrations
- `integration_connections`
  - providers:
    - `woocommerce`
    - `winmentor_csv`
    - `sameday`
  - provider-specific settings live in `settings` JSON
- `sync_runs`
  - tracks every import execution
  - statuses: `queued`, `running`, `success`, `failed`, `cancelled`
  - stores rich `stats` + `errors` payloads

### B) Woo catalog
- `woo_categories`
- `woo_products`
- pivot: `woo_product_category`
- plus local inventory/trace tables:
  - `product_stocks`
  - `product_price_logs`

### C) Sales
- `customers`
- `customer_delivery_addresses`
- `offers`
- `offer_items`

### D) Company data API
- `company_api_settings` (OpenAPI provider)
- used to auto-populate company/customer details by CUI

---

## 5) Integration flows (most important)

### 5.1 Woo categories import
Code path:
- `ImportWooCategoriesJob`
- `ImportWooCategoriesAction`

Flow:
1. Create `sync_runs` row (`running`)
2. Pull paginated categories from Woo
3. Upsert by `(connection_id, woo_id)`
4. Rebuild tree (`parent_id`) from `parent_woo_id`
5. Mark run `success` / `failed` / `cancelled`

Notes:
- Category names are HTML-decoded.
- Import loop checks cancellation checkpoints.
- Parent normalization is strict positive integer.

### 5.2 Woo products import
Code path:
- `ImportWooProductsJob`
- `ImportWooProductsAction`

Flow:
1. Create `sync_runs` row (`running`)
2. Pull paginated products
3. Upsert by `(connection_id, woo_id)`
4. Sync product-category pivot
5. Update run stats/errors and final status

Notes:
- `manage_stock` parsing is boolean-safe (string "false" stays false).
- Missing category mappings are captured in run errors.

### 5.3 WinMentor CSV import (stock/price)
Code path:
- `ImportWinmentorCsvJob`
- `ImportWinmentorCsvAction`
- `PushWinmentorPricesToWooJob`

Flow:
1. Create queued `sync_runs` row from UI action.
2. Queue job starts local CSV import (`phase: local_import`):
   - download CSV
   - parse by configured column names
   - match SKU to Woo products
   - create ERP placeholder Woo product when SKU missing
   - upsert `product_stocks`
   - insert `product_price_logs`
3. If configured, queue deferred Woo price push jobs (`phase: pushing_prices`).
4. Deferred jobs update `sync_runs.stats` counters and finish run:
   - `success` if no Woo push failures
   - `failed` if push failures exist

Key phase values in `sync_runs.stats.phase`:
- `queued`
- `local_import`
- `queueing_price_push`
- `pushing_prices`
- `completed`
- `completed_with_errors`
- `failed`
- `cancelled`

---

## 6) Filament resources worth knowing first

Admin panel:
- `IntegrationConnectionResource`
  - test connection (Woo, WinMentor CSV, Sameday)
  - import actions:
    - Woo categories
    - Woo products
    - Woo full chain (`Import all`)
    - WinMentor CSV stock/price
- `SyncRunResource`
  - includes stop action for queued/running imports
  - rich columns for phase, heartbeat, queue progress
- `ProductPriceLogResource`
- `LocationResource`
- `CompanyApiSettingResource`
- `UserResource`

App panel:
- `WooProductResource`, `WooCategoryResource`
- `OfferResource` (+ print/preview pages)
- `CustomerResource`

---

## 7) CLI commands
- `woo:import-categories {connectionId}`
- `woo:import-products {connectionId}`
- `stock:import-winmentor {connectionId}`
- `stock:dispatch-scheduled-winmentor`

Sameday SDK install (required for Sameday test connection):
- `composer require sameday-courier/php-sdk`

Scheduler behavior:
- `routes/console.php` runs `stock:dispatch-scheduled-winmentor` every minute.
- Each active WinMentor connection can control its own schedule from settings:
  - `settings.auto_sync_enabled` (on/off)
  - `settings.sync_interval_minutes` (minimum 5 minutes)

---

## 8) Queue troubleshooting quick guide

If worker shows pattern like many fast `FAIL` and then some `DONE`:
1. Check real exception message in logs:
   - `storage/logs/laravel.log`
   - search for:
     - `Winmentor import queue job failed`
     - `Winmentor import failed`
     - `Deferred Woo price push job failed`
2. Inspect `sync_runs` for the exact `connection_id` / `sync_run_id`.
3. Verify connection settings:
   - provider correct
   - CSV URL reachable
   - required CSV columns exist
   - Woo connection exists for same location
4. Check failed jobs:
   - `php artisan queue:failed`
   - `php artisan queue:retry <id>`

Important:
- Queue worker output only shows class-level FAIL/DONE.
- Root cause is in exception logs and `sync_runs.errors`.

---

## 9) High-risk / high-change files
- `app/Actions/Winmentor/ImportWinmentorCsvAction.php`
- `app/Jobs/PushWinmentorPricesToWooJob.php`
- `app/Filament/Resources/IntegrationConnectionResource.php`
- `app/Filament/Resources/SyncRunResource.php`
- `app/Services/WooCommerce/WooClient.php`
- `app/Filament/App/Resources/OfferResource.php`
- `app/Filament/App/Resources/CustomerResource.php`

---

## 10) Fast onboarding checklist for a new agent
1. Read this file.
2. Read integration actions/jobs (Woo + WinMentor).
3. Read `IntegrationConnectionResource` + `SyncRunResource`.
4. For sales changes, read `OfferResource` and `CustomerResource`.
5. Before fixes, inspect latest `sync_runs` rows and `stats.phase`.
6. Keep changes small; always verify queue side-effects.
