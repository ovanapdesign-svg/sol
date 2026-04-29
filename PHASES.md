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
- WP-CLI command `wp configkit migrate` to run pending migrations.
- DDL: `CREATE TABLE`, `CREATE INDEX`.
- DML, narrowly scoped:
  - `INSERT` into `wp_configkit_migrations` only (the migration log).
  - `UPDATE` of the `configkit_db_version` option only if the migration
    runner uses it.
  - Optional `INSERT` of built-in seed data only if documented in the
    migration file and idempotent (re-running must be a no-op).
- Idempotency: each migration checks if already applied.

### Forbidden
- `DROP`.
- Destructive `ALTER` (column drops, type narrowing).
- `DELETE`.
- Product / meta writes.
- Woo product changes.
- Runtime behavior changes.
- Engine code.
- Admin UI.
- Frontend code.
- Cart / order writes.

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

## Phase 3 — Admin UI core

Core CRUD scaffolding so the owner can build product configurators by hand
before Excel import and frontend polish arrive in later phases.

### Entry criteria
- WooCommerce installed and active.
- Phase 2 marked `complete` in `STATUS.md`.

### Allowed
- WP admin pages under "ConfigKit" menu.
- REST controllers in `configkit/v1/*` namespace.
- Repository classes (DB layer).
- Admin JS for forms and lists.
- Core CRUD for: Product Binding, Families, Templates, Libraries,
  Library Items, Lookup Tables, Lookup Cells, Rules.
- Steps, Fields, Field Options CRUD as part of Templates.

### Forbidden
- Excel import wizard (moves to Phase 4).
- Diagnostics dashboard (moves to Phase 4).
- Product Readiness Board (moves to Phase 4).
- Frontend renderer (Phase 5).
- Cart / order integration (Phase 6).

### Exit criteria
- Owner can create/edit/delete every entity through the admin UI.
- All CRUD passes its DoD checklist (`docs/DOD_CHECKLIST.md`).
- Optimistic locking via `version_hash` works.

---

## Phase 4 — Excel Import + Diagnostics + Product Readiness Board

The owner-mission phase: bulk-create configurators by uploading lookup
tables and library items from Excel, with diagnostics and a Product
Readiness Board to flag missing pieces before frontend polish.

### Entry criteria
- Phase 3 marked `complete` in `STATUS.md`.

### Allowed
- Excel import wizard (4 steps: upload, map, dry-run, commit).
- `wp_configkit_import_batches` and `wp_configkit_import_rows` writes.
- Rollback support.
- Diagnostics dashboard with broken-reference checks (per
  `AUDIT_CHECKLIST.md`, Batch 2).
- Auto-assignment helpers (family from category, template from family, etc).
- Product Readiness Board: per-product checklist showing what is wired,
  what is missing, and what blocks publish.

### Forbidden
- Frontend renderer (Phase 5).
- Cart / order integration (Phase 6).

### Exit criteria
- Owner imports 50 products from Excel without errors.
- Dry-run flags real issues, blocks commit on critical errors.
- Rollback restores prior state per import_batches log.
- Diagnostics catches at least 10 different broken-state types.
- Readiness Board correctly classifies every product as ready / blocked.

---

## Phase 5 — Frontend renderer

### Entry criteria
- Phase 4 marked `complete` in `STATUS.md`.

### Allowed
- Frontend templates rendered on Woo product page.
- Stepper / accordion UI per `FRONTEND_CONFIGURATOR_UX.md`.
- Live price preview (UX only, server still validates).
- Sticky summary on desktop, sticky price bar on mobile.
- Rule evaluation in JS (using same JSON spec as server).
- Library item picker with search, filters, popular.

### Forbidden
- Server-side cart write (Phase 6).
- Order snapshot logic (Phase 6).

### Exit criteria
- Customer can configure a test product front-to-back without errors.
- Live price matches server recalc (logged mismatches = 0).
- All field types render correctly per `FIELD_MODEL.md §9`.

---

## Phase 6 — Cart + Order integration

### Entry criteria
- Phase 5 marked `complete` in `STATUS.md`.

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

## Phase 7 — First product end-to-end

Pilot test: one real Sologtak product configured front-to-back.

### Entry criteria
- Phase 6 marked `complete` in `STATUS.md`.

### Allowed
- Manual data entry or Excel import for one Markise product.
- Full DoD walkthrough.
- Performance measurement against `TARGET_ARCHITECTURE.md §6`.

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
