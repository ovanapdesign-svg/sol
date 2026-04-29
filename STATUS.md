# ConfigKit Status

Single source of truth for feature status. Updated on every PR that changes
status.

Statuses:
- `complete` — passes full DoD checklist
- `partial` — works for stated scope; gaps listed
- `wip` — under active development, do not use
- `broken` — known broken, do not use
- `pending` — not yet started

---

## Phase 0 — Scaffold + specs

| Item                                         | Status   | Notes                              |
|----------------------------------------------|----------|------------------------------------|
| Plugin scaffold (configkit.php)              | complete | Header, ABSPATH guard, version constants |
| GitHub repo connected                        | complete | git@github.com:ovanapdesign-svg/sol.git |
| WP install on demo.ovanap.dev/sol1           | complete | Tomas verified                     |
| WooCommerce installed                        | pending  | WooCommerce must be installed and active before Phase 3 Admin UI starts. Currently pending — owner will install via `wp plugin install woocommerce --activate` before Phase 3 entry. |
| TARGET_ARCHITECTURE.md (Batch 1)             | complete | DRAFT v2 — approved 2026-04-29     |
| DATA_MODEL.md (Batch 1)                      | complete | DRAFT v2 — approved 2026-04-29     |
| FIELD_MODEL.md (Batch 1)                     | complete | DRAFT v2 — approved 2026-04-29     |
| MODULE_LIBRARY_MODEL.md (Batch 1)            | complete | DRAFT v2 — approved 2026-04-29     |
| RULE_ENGINE_CONTRACT.md (Batch 2)            | pending  |                                    |
| PRICING_CONTRACT.md (Batch 2)                | pending  |                                    |
| TEMPLATE_VERSIONING.md (Batch 2)             | pending  |                                    |
| MIGRATION_STRATEGY.md (Batch 2)              | pending  |                                    |
| MULTILANGUAGE_MODEL.md (Batch 2)             | pending  |                                    |
| AUDIT_CHECKLIST.md (Batch 2)                 | pending  |                                    |
| UX roadmap (8 docs)                          | pending  |                                    |

**Phase 0 exit:** Owner approved Batch 1 v2 on 2026-04-29. All four Batch 1
specs (TARGET_ARCHITECTURE, DATA_MODEL, FIELD_MODEL, MODULE_LIBRARY_MODEL)
are marked `complete`. Phase 0 closed; awaiting owner approval to enter
Phase 1.

---

## Phase 1 — Schema migrations

| Item                                                | Status   | Notes                                                                  |
|-----------------------------------------------------|----------|------------------------------------------------------------------------|
| Plugin bootstrap + Composer/PSR-4 autoloader        | complete | `configkit.php`, `src/Plugin.php`, `composer.json`. Fallback autoloader if `vendor/` absent. |
| Migration interface + Runner                        | complete | `src/Migration/Migration.php`, `src/Migration/Runner.php`. Uses `microtime(true)`; logs applied/failed to `wp_configkit_migrations`. |
| 16 migration files (0001–0016)                      | complete | Schemas per `DATA_MODEL.md §3`. No seed migration — `wp_configkit_modules` ships empty (owner adds modules in Phase 3). |
| WP-CLI command `wp configkit migrate`               | complete | `src/CLI/Command.php`. Supports `--dry-run` and `--status`.            |
| PHPUnit unit tests                                  | complete | `tests/unit/Migration/RunnerTest.php`. 4 tests / 16 assertions, green. |
| Apply migrations on staging DB                      | pending  | Owner activates plugin to trigger migrations, or runs `wp configkit migrate`. |
| Verify idempotent re-run                            | pending  | Owner: `wp configkit migrate` twice — second run reports no pending.   |
| Verify tables via `SHOW TABLES LIKE 'wp_configkit%'` | pending  | Owner verifies 16 tables present.                                      |

**Phase 1 status:** code complete, tests green; awaiting owner DB
activation to satisfy operational exit criteria. No `INSERT`/`UPDATE`
issued by this codebase yet beyond the migrations table itself.

---

## Phase 2 — Engines

(Nothing started.)

---

## Phase 3 — Admin UI core

(Nothing started.)

---

## Phase 4 — Excel Import + Diagnostics + Product Readiness Board

(Nothing started.)

---

## Phase 5 — Frontend renderer

(Nothing started.)

---

## Phase 6 — Cart + Order integration

(Nothing started.)

---

## Phase 7 — First product end-to-end

(Nothing started.)

---

## Last updated

2026-04-29 — Owner approved Batch 1 v2. All four Batch 1 spec rows marked
`complete`. Phase 0 closed; awaiting owner approval to enter Phase 1.

---

## Patches applied — 2026-04-29

Owner review of Batch 1 v2 raised 5 corrections; this patch round applies
them plus the 5 stale cross-references previously logged here. All four
Batch 1 specs remain `wip` and DRAFT v2; nothing is approved yet.

### Owner-review corrections

1. **PHASES.md Phase 1 contradiction.** Migration logging was forbidden by
   the blanket DML ban. Phase 1 Allowed/Forbidden replaced to permit narrow
   writes (`INSERT` into `wp_configkit_migrations`, `UPDATE` of the
   `configkit_db_version` option, idempotent built-in seed inserts) while
   keeping destructive ops, product writes, and runtime/UI writes
   forbidden.

2. **WooCommerce dependency clarity.** STATUS.md note rewritten to state
   WC must be installed and active before Phase 3 entry. PHASES.md Phase 3
   gains an entry-criteria section requiring "WooCommerce installed and
   active".

3. **Phase reorder.** Excel Import + Diagnostics + Product Readiness Board
   moved from old Phase 6 to new Phase 4. Frontend renderer is now Phase 5;
   Cart/Order is Phase 6; Phase 7 unchanged. CLAUDE.md phase table updated
   to match. Reason: owner mission is fast product creation through Excel +
   reusable lookup tables/libraries + diagnostics + preview. Owner must be
   able to upload products and lookup tables before frontend polish.

4. **Greenfield clarification in TARGET_ARCHITECTURE.md §6.** Paragraph
   added stating that legacy data (Sologtak, scraped products, prior
   ConfigKit) enters via one-time import/migration only; no live dual-read
   legacy engine is supported for sol1.

5. **Stale cross-references resolved.** See list below.

### Stale cross-references resolved

- `CLAUDE.md` line 30: `docs/STATUS.md` → `STATUS.md`.
- `TARGET_ARCHITECTURE.md` §4: `DATA_MODEL.md §3.2` → `DATA_MODEL.md §3.6`
  (template_versions).
- `TARGET_ARCHITECTURE.md` §5: `DATA_MODEL.md §3.7` → `DATA_MODEL.md
  §3.15-§3.16` (import_batches and import_rows).
- `PHASES.md` Phase 4 (was Phase 6) diagnostics: `TARGET_ARCHITECTURE.md
  §11` → `AUDIT_CHECKLIST.md` (Batch 2). §11 of TARGET_ARCHITECTURE.md is
  "Open questions"; diagnostics is not defined in that document.
- `PHASES.md` Phase 7 perf measurement: `TARGET_ARCHITECTURE.md §10` →
  `TARGET_ARCHITECTURE.md §6` (Performance budgets). §10 was "What this
  document does not cover".

**Update 2026-04-29:** Owner approved Batch 1 v2. All four Batch 1 spec
rows are now `complete`. Phase 0 closed; see "Phase 0 exit" line above.
