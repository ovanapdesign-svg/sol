# ConfigKit / Sologtak Configurator — Target Architecture

| Field            | Value                                       |
|------------------|---------------------------------------------|
| Status           | DRAFT v2 (regenerated with corrections)     |
| Document type    | Foundational specification                  |
| Companion docs   | DATA_MODEL.md, FIELD_MODEL.md, MODULE_LIBRARY_MODEL.md |
| Spec language    | English                                     |
| Frontend language| Norwegian (nb_NO)                           |
| Owner            | Ovanap / Sologtak                           |

---

## 1. Mission

ConfigKit is a WooCommerce extension that adds a dynamic, schema-driven product
configurator to existing Woo products. ConfigKit binds to Woo products. It does
not replace them.

The owner workload model:

1. Owner imports product data via Excel.
2. Owner assigns family + template + lookup table to a Woo product.
3. Owner runs diagnostics, previews frontend, then publishes.
4. Owner does NOT manually wire 100 fields per product.

If the owner is wiring fields per product, the system has failed its mission.

---

## 2. Out of scope

ConfigKit does NOT:

- Replace WooCommerce products with a custom product type.
- Build a parallel admin product list outside Woo's product post type.
- Build hardcoded module tabs (one tab per option group).
- Use display labels as technical identifiers.
- Use numeric IDs as canonical references between sites.
- Calculate final pricing on the frontend (frontend pricing is UX-only).
- Become an Elementor-style page builder.
- Maintain its own translation framework. Plugin code strings use WP gettext;
  owner-managed labels are written in Norwegian directly into the database.

---

## 3. Object hierarchy

```
WooCommerce Product
  └── Product Binding                  (1:1 via post meta)
        ├── Family                     (M:1, optional, by family_key)
        ├── Template (current)         (M:1, by template_key)
        ├── Lookup Table               (M:1, optional, by lookup_table_key)
        ├── Defaults                   (per-binding overrides)
        └── Validation status          (computed)

Template (logical entity)
  └── Template Version                 (1:N, immutable when published)
        ├── Steps                      (1:N within version, by step_key)
        │     └── Fields               (1:N within step, by field_key)
        ├── Field Options              (1:N per field, manual_options source)
        └── Rules                      (1:N within version, JSON spec)

Module (capability definition)         registry
  └── Library                          (M:1 to Module, by module_key)
        └── Library Item               (M:1 to Library, by library_key + item_key)

Field
  └── source_config (JSON)
       value_source determines shape:
         library | woo_products | woo_category | manual_options | lookup_table | computed

WooCommerce Cart Item
  └── Cart item meta
        ├── _configkit_template_key
        ├── _configkit_template_version_id
        ├── _configkit_selections        (JSON; uses field_key + item_key)
        ├── _configkit_lookup_match
        └── _configkit_price_breakdown

WooCommerce Order Item
  └── Order item meta (snapshot, immutable)
        ├── _configkit_template_snapshot_id
        ├── _configkit_template_version_id
        ├── _configkit_selections      (JSON, with labels frozen)
        ├── _configkit_price_breakdown
        ├── _configkit_lookup_match
        └── _configkit_production_summary
```

The line item snapshot rule is critical: an order from 2026 must render
correctly in 2028 even if the template has been edited 50 times since.
See TEMPLATE_VERSIONING.md (Batch 2) for the snapshot mechanism.

---

## 4. Engine boundaries

ConfigKit is composed of these engines, each with a single responsibility:

| Engine              | Responsibility                                                |
|---------------------|---------------------------------------------------------------|
| Schema Engine       | CRUD for templates, steps, fields, options, rules, libraries  |
| Rule Engine         | Evaluate JSON rule specs (frontend JS + server PHP)           |
| Pricing Engine      | Compute price from base + options + addons + surcharges       |
| Lookup Engine       | Match (width, height, price_group_key) → base price           |
| Validation Engine   | Validate selections against template + rules                  |
| Render Engine       | Frontend renderer (stepper/accordion + sticky summary)        |
| Cart Engine         | Persist selections to cart item meta + recalc on cart events  |
| Order Engine        | Snapshot template version into order item meta on creation    |
| Diagnostics         | Scan for broken references, missing keys, etc.                |
| Migration Engine    | Apply schema migrations idempotently with logs                |
| Logger              | Structured logs to configkit_log table                        |

Architectural rules:

- **Rule Engine and Pricing Engine are pure functions.** No WP globals, no DB
  queries inside the eval loop. They take data in, return data out. This makes
  them PHPUnit-testable without a WP test bootstrap.
- **Engines do not call each other directly.** They expose interfaces. The
  orchestrator (a thin coordinator class) wires inputs/outputs.
- **No engine writes to multiple tables in one method.** Transaction boundaries
  are explicit at the orchestrator level.
- **No engine reads `$_POST` directly.** The HTTP layer (REST controllers)
  parses input, validates, then calls engines with sanitized arrays.

---

## 5. Canonical references — keys vs IDs

This is the architectural rule that prevents environment drift.

**Keys** are language-neutral, snake_case, immutable identifiers stored on
records. They survive Excel export, cross-environment migration, and label
edits.

**IDs** are auto-increment primary keys. They differ between staging and
production. They are NOT used in canonical references between objects.

Required key columns on every editable entity:

- `family_key`
- `template_key`
- `step_key`
- `field_key`
- `option_key`
- `module_key`
- `library_key`
- `item_key`
- `lookup_table_key`
- `rule_key`
- `price_group_key`

Product binding uses keys, not IDs:

```
_configkit_family_key       (was _configkit_family_id)
_configkit_template_key     (was _configkit_template_id)
_configkit_lookup_table_key (was _configkit_lookup_table_id)
```

Numeric IDs may exist as resolved cache references in cart item meta or
diagnostics views, but the canonical model uses keys. Excel imports and
exports use keys. Rules reference keys. Cart and order selections use keys.

Auto-key suggestion at creation:

```
slugify( strtolower( label ) ) → ASCII fold → snake_case → first 64 chars
```

Owner can override the suggestion. After save, the key never auto-regenerates.

---

## 6. Feature flag model

Flags govern engine behavior and observability. Stored in `wp_options`.

| Flag                                  | Default | Purpose                                            |
|---------------------------------------|---------|----------------------------------------------------|
| `configkit_debug_mode`                | false   | Verbose admin diagnostics                          |
| `configkit_log_level`                 | 'warn'  | debug / info / warn / error                        |
| `configkit_server_side_validation`    | true    | Reject invalid frontend payloads                   |
| `configkit_require_required_fields`   | true    | Block add-to-cart if required fields empty        |

This is a greenfield build. There is no v1 engine to dual-read against. If
data needs to be migrated from a prior site, the migration runs once via the
Migration Engine and produces a clean v2 dataset.

Greenfield clarification. Old Sologtak data, scraped products, or any prior
ConfigKit data must enter the system through one-time import/migration into
clean v2 data structures. No live dual-read legacy engine is required or
supported for sol1. The Migration Engine handles one-time imports as
documented in MIGRATION_STRATEGY.md (Batch 2).

---

## 7. Tech stack constraints

| Component       | Constraint                                                      |
|-----------------|-----------------------------------------------------------------|
| PHP             | 8.1 minimum. `declare(strict_types=1)` in all files.            |
| WordPress       | 6.4+                                                            |
| WooCommerce     | 8.0+                                                            |
| Database        | MySQL 5.7+ or MariaDB 10.4+, utf8mb4, InnoDB                    |
| JS              | Vanilla ES2020 modules. No jQuery in render layer.              |
| Build           | No transpiler required. Ship raw modern JS.                     |
| CSS             | Plain CSS with CSS variables. No SCSS pipeline.                 |
| HTTP API        | WP REST API namespace `configkit/v1/*`                          |
| Logging         | PSR-3-compatible interface, custom DB writer                    |

`declare(strict_types=1)` is enforced via PHPCS rule when CI is set up. Until
CI exists, code review enforces it. Files lacking strict_types declaration
fail review.

---

## 8. Staging discipline

All development happens on a staging environment (initially
`demo.ovanap.dev/sol1`). Production is not deployed to until:

- Audit is complete.
- Specs are approved.
- Staging tests pass.
- Owner explicitly approves a production deployment.

Schema migrations are applied via the Migration Engine, never by hand. Every
migration is idempotent and logged. See MIGRATION_STRATEGY.md (Batch 2) for
the full migration discipline.

---

## 9. Critical discipline: keys vs labels

Technical identifiers are language-neutral, snake_case, immutable. Labels are
display strings. Rules, pricing logic, lookup logic, cart meta, and order meta
reference KEYS only.

Changing a label MUST NOT break:

- Saved rules
- Pricing calculations
- Lookup matching
- Cart meta (existing carts must keep working)
- Order history (existing orders must keep rendering)
- Saved templates

Keys are auto-suggested from labels at creation time but are independently
editable in the admin and never auto-regenerated.

---

## 10. Performance envelope (target / warning / fail)

These are not pass/fail gates during development. They are observability
thresholds.

| Operation                                       | Target  | Warning | Fail    |
|-------------------------------------------------|---------|---------|---------|
| Admin library list with 500 items, page load    | <500ms  | >800ms  | >2000ms |
| Admin template edit page load                   | <800ms  | >1500ms | >3000ms |
| Frontend price recalc (single field change)     | <100ms  | >300ms  | >800ms  |
| Frontend initial render of a 6-step template    | <600ms  | >1200ms | >2500ms |
| Add to cart roundtrip (server validate + write) | <300ms  | >700ms  | >1500ms |
| Diagnostics dashboard full scan                 | <2s     | >5s     | >15s    |
| Lookup cell match (single)                      | <5ms    | >20ms   | >100ms  |
| Excel import dry run for 100 products           | <8s     | >20s    | >60s    |
| Excel import commit for 100 products            | <30s    | >90s    | >300s   |

Operations exceeding **warning** are logged. Operations exceeding **fail** raise
diagnostics warnings in the admin dashboard. Numbers are not enforced as PR
gates during MVP development.

Measurement uses `microtime(true)` via the `ConfigKit_Timer` helper class.
Engines emit start/end pairs to the log when `configkit_log_level >= 'debug'`.

---

## 11. Observability

Every event with operational value writes a row to `wp_configkit_log`:

| Column          | Purpose                                                    |
|-----------------|------------------------------------------------------------|
| id              | PK                                                         |
| created_at      | DATETIME(6), microsecond precision                         |
| level           | VARCHAR(16): debug / info / warn / error / critical        |
| event_type      | VARCHAR(64), e.g. `pricing.frontend_server_mismatch`       |
| user_id         | WP user (admin actions) or 0 (frontend visitor)            |
| product_id      | Woo product ID, nullable                                   |
| order_id        | Order ID, nullable                                         |
| template_key    | Template key, nullable                                     |
| context_json    | LONGTEXT — JSON blob: selections, formula, expected vs actual |
| message         | TEXT                                                       |

Mandatory log events:

- `pricing.frontend_server_mismatch`
- `pricing.lookup_cell_missing`
- `rule.target_missing`
- `migration.applied` / `migration.failed`
- `validation.failed`
- `order.snapshot_written`
- `import.batch_started` / `import.batch_committed` / `import.batch_failed`

Retention: 30 days for `level <= info`, 365 days for `warn` and above.
Cleanup runs nightly via `configkit_log_cleanup` cron action.

---

## 12. Concurrency model — optimistic locking

Two admins editing the same template must not silently overwrite each other.

Every editable entity carries a `version_hash` column:

```
version_hash = sha1( updated_at . id )
```

Every admin form posts back the `version_hash` it loaded with. Save handler:

1. Read current version_hash from DB.
2. Compare to posted version_hash.
3. If mismatch: reject with 409 Conflict + diff display in admin.
4. If match: update record, recompute version_hash, return new hash.

This is best-effort, not strict locking. It catches the common case of two
admins, not race conditions at millisecond level.

---

## 13. Testing requirement

| Suite                  | Path                       | Triggers                                |
|------------------------|----------------------------|------------------------------------------|
| Unit (pure PHP)        | `tests/unit/`              | Every commit (local pre-commit hook)     |
| Integration (WP + Woo) | `tests/integration/`       | Pre-merge to main                        |
| Manual DoD checklist   | `docs/DOD_CHECKLIST.md`    | Per feature, before "complete" status    |

Unit suite scope (mandatory coverage):

- `ConfigKit\Engines\RuleEngine` — every condition operator, every action,
  AND/OR/NOT nesting, cycle detection, missing target handling.
- `ConfigKit\Engines\PricingEngine` — every pricing mode, surcharge layering,
  hidden field exclusion, sale price precedence, rounding policy.
- `ConfigKit\Engines\ValidationEngine` — required-field enforcement, reset of
  invalid selections.

Integration suite scope:

- Cart write/read.
- Order snapshot creation and historical render.
- Migration idempotency (apply twice, second is no-op).
- Excel import dry-run and commit.

---

## 14. Feature status protocol

Every feature has one of these statuses, visible in admin and in
`docs/STATUS.md`:

| Status      | Meaning                                                    |
|-------------|------------------------------------------------------------|
| `complete`  | Passes full DoD checklist for the feature.                 |
| `partial`   | Works for stated scope; explicitly listed gaps remain.     |
| `wip`       | Active development; should not be used in production.      |
| `broken`    | Known broken; do not use; documented workaround if any.    |

In admin, `partial`, `wip`, and `broken` features render with a subtle badge
near the feature title.

`docs/STATUS.md` is the single source of truth, updated on every PR that
changes feature status.

---

## 15. JSON storage convention

The schema stores JSON in `LONGTEXT` columns (named `*_json`), not native
MySQL JSON type. Reasons:

- dbDelta portability across MySQL versions and shared hosts.
- Easier `wp db export`/`import` round-trips.
- Application-layer validation gives clearer error messages.

Validation happens in PHP at write time. Read time assumes valid JSON because
write was validated; corrupt data raises a `data.json_invalid` log event.

---

## 16. Out-of-band work that affects this architecture

Decisions deferred to other documents:

- Migration mechanics → MIGRATION_STRATEGY.md (Batch 2)
- Rule JSON schema → RULE_ENGINE_CONTRACT.md (Batch 2)
- Pricing formulas → PRICING_CONTRACT.md (Batch 2)
- Template snapshot format → TEMPLATE_VERSIONING.md (Batch 2)
- gettext + DB content split → MULTILANGUAGE_MODEL.md (Batch 2)
- Schema details → DATA_MODEL.md (Batch 1)
- Field axes → FIELD_MODEL.md (Batch 1)
- Module/library separation → MODULE_LIBRARY_MODEL.md (Batch 1)

---

## 17. Acceptance criteria

This document is in DRAFT v2 status. Awaiting owner review.

Sign-off requires:

- [ ] Object hierarchy in §3 reflects intended model.
- [ ] Engine boundaries in §4 are accepted.
- [ ] Key-based canonical references in §5 are the law.
- [ ] Feature flag model in §6 is accepted.
- [ ] Tech stack constraints in §7 are committed.
- [ ] Staging discipline in §8 is the law.
- [ ] Key/label discipline in §9 is the law.
- [ ] Performance envelope in §10 is acceptable.
- [ ] Observability scope in §11 is acceptable.
- [ ] Optimistic locking model in §12 is acceptable.
- [ ] Testing requirement in §13 is committed.
- [ ] Feature status protocol in §14 is committed.
- [ ] JSON storage convention in §15 is accepted.

After approval, Batch 2 specs may proceed.
