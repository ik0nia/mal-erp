# Project Context (for fast agent onboarding)

## Goal
Laravel 12 + Filament app for operational teams and admins.  
Main domain: locations, users/roles, WooCommerce integration connections, and Woo catalog sync (categories + products).

## Tech stack
- PHP 8.2
- Laravel 12
- Filament 3
- SQLite/MySQL compatible schema (tests configured with sqlite in-memory)
- Queue jobs for long Woo imports

## Main functional areas

### 1) Locations and users
- `locations`: store / warehouse / office
- `users`: role + location + admin flags (`is_admin`, `is_super_admin`)
- Non-super users are scoped to operational locations via `User::operationalLocationIds()`.

### 2) Integration connections
- `integration_connections`: Woo credentials and settings (`per_page`, `timeout`, SSL verify)
- Admin-only management in `App\Filament\Resources\IntegrationConnectionResource`.

### 3) Woo catalog data
- `woo_categories`
- `woo_products`
- pivot: `woo_product_category`
- `sync_runs`: audit trail for import runs (status, stats, errors)

## Import flow (important)

### Categories
1. Fetch paginated categories from Woo API.
2. Upsert by `(connection_id, woo_id)`.
3. Persist `parent_woo_id` and rebuild `parent_id` hierarchy.
4. Save run stats/errors in `sync_runs`.

### Products
1. Fetch paginated products from Woo API.
2. Upsert by `(connection_id, woo_id)`.
3. Sync product-category pivot by Woo category IDs already imported locally.
4. Save run stats/errors in `sync_runs`.

## Jobs and commands
- Jobs:
  - `ImportWooCategoriesJob`
  - `ImportWooProductsJob`
- Commands:
  - `woo:import-categories {connectionId}`
  - `woo:import-products {connectionId}`
- UI action also supports chained full import (`Import all`) from connection list.

## Filament panels
- `AdminPanelProvider` (`/admin`): admin-only resources.
- `AppPanelProvider` (`/`): operational panel for non-admin users + super-admin.

## High-value files for future agents
- Models:
  - `app/Models/IntegrationConnection.php`
  - `app/Models/SyncRun.php`
  - `app/Models/WooCategory.php`
  - `app/Models/WooProduct.php`
  - `app/Models/User.php`
- Woo integration:
  - `app/Services/WooCommerce/WooClient.php`
  - `app/Actions/WooCommerce/ImportWooCategoriesAction.php`
  - `app/Actions/WooCommerce/ImportWooProductsAction.php`
  - `app/Jobs/ImportWooCategoriesJob.php`
  - `app/Jobs/ImportWooProductsJob.php`
- Admin resource:
  - `app/Filament/Resources/IntegrationConnectionResource.php`

## Known constraints / caveats
- Queue worker must be running for async imports.
- Product-category linking expects categories to be imported first.
- If PHP/composer are missing in CI sandbox, runtime tests cannot be executed locally.

## Fast-start checklist for a new agent
1. Read this file first.
2. Read Woo actions + IntegrationConnection resource.
3. Inspect latest `sync_runs` behavior before changing import logic.
4. Prefer small, isolated fixes with clear migration/test impact.
