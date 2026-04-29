# ConfigKit Phases

The phase plan. Each phase has explicit entry and exit criteria. Do not enter
Phase N+1 until Phase N is marked `complete` in `STATUS.md`.

---

## Phase 0 — Scaffold + specs

**Active.**

### Allowed
- Plugin scaffold (configkit.php with header).
- Spec documents in `docs/configkit/specs/`.
- UX documents in `docs/configkit/ux/`.
- `CLAUDE.md`, `STATUS.md`, `PHASES.md` at plugin root.

### Forbidden
- All runtime PHP/JS/CSS edits.
- All database writes.

### Exit criteria
- All four Batch 1 specs approved (TARGET, DATA, FIELD, MODULE_LIBRARY).
- All six Batch 2 specs approved (RULE, PRICING, VERSIONING, MIGRATION,
  MULTILANG, AUDIT).
- UX roadmap approved (8 docs).
- Owner explicitly signs off entry to Phase 1.

---

## Phase 1 — Schema migrations (additive)

### Allowed
- Migration runner class (`ConfigKit\Migration\Runner`).
- Migration files in `migrations/` numbered `0001_*.php` etc.
- `wp_configkit_migrations` table to track applied migrations.
- All other table creation per `DATA_MODEL.md`.
- Only `CREATE TABLE` and `CREATE INDEX` DDL.
- Idempotency: each migration checks if already applied.
- WP-CLI command `wp configkit migrate` to run pending migrations.

### Forbidden
- `DROP`, `ALTER`, `INSERT`, `UPDATE`, `DELETE`.
- Engine code.
- Admin UI.
- Frontend code.

### Exit criteria
- All tables from `DATA_MODEL.md §3` exist on staging.
- `wp configkit migrate` is idempotent (running twice is no-op).
- Migration log in `wp_configkit_migrations` shows clean history.
- PHPUnit unit tests for migration runner pass.
- Owner verifies tables in MariaDB CLI.

---

## Phase 2 — Engines

Pure-PHP engines, no WP dependencies, fully unit-testable.

### Allowed
- `ConfigKit\Engines\RuleEngine`
- `ConfigKit\Engines\PricingEngine`
- `ConfigKit\Engines\LookupEngine`
- `ConfigKit\Engines\ValidationEngine`
- `ConfigKit\Logger\*`
- `tests/unit/` PHPUnit tests with full coverage of engines.

### Forbidden
- Direct DB queries in engines (engines receive data, return data).
- Admin UI.
- Frontend code.
- HTTP routes (REST controllers come Phase 3).

### Exit criteria
- All engines have ≥ 90% line coverage in unit tests.
- Tests pass on PHP 8.1, 8.2, 8.3.
- Engines documented with example inputs/outputs.
- Owner reviews test reports.

---

## Phase 3 — Admin UI

### Allowed
- WP admin pages under "ConfigKit" menu.
- REST controllers in `configkit/v1/*` namespace.
- Repository classes (DB layer).
- Admin JS for forms and lists.
- All entities CRUD: modules, libraries, library items, families, templates,
  steps, fields, field_options, rules, lookup tables, lookup cells.

### Forbidden
- Frontend renderer.
- Cart/order integration.
- Excel import (Phase 6).

### Exit criteria
- Owner can create/edit/delete every entity through the admin UI.
- All CRUD passes its DoD checklist (`docs/DOD_CHECKLIST.md`).
- Optimistic locking via `version_hash` works.
- Diagnostics dashboard surfaces broken references.

---

## Phase 4 — Frontend renderer

### Allowed
- Frontend templates rendered on Woo product page.
- Stepper / accordion UI per `FRONTEND_CONFIGURATOR_UX.md`.
- Live price preview (UX only, server still validates).
- Sticky summary on desktop, sticky price bar on mobile.
- Rule evaluation in JS (using same JSON spec as server).
- Library item picker with search, filters, popular.

### Forbidden
- Server-side cart write (Phase 5).
- Order snapshot logic (Phase 5).

### Exit criteria
- Customer can configure a test product front-to-back without errors.
- Live price matches server recalc (logged mismatches = 0).
- All field types render correctly per `FIELD_MODEL.md §9`.

---

## Phase 5 — Cart + order integration

### Allowed
- Add-to-cart server-side validation.
- Cart item meta write.
- Cart recalculation on cart events.
- Order item meta snapshot at checkout.
- Order admin display of selections.
- Customer email integration.
- Production summary block in admin order.

### Exit criteria
- Add-to-cart works end-to-end.
- Cart shows selections and price breakdown.
- Order admin shows full snapshot.
- Customer email shows configured fields per template settings.
- Refunds and edits do not break the snapshot.

---

## Phase 6 — Excel import + diagnostics

### Allowed
- Excel import wizard (4 steps: upload, map, dry-run, commit).
- `wp_configkit_import_batches` and `wp_configkit_import_rows` writes.
- Rollback support.
- Diagnostics dashboard with all checks per `TARGET_ARCHITECTURE.md §11`.
- Auto-assignment helpers (family from category, template from family, etc).

### Exit criteria
- Owner imports 50 products from Excel without errors.
- Dry-run flags real issues, blocks commit on critical errors.
- Rollback restores prior state per import_batches log.
- Diagnostics catches at least 10 different broken-state types.

---

## Phase 7 — First product end-to-end

Pilot test: one real Sologtak product configured front-to-back.

### Allowed
- Manual data entry or Excel import for one Markise product.
- Full DoD walkthrough.
- Performance measurement against `TARGET_ARCHITECTURE.md §10`.

### Exit criteria
- Owner can purchase the test product end-to-end.
- Order admin shows production-ready summary.
- All performance targets met or warnings logged.
- `STATUS.md` shows all features `complete` for this product family.

After Phase 7 green, repeat for next product family. Slow rollout.

---

## Cross-phase rules

- Every phase has a Git branch: `phase/N-<short-name>`.
- Every commit references the phase: `phase 1: ...`.
- `STATUS.md` updated on every commit that changes feature status.
- Owner sign-off required before phase transition.
- Failed phase exit → return to phase, not skip.
