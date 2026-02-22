# Main Branch Analysis Notes

## Scope
Quick technical analysis of current main functionality, focused on integration reliability and high-impact maintenance areas.

## Confirmed architecture direction
- Two-panel Filament setup (admin + operational).
- Integrations are centered around `integration_connections` and `sync_runs`.
- Woo imports and WinMentor imports are intentionally async-first and observable via `sync_runs`.
- Sales workflow (customers + offers) is now a first-class module.

## Reliability hotspots (ordered by operational impact)

1. WinMentor queue execution visibility depends on `sync_runs` + logs
- Worker output (`RUNNING/FAIL/DONE`) is not enough for root-cause analysis.
- Real diagnosis path:
  - `storage/logs/laravel.log`
  - `sync_runs.errors`
  - `sync_runs.stats.phase`

2. Per-connection import overlap can still produce confusing worker output
- UI prevents duplicate queued/running runs for same connection.
- If jobs are triggered from outside UI or old queued jobs remain, mixed FAIL/DONE sequences can appear.
- Always correlate by `sync_run_id` and `connection_id`.

3. CSV schema drift is a frequent failure vector
- Missing required columns (`sku`, `quantity`, `price`) fails import early.
- Header typo fallback exists only for `cantiate`.
- Validate supplier CSV headers whenever source changes.

4. Deferred Woo price pushes are a second stage
- Local import may complete while Woo push jobs continue in background.
- Final run status can still flip to failed if push stage records failures.
- Check:
  - `site_price_push_queued`
  - `site_price_push_processed`
  - `site_price_update_failures`

5. Limited automated test coverage
- Current repo has baseline example tests only.
- Most critical paths (imports, queue transitions, cancellation, retries) are not protected by dedicated tests.

## Suggested next hardening tasks
- Add integration tests for WinMentor phases (`local_import` -> `pushing_prices` -> final status).
- Add idempotency/overlap guard at job middleware layer for `ImportWinmentorCsvJob`.
- Add run-level correlation id in all related logs (`sync_run_id` already present in most places, keep strict consistency).
- Add an admin action to display latest failed exception directly from `sync_runs.errors`.
