# ConfigKit — Target Architecture

**Status:** DRAFT v2
**Scope:** Batch 1 of the spec set. Read alongside `DATA_MODEL.md`, `FIELD_MODEL.md`, `MODULE_LIBRARY_MODEL.md`.
**Audience:** Plugin engineers and reviewers.

---

## 1. Purpose

ConfigKit is a schema-driven product configurator that runs on top of WooCommerce. It lets non-developer authors design configurable products by composing **templates** out of **fields**, **modules**, and **price groups** — instead of hardcoding configurator UI per product.

The plugin must:

- model configurator content as portable data (not PHP classes per product),
- render that data on the front end with predictable performance,
- import/export that data in deterministic batches,
- coexist with WooCommerce without forking it.

This document covers the high-level architecture only. Concrete table shapes live in `DATA_MODEL.md`. Field-level rules live in `FIELD_MODEL.md`. Module rules live in `MODULE_LIBRARY_MODEL.md`.

---

## 2. Architectural principles

These principles are normative for v2. Deviations require an explicit note in the relevant spec.

### 2.1 Canonical keys, not surrogate IDs

Every authoring entity that participates in import/export, references, or human reasoning carries a **canonical string key** that is the stable identity:

- `template_key`, `field_key`, `module_key`, `price_group_key`, `option_key`, `import_batch_key`.
- Numeric `id` columns still exist for join performance, but they are **never** used as cross-system references.
- Keys are lowercase ASCII, `[a-z0-9_]`, max 64 chars. They are immutable once published.

**Why this matters:** v1 used numeric IDs in JSON exports and inline references. Re-imports on a fresh DB produced broken pointers. v2 disallows this entirely.

### 2.2 Authoring content is portable data

Templates, fields, modules, price groups, and module attribute schemas are stored as rows + JSON-in-text payloads. There is **no per-product PHP file** and no generated PHP for content. New content types ship as data, not code.

### 2.3 Two distinct planes

| Plane | Responsibility | Latency profile |
|-------|----------------|-----------------|
| **Authoring** | Admin UI, validation, import/export, versioning | Tolerates 100s of ms |
| **Runtime** | Front-end render, price calc, add-to-cart | Must hit performance budgets in §6 |

These planes share the data model but **not** the same caches or the same hot paths. Runtime never reads authoring-only tables.

### 2.4 Versioned templates, snapshot-rendered

Templates are versioned (see `DATA_MODEL.md §3.2`). The runtime always renders against a **published version snapshot**, not against live editable rows. This isolates customers from in-progress edits and makes regressions diff-able.

### 2.5 Deterministic imports

Every import is a **batch** with a key, a state machine, and per-row results (see `DATA_MODEL.md §3.7`). The same input file run twice on the same DB produces the same outcome. No "fix it up by re-running."

---

## 3. Layer map

```
┌─────────────────────────────────────────────────────────────┐
│ Presentation (front-end render, admin React UI)             │
├─────────────────────────────────────────────────────────────┤
│ Application services                                        │
│  - TemplateRenderer        - PriceCalculator                │
│  - ModuleEvaluator         - ImportRunner                   │
│  - FieldValidator          - ExportBuilder                  │
├─────────────────────────────────────────────────────────────┤
│ Domain model (pure PHP, no WP/WC calls)                     │
│  - Template / TemplateVersion                               │
│  - Field / FieldOption                                      │
│  - Module / ModuleInstance                                  │
│  - PriceGroup                                               │
│  - ImportBatch / ImportBatchItem                            │
├─────────────────────────────────────────────────────────────┤
│ Persistence (wpdb gateways, one per aggregate)              │
├─────────────────────────────────────────────────────────────┤
│ WordPress / WooCommerce host                                │
└─────────────────────────────────────────────────────────────┘
```

The domain layer must not import WordPress functions directly. Gateways and services are the only layers that touch `$wpdb`, WC product objects, or WP options.

---

## 4. Data flow — runtime render

1. Customer hits a configurable product page.
2. WC product → `template_key` → resolve **published** `template_version_id`.
3. `TemplateRenderer` loads the version snapshot (one query + a small set of join queries; see `DATA_MODEL.md §3.6`).
4. `FieldValidator` and `ModuleEvaluator` run only against fields/modules referenced by that version.
5. `PriceCalculator` resolves each line item against its `price_group_key`.
6. Output is cached per (template_version_id, locale, customer_role) for the runtime cache TTL.

Runtime must never trigger an import, a schema migration, or a write to authoring tables.

---

## 5. Data flow — authoring & import

1. Author uploads a CSV/JSON bundle through the admin UI.
2. `ImportRunner` creates an `import_batch` row with `state='received'`.
3. Each row in the bundle becomes an `import_batch_item`. Validation runs per item; errors are stored on the item, not raised as PHP exceptions.
4. On `state='applied'`, the runner writes to the relevant authoring tables in a transaction per batch.
5. Templates that change produce a **new** `template_version` row. The previous published version stays live until the new one is explicitly published.
6. On any failure, the batch is left in `state='failed'` with all item errors intact for inspection.

See `DATA_MODEL.md §3.15-§3.16` for the import batch tables.

---

## 6. Performance budgets

Budgets use three thresholds: **target** (steady-state goal), **warning** (logged + flagged in admin), **fail** (alerted + treated as a bug). All measurements use `microtime(true)` deltas — there is no `wp_microtime()` in WordPress core, and we explicitly avoid inventing one.

| Operation | Target | Warning | Fail |
|---|---|---|---|
| Single field render | 5 ms | 15 ms | 50 ms |
| Full template render (≤30 fields) | 50 ms | 150 ms | 500 ms |
| Module evaluation (single module) | 10 ms | 30 ms | 100 ms |
| Price calculation (full configuration) | 20 ms | 60 ms | 200 ms |
| Admin save (single template version) | 100 ms | 300 ms | 1000 ms |
| Import batch apply (per 100 items) | 500 ms | 1500 ms | 5000 ms |

Measurements are taken at service boundaries:

```php
$t0 = microtime( true );
$result = $renderer->render( $version_id );
$elapsed_ms = ( microtime( true ) - $t0 ) * 1000.0;
```

Logging beyond the warning threshold writes to a structured perf log (table TBD in Batch 2). Exceeding `fail` should fail loudly in CI test fixtures.

**Greenfield clarification.** Old Sologtak data, scraped products, or any prior ConfigKit data must enter the system through one-time import/migration into clean v2 data structures. No live dual-read legacy engine is required or supported for sol1. The Migration Engine handles one-time imports as documented in `MIGRATION_STRATEGY.md` (Batch 2).

---

## 7. Storage choices — portability over cleverness

The data model deliberately favours portability across MySQL/MariaDB versions and across hosting environments:

- **No native JSON columns.** JSON payloads are stored as `LONGTEXT` with a documented schema and validated in PHP. This keeps us compatible with MySQL 5.7-era hosts and avoids vendor-specific JSON path predicates.
- **No ENUM columns.** Status fields and similar small-cardinality strings use `VARCHAR(32)` with allowed-value lists enforced in code. ENUM migrations are painful and ENUM ordering is a footgun.
- **JSON arrays — never CSV.** Multi-value attributes are stored as JSON arrays in `LONGTEXT`. v1 used CSV strings (`"red,blue,green"`) which broke on values containing commas and made escaping ad-hoc.
- **Foreign-key-shaped indexes**, even where InnoDB FKs are not declared, on every `*_id` and `*_key` column that is queried.

`DATA_MODEL.md §2` lists the exact column types per table.

---

## 8. Caching strategy (sketch)

- **Authoring side:** no caching; correctness over speed.
- **Runtime side:** template version snapshots are the cache unit. Cache key = `cfgkit:tv:{template_version_id}:{locale}:{role}`. Invalidation happens on publish of a new version, not on edits.
- **Module evaluation:** results are not cached across requests by default; module authors can opt in via `attribute_schema_json.cache` (see `MODULE_LIBRARY_MODEL.md §4`).

Detailed cache layout is out of scope for Batch 1.

---

## 9. Legacy references (v1 → v2)

This section documents naming and shape changes from v1 so older docs/code can be read without confusion.

| v1 term / column | v2 term / column | Notes |
|---|---|---|
| `source_ref` | `source_config_json` | v1 stored a single string pointer; v2 stores a structured JSON config that captures source type + parameters. See `FIELD_MODEL.md §4`. |
| `field_options` (CSV in field row) | `configkit_field_options` table | Manual options now live in their own table with stable `option_key`s. |
| Inline template JSON blob | `configkit_template_versions` rows | Versioning is first-class; there is no "current template" without a version. |
| Numeric IDs in exports | Canonical keys in exports | Numeric IDs are never serialised. |
| ENUM `status` columns | `VARCHAR(32)` + allowed list | See §7. |
| Native JSON columns | `LONGTEXT` + PHP validation | See §7. |
| `wp_microtime()` (custom helper) | `microtime(true)` directly | The helper added no value and obscured intent. |

Legacy column names must not be reintroduced. If v1 data needs to be read during migration, it is read by an explicit, named adapter — never by the runtime.

---

## 10. What this document does **not** cover

- Concrete SQL DDL — see `DATA_MODEL.md`.
- Field type catalogue and validation rules — see `FIELD_MODEL.md`.
- Module attribute schema and lifecycle — see `MODULE_LIBRARY_MODEL.md`.
- Admin UI shape, REST endpoints, capability model — Batch 2.
- Cart / order persistence of resolved configurations — Batch 2.
- Telemetry / perf log table — Batch 2.

---

## 11. Open questions (tracked, not answered here)

1. Should `template_version` publishing be append-only forever, or can old versions be archived after N releases?
2. Module evaluation caching default — opt-in vs opt-out?
3. Locale handling for option labels — separate table vs JSON-per-locale?

These are noted for Batch 2 review; v2 of Batch 1 does not commit to an answer.
