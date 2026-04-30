# ConfigKit Data Model

| Field            | Value                                       |
|------------------|---------------------------------------------|
| Status           | DRAFT v2 (regenerated with corrections)     |
| Document type    | Data storage specification                  |
| Companion docs   | TARGET_ARCHITECTURE.md                      |
| Migration rules  | MIGRATION_STRATEGY.md (Batch 2)             |
| Spec language    | English                                     |

---

## 1. Storage strategy decision matrix

| Object                          | Storage           | Why                                       |
|---------------------------------|-------------------|-------------------------------------------|
| Module registry                 | Custom table      | Read-heavy, joined to libraries           |
| Libraries                       | Custom table      | Joined to items, modules                  |
| Library items                   | Custom table      | High count, indexed lookups               |
| Families                        | Custom table      | Small, joined to templates                |
| Templates (logical)             | Custom table      | One row per template entity               |
| Template versions               | Custom table      | Immutable JSON snapshot per version       |
| Steps                           | Custom table      | Editable draft state                      |
| Fields                          | Custom table      | Editable draft state                      |
| Field options (manual)          | Custom table      | Editable draft state                      |
| Rules                           | Custom table      | One row per rule, JSON spec column        |
| Lookup tables                   | Custom table      | Joined to family/template                 |
| Lookup cells                    | Custom table      | High cardinality                          |
| Product binding                 | Post meta on Woo product | Simpler, exports with WP backups   |
| Migration log                   | Custom table      | Idempotency tracking                      |
| Structured log                  | Custom table      | Diagnostic events                         |
| Import batches                  | Custom table      | Excel import audit + dry-run + rollback   |
| Import rows                     | Custom table      | Per-row import outcome                    |
| Cart selections                 | Cart item meta    | Native Woo session                        |
| Order selections snapshot       | Order item meta   | Native Woo, durable                       |

We do NOT use Custom Post Types for ConfigKit objects.
We do NOT store ConfigKit business data in `wp_options` (options are for
plugin-level settings only).

---

## 2. Naming convention

| Element           | Pattern                                            |
|-------------------|----------------------------------------------------|
| Custom tables     | `{wpdb_prefix}configkit_*`                         |
| Cart item meta    | `_configkit_*` (underscore = hidden from UI)       |
| Order item meta   | `_configkit_*`                                     |
| Post meta         | `_configkit_*`                                     |
| User meta         | `configkit_*`                                      |
| Options           | `configkit_*`                                      |
| Transients        | `configkit_*`                                      |
| REST routes       | `/wp-json/configkit/v1/*`                          |
| Cron hooks        | `configkit_*`                                      |
| Action hooks      | `configkit/*` (slash separator, namespaced)        |
| Filter hooks      | `configkit/*`                                      |
| Class namespaces  | `ConfigKit\<Module>\<Class>`                       |
| Text domain       | `configkit`                                        |

JSON columns are named `*_json` and stored as `LONGTEXT`. See §11.

---

## 3. Custom table schemas

All tables use `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.

Status/type fields use `VARCHAR(32)` (not native ENUM) for dbDelta
portability. Application code validates allowed values.

### 3.1 wp_configkit_modules

Module registry. One row per module type.

| Column                   | Type                | Notes                            |
|--------------------------|---------------------|----------------------------------|
| id                       | BIGINT UNSIGNED PK auto | |
| module_key               | VARCHAR(64)         | UNIQUE, snake_case               |
| name                     | VARCHAR(255)        | Display label (NO)               |
| description              | TEXT NULL           | Optional                         |
| supports_sku             | TINYINT             | 0/1                              |
| supports_image           | TINYINT             | 0/1                              |
| supports_main_image      | TINYINT             | 0/1                              |
| supports_price           | TINYINT             | 0/1                              |
| supports_sale_price      | TINYINT             | 0/1                              |
| supports_filters         | TINYINT             | 0/1                              |
| supports_compatibility   | TINYINT             | 0/1                              |
| supports_price_group     | TINYINT             | 0/1                              |
| supports_brand           | TINYINT             | 0/1                              |
| supports_collection      | TINYINT             | 0/1                              |
| supports_color_family    | TINYINT             | 0/1                              |
| supports_woo_product_link| TINYINT             | 0/1                              |
| allowed_field_kinds_json | LONGTEXT            | JSON array of field_kind strings |
| attribute_schema_json    | LONGTEXT            | JSON describing attribute keys + types |
| is_builtin               | TINYINT             | 1 = locked, cannot be deleted    |
| is_active                | TINYINT             |                                  |
| sort_order               | INT                 |                                  |
| created_at               | DATETIME            |                                  |
| updated_at               | DATETIME            |                                  |
| version_hash             | VARCHAR(40)         |                                  |

Indexes: UNIQUE(module_key), KEY(is_active, sort_order)

`attribute_schema_json` example for `textiles`:

```json
{
  "fabric_code":     "string",
  "material":        "string",
  "transparency":    "string",
  "blackout":        "boolean",
  "flame_retardant": "boolean",
  "eco_label":       "string"
}
```

The shared library admin UI generates form fields from this schema.

### 3.2 wp_configkit_libraries

A library is a data instance of a module.

| Column          | Type                | Notes                              |
|-----------------|---------------------|------------------------------------|
| id              | BIGINT UNSIGNED PK auto | |
| library_key     | VARCHAR(64)         | UNIQUE, snake_case                 |
| module_key      | VARCHAR(64)         | Logical FK → modules.module_key    |
| name            | VARCHAR(255)        |                                    |
| description     | TEXT NULL           |                                    |
| brand           | VARCHAR(255) NULL   | If module supports_brand           |
| collection      | VARCHAR(255) NULL   | If module supports_collection      |
| is_builtin      | TINYINT             |                                    |
| is_active       | TINYINT             |                                    |
| sort_order      | INT                 |                                    |
| created_at      | DATETIME            |                                    |
| updated_at      | DATETIME            |                                    |
| version_hash    | VARCHAR(40)         |                                    |

Indexes: UNIQUE(library_key), KEY(module_key), KEY(is_active, sort_order)

### 3.3 wp_configkit_library_items

Items inside a library.

| Column          | Type                | Notes                              |
|-----------------|---------------------|------------------------------------|
| id              | BIGINT UNSIGNED PK auto | |
| library_key     | VARCHAR(64)         | Logical FK → libraries.library_key |
| item_key        | VARCHAR(64)         | snake_case, UNIQUE per library_key |
| sku             | VARCHAR(64) NULL    | If module supports_sku             |
| label           | VARCHAR(255)        | Display label (NO)                 |
| short_label     | VARCHAR(64) NULL    |                                    |
| image_url       | VARCHAR(2048) NULL  | Thumbnail                          |
| main_image_url  | VARCHAR(2048) NULL  | Hero/full-size                     |
| description     | TEXT NULL           |                                    |
| price           | DECIMAL(12,2) NULL  | If module supports_price           |
| price_source    | VARCHAR(32) NOT NULL DEFAULT 'configkit' | Phase 4.2 — see PRICING_SOURCE_MODEL.md §2 |
| bundle_fixed_price | DECIMAL(12,2) NULL | Phase 4.2 — bundle items only when price_source = 'fixed_bundle' |
| sale_price      | DECIMAL(12,2) NULL  | If module supports_sale_price      |
| price_group_key | VARCHAR(32) NULL    | If module supports_price_group     |
| color_family    | VARCHAR(64) NULL    | If module supports_color_family    |
| woo_product_id  | BIGINT UNSIGNED NULL| If module supports_woo_product_link|
| item_type       | VARCHAR(32) NOT NULL DEFAULT 'simple' | Phase 4.2 — see BUNDLE_MODEL.md §2 (`simple` / `bundle`) |
| bundle_components_json | LONGTEXT NULL | Phase 4.2 — required when item_type = 'bundle' (BUNDLE_MODEL §3) |
| cart_behavior   | VARCHAR(32) NULL DEFAULT 'price_inside_main' | Phase 4.2 — bundle items only (BUNDLE_MODEL §5) |
| admin_order_display | VARCHAR(32) NULL DEFAULT 'expanded' | Phase 4.2 — bundle items only (BUNDLE_MODEL §7) |
| filters_json    | LONGTEXT NULL       | JSON array of filter tag strings   |
| compatibility_json | LONGTEXT NULL    | JSON array of compatibility tags   |
| attributes_json | LONGTEXT NULL       | JSON, shape from module attribute_schema_json |
| legacy_source   | VARCHAR(64) NULL    | For migration window               |
| legacy_id       | VARCHAR(128) NULL   | Old ID from migration source       |
| is_active       | TINYINT             |                                    |
| sort_order      | INT                 |                                    |
| created_at      | DATETIME            |                                    |
| updated_at      | DATETIME            |                                    |
| version_hash    | VARCHAR(40)         |                                    |

Indexes:
- UNIQUE(library_key, item_key)
- KEY(library_key, is_active, sort_order)
- KEY(sku)
- KEY(woo_product_id)
- KEY(legacy_source, legacy_id)
- KEY(item_type) — Phase 4.2

**Phase 4.2 additions** (`price_source`, `bundle_fixed_price`,
`item_type`, `bundle_components_json`, `cart_behavior`,
`admin_order_display`) land in migration
`0017_extend_library_items_pricing_bundles.php`. All defaults keep
existing rows at `price_source = 'configkit'` / `item_type = 'simple'`
so no behavioural change ships with the schema change. The migration
itself is authored in Phase 4.2b after owner sign-off on
PRICING_SOURCE_MODEL.md and BUNDLE_MODEL.md.

### 3.4 wp_configkit_families

| Column                | Type                | Notes                              |
|-----------------------|---------------------|------------------------------------|
| id                    | BIGINT UNSIGNED PK auto | |
| family_key            | VARCHAR(64)         | UNIQUE                             |
| name                  | VARCHAR(255)        |                                    |
| description           | TEXT NULL           |                                    |
| default_template_key  | VARCHAR(64) NULL    | Logical FK → templates.template_key|
| allowed_modules_json  | LONGTEXT NULL       | JSON array of module_key strings   |
| default_step_order_json | LONGTEXT NULL     | JSON array of step_key strings     |
| is_active             | TINYINT             |                                    |
| created_at            | DATETIME            |                                    |
| updated_at            | DATETIME            |                                    |
| version_hash          | VARCHAR(40)         |                                    |

Indexes: UNIQUE(family_key), KEY(default_template_key), KEY(is_active)

### 3.5 wp_configkit_templates

The logical template entity.

| Column                | Type                | Notes                              |
|-----------------------|---------------------|------------------------------------|
| id                    | BIGINT UNSIGNED PK auto | |
| template_key          | VARCHAR(64)         | UNIQUE                             |
| name                  | VARCHAR(255)        |                                    |
| family_key            | VARCHAR(64) NULL    | Logical FK → families.family_key   |
| description           | TEXT NULL           |                                    |
| status                | VARCHAR(32)         | 'draft' / 'published' / 'archived' |
| published_version_id  | BIGINT UNSIGNED NULL | Pointer to current immutable version |
| legacy_source         | VARCHAR(64) NULL    |                                    |
| legacy_id             | VARCHAR(128) NULL   |                                    |
| created_at            | DATETIME            |                                    |
| updated_at            | DATETIME            |                                    |
| version_hash          | VARCHAR(40)         |                                    |

Indexes: UNIQUE(template_key), KEY(family_key), KEY(status)

There is no `draft_version_id`. Editable draft state lives in the steps,
fields, field_options, and rules tables. Only immutable published or
archived snapshots live in `template_versions`.

### 3.6 wp_configkit_template_versions

Immutable published snapshots.

| Column          | Type             | Notes                              |
|-----------------|------------------|------------------------------------|
| id              | BIGINT UNSIGNED PK auto | |
| template_key    | VARCHAR(64)      | Logical FK → templates.template_key|
| version_number  | INT              | 1, 2, 3 ... per template_key       |
| status          | VARCHAR(32)      | 'published' / 'archived'           |
| snapshot_json   | LONGTEXT         | Full template serialized           |
| published_at    | DATETIME NULL    |                                    |
| published_by    | BIGINT UNSIGNED NULL | WP user ID                     |
| notes           | TEXT NULL        | Owner-written changelog            |
| created_at      | DATETIME         |                                    |

Indexes: UNIQUE(template_key, version_number), KEY(status)

The `snapshot_json` shape is defined in TEMPLATE_VERSIONING.md (Batch 2).

### 3.7 wp_configkit_steps

Editable step state.

| Column          | Type                | Notes                              |
|-----------------|---------------------|------------------------------------|
| id              | BIGINT UNSIGNED PK auto | |
| template_key    | VARCHAR(64)         | Logical FK → templates.template_key|
| step_key        | VARCHAR(64)         | snake_case, UNIQUE per template_key|
| label           | VARCHAR(255)        | Display label (NO)                 |
| description     | TEXT NULL           |                                    |
| sort_order      | INT                 |                                    |
| is_required     | TINYINT             |                                    |
| is_collapsed_by_default | TINYINT     |                                    |
| created_at      | DATETIME            |                                    |
| updated_at      | DATETIME            |                                    |
| version_hash    | VARCHAR(40)         |                                    |

Indexes: UNIQUE(template_key, step_key), KEY(template_key, sort_order)

### 3.8 wp_configkit_fields

Editable field state. See FIELD_MODEL.md for axis definitions.

| Column                  | Type                | Notes                              |
|-------------------------|---------------------|------------------------------------|
| id                      | BIGINT UNSIGNED PK auto | |
| template_key            | VARCHAR(64)         | Logical FK; uniqueness scope       |
| step_key                | VARCHAR(64)         | Logical FK → steps within template |
| field_key               | VARCHAR(64)         | snake_case, UNIQUE per template_key|
| label                   | VARCHAR(255)        | Display label (NO)                 |
| helper_text             | TEXT NULL           |                                    |
| field_kind              | VARCHAR(32)         | input/display/computed/addon/lookup|
| input_type              | VARCHAR(32) NULL    | number/radio/checkbox/dropdown/text/hidden |
| display_type            | VARCHAR(32)         | plain/cards/image_grid/swatch_grid/accordion/summary/info_block/heading |
| value_source            | VARCHAR(32)         | manual_options/library/woo_products/woo_category/lookup_table/computed |
| source_config_json      | LONGTEXT            | JSON; shape depends on value_source|
| behavior                | VARCHAR(32)         | normal_option/product_addon/lookup_dimension/price_modifier/presentation_only |
| pricing_mode            | VARCHAR(32) NULL    | none/fixed/per_unit/per_m2/lookup_dimension |
| pricing_value           | DECIMAL(12,2) NULL  |                                    |
| is_required             | TINYINT             |                                    |
| default_value           | TEXT NULL           | Stable item_key or option_key      |
| show_in_cart            | TINYINT             |                                    |
| show_in_checkout        | TINYINT             |                                    |
| show_in_admin_order     | TINYINT             |                                    |
| show_in_customer_email  | TINYINT             |                                    |
| sort_order              | INT                 |                                    |
| legacy_source           | VARCHAR(64) NULL    |                                    |
| legacy_id               | VARCHAR(128) NULL   |                                    |
| created_at              | DATETIME            |                                    |
| updated_at              | DATETIME            |                                    |
| version_hash            | VARCHAR(40)         |                                    |

Indexes:
- UNIQUE(template_key, field_key)
- KEY(template_key, step_key, sort_order)
- KEY(field_kind)
- KEY(value_source)

`source_config_json` examples:

```json
{
  "type": "library",
  "libraries": ["textiles_dickson", "textiles_sandatex"],
  "filters": ["markise"],
  "sort": "popular_first"
}
```

```json
{
  "type": "woo_category",
  "category_slug": "sensorer",
  "required_tags": ["io"]
}
```

```json
{
  "type": "manual_options"
}
```

```json
{
  "type": "lookup_table",
  "lookup_table_key": "markise_2d_v1",
  "dimension": "width"
}
```

### 3.9 wp_configkit_field_options

Manual options for fields where `value_source = manual_options`.

| Column          | Type                | Notes                              |
|-----------------|---------------------|------------------------------------|
| id              | BIGINT UNSIGNED PK auto | |
| template_key    | VARCHAR(64)         | Logical FK                         |
| field_key       | VARCHAR(64)         | Logical FK → fields                |
| option_key      | VARCHAR(64)         | snake_case, UNIQUE per field       |
| label           | VARCHAR(255)        | Display label (NO)                 |
| price           | DECIMAL(12,2) NULL  |                                    |
| sale_price      | DECIMAL(12,2) NULL  |                                    |
| image_url       | VARCHAR(2048) NULL  |                                    |
| description     | TEXT NULL           |                                    |
| attributes_json | LONGTEXT NULL       |                                    |
| is_active       | TINYINT             |                                    |
| sort_order      | INT                 |                                    |
| legacy_source   | VARCHAR(64) NULL    |                                    |
| legacy_id       | VARCHAR(128) NULL   |                                    |
| created_at      | DATETIME            |                                    |
| updated_at      | DATETIME            |                                    |
| version_hash    | VARCHAR(40)         |                                    |

Indexes:
- UNIQUE(template_key, field_key, option_key)
- KEY(template_key, field_key, sort_order)
- KEY(is_active)

### 3.10 wp_configkit_rules

| Column          | Type                | Notes                              |
|-----------------|---------------------|------------------------------------|
| id              | BIGINT UNSIGNED PK auto | |
| template_key    | VARCHAR(64)         | Logical FK                         |
| rule_key        | VARCHAR(64)         | UNIQUE per template_key, snake_case|
| name            | VARCHAR(255)        | Owner-written description          |
| spec_json       | LONGTEXT            | Rule spec (RULE_ENGINE_CONTRACT.md)|
| priority        | INT                 | Lower = earlier evaluation         |
| is_active       | TINYINT             |                                    |
| sort_order      | INT                 |                                    |
| created_at      | DATETIME            |                                    |
| updated_at      | DATETIME            |                                    |
| version_hash    | VARCHAR(40)         |                                    |

Indexes: UNIQUE(template_key, rule_key), KEY(template_key, priority)

### 3.11 wp_configkit_lookup_tables

| Column                | Type                | Notes                              |
|-----------------------|---------------------|------------------------------------|
| id                    | BIGINT UNSIGNED PK auto | |
| lookup_table_key      | VARCHAR(64)         | UNIQUE                             |
| name                  | VARCHAR(255)        |                                    |
| family_key            | VARCHAR(64) NULL    | Logical FK                         |
| unit                  | VARCHAR(16)         | mm / cm / m                        |
| supports_price_group  | TINYINT             |                                    |
| width_min             | INT NULL            | mm                                 |
| width_max             | INT NULL            | mm                                 |
| height_min            | INT NULL            | mm                                 |
| height_max            | INT NULL            | mm                                 |
| match_mode            | VARCHAR(32)         | exact / round_up / nearest         |
| import_source         | VARCHAR(255) NULL   |                                    |
| last_imported_at      | DATETIME NULL       |                                    |
| is_active             | TINYINT             |                                    |
| legacy_source         | VARCHAR(64) NULL    |                                    |
| legacy_id             | VARCHAR(128) NULL   |                                    |
| created_at            | DATETIME            |                                    |
| updated_at            | DATETIME            |                                    |
| version_hash          | VARCHAR(40)         |                                    |

Indexes: UNIQUE(lookup_table_key), KEY(family_key)

### 3.12 wp_configkit_lookup_cells

| Column            | Type                | Notes                              |
|-------------------|---------------------|------------------------------------|
| id                | BIGINT UNSIGNED PK auto | |
| lookup_table_key  | VARCHAR(64)         | Logical FK                         |
| width             | INT                 | mm                                 |
| height            | INT                 | mm                                 |
| price_group_key   | VARCHAR(32) NOT NULL DEFAULT '' | Empty string when 2D     |
| price             | DECIMAL(12,2)       |                                    |

Indexes:
- UNIQUE(lookup_table_key, width, height, price_group_key)
- KEY(lookup_table_key, width, height) — fast 2D match
- KEY(lookup_table_key, price_group_key)

`price_group_key` is `NOT NULL DEFAULT ''` because MySQL UNIQUE indexes
allow multiple NULL values; `''` enforces true uniqueness for 2D tables.

### 3.13 wp_configkit_log

Structured log.

| Column        | Type                | Notes                              |
|---------------|---------------------|------------------------------------|
| id            | BIGINT UNSIGNED PK auto | |
| created_at    | DATETIME(6)         | Microsecond precision              |
| level         | VARCHAR(16)         | debug/info/warn/error/critical     |
| event_type    | VARCHAR(64)         |                                    |
| user_id       | BIGINT UNSIGNED     | 0 if no user                       |
| product_id    | BIGINT UNSIGNED NULL|                                    |
| order_id      | BIGINT UNSIGNED NULL|                                    |
| template_key  | VARCHAR(64) NULL    |                                    |
| context_json  | LONGTEXT NULL       |                                    |
| message       | TEXT                |                                    |

Indexes: KEY(created_at), KEY(event_type, created_at), KEY(level, created_at)

### 3.14 wp_configkit_migrations

| Column        | Type                | Notes                              |
|---------------|---------------------|------------------------------------|
| id            | BIGINT UNSIGNED PK auto | |
| migration_key | VARCHAR(128)        | UNIQUE — slug                      |
| applied_at    | DATETIME            |                                    |
| applied_by    | BIGINT UNSIGNED NULL|                                    |
| duration_ms   | INT                 |                                    |
| status        | VARCHAR(32)         | applied / failed / rolled_back     |
| notes         | TEXT NULL           |                                    |

Indexes: UNIQUE(migration_key)

### 3.15 wp_configkit_import_batches

Excel import audit. One row per import attempt.

| Column            | Type                | Notes                              |
|-------------------|---------------------|------------------------------------|
| id                | BIGINT UNSIGNED PK auto | |
| batch_key         | VARCHAR(64)         | UNIQUE, generated UUID-style       |
| import_type       | VARCHAR(32)         | products/lookup/textiles/libraries/compatibility |
| filename          | VARCHAR(255)        | Original Excel filename            |
| status            | VARCHAR(32)         | pending/dry_run/committed/failed/rolled_back |
| dry_run           | TINYINT             | 1 if last action was dry-run only  |
| created_by        | BIGINT UNSIGNED     | WP user                            |
| created_at        | DATETIME            |                                    |
| committed_at      | DATETIME NULL       |                                    |
| summary_json      | LONGTEXT NULL       | Counts: created/updated/warnings/errors |
| rollback_status   | VARCHAR(32) NULL    | available/applied/not_applicable   |
| notes             | TEXT NULL           |                                    |

Indexes: UNIQUE(batch_key), KEY(import_type, status), KEY(created_at)

### 3.16 wp_configkit_import_rows

Per-row outcome of an import batch.

| Column                | Type                | Notes                              |
|-----------------------|---------------------|------------------------------------|
| id                    | BIGINT UNSIGNED PK auto | |
| batch_key             | VARCHAR(64)         | Logical FK                         |
| row_number            | INT                 | Excel row number, 1-based          |
| action                | VARCHAR(32)         | create/update/skip/error           |
| object_type           | VARCHAR(32)         | product/lookup_cell/library_item/textile/etc |
| object_key            | VARCHAR(128) NULL   | The created/updated key            |
| severity              | VARCHAR(32)         | green/yellow/red                   |
| message               | TEXT NULL           | Human-readable error/warning       |
| raw_data_json         | LONGTEXT NULL       | The Excel row as parsed            |
| normalized_data_json  | LONGTEXT NULL       | After normalization, before write  |
| created_at            | DATETIME            |                                    |

Indexes:
- KEY(batch_key, row_number)
- KEY(batch_key, severity)
- KEY(object_type, object_key)

---

## 4. Post meta keys (on Woo product post)

Canonical references use keys, not IDs.

| Meta key                          | Type    | Default | Purpose                         |
|-----------------------------------|---------|---------|---------------------------------|
| `_configkit_enabled`              | bool    | false   | Master enable flag              |
| `_configkit_family_key`           | string  | null    | family_key                      |
| `_configkit_template_key`         | string  | null    | template_key                    |
| `_configkit_lookup_table_key`     | string  | null    | lookup_table_key                |
| `_configkit_default_values_json`  | LONGTEXT| {}      | { field_key: option_or_item_key }|
| `_configkit_overrides_json`       | LONGTEXT| {}      | Field-level overrides           |
| `_configkit_validation_status`    | string  | 'unknown' | ready/missing_template/etc.   |
| `_configkit_last_validated_at`    | datetime| null    |                                 |
| `_configkit_legacy_source`        | string  | null    | Migration window only           |
| `_configkit_legacy_id`            | string  | null    | Migration window only           |

---

## 5. Cart item meta keys

| Meta key                              | Type   | Purpose                              |
|---------------------------------------|--------|--------------------------------------|
| `_configkit_template_key`             | string |                                      |
| `_configkit_template_version_id`      | int    | Pinned version at add-to-cart        |
| `_configkit_selections_json`          | LONGTEXT | { field_key: item_or_option_key } |
| `_configkit_lookup_match_json`        | LONGTEXT | { width, height, price_group_key, price } |
| `_configkit_price_breakdown_json`     | LONGTEXT |                                    |
| `_configkit_addon_product_ids_json`   | LONGTEXT | Linked Woo product IDs (resolved)  |

---

## 6. Order item meta keys (snapshot, immutable)

| Meta key                              | Type     | Purpose                            |
|---------------------------------------|----------|------------------------------------|
| `_configkit_template_key`             | string   |                                    |
| `_configkit_template_snapshot_id`     | int      | FK → template_versions             |
| `_configkit_selections_json`          | LONGTEXT | Selections + frozen labels         |
| `_configkit_price_breakdown_json`     | LONGTEXT |                                    |
| `_configkit_lookup_match_json`        | LONGTEXT |                                    |
| `_configkit_production_summary_json`  | LONGTEXT |                                    |
| `_configkit_rule_results_json`        | LONGTEXT | Which rules fired and their effects|

---

## 7. Options keys

| Option key                            | Type    | Default | Purpose                         |
|---------------------------------------|---------|---------|---------------------------------|
| `configkit_db_version`                | string  | '0'     | Schema version                  |
| `configkit_debug_mode`                | bool    | false   |                                 |
| `configkit_log_level`                 | string  | 'warn'  |                                 |
| `configkit_currency`                  | string  | 'NOK'   |                                 |
| `configkit_measurement_unit`          | string  | 'mm'    |                                 |
| `configkit_price_display`             | string  | 'incl_vat' |                              |
| `configkit_lookup_match_default`      | string  | 'round_up' |                              |
| `configkit_frontend_mode`             | string  | 'stepper'  |                              |
| `configkit_mobile_sticky_bar`         | bool    | true    |                                 |
| `configkit_require_required_fields`   | bool    | true    |                                 |
| `configkit_server_side_validation`    | bool    | true    |                                 |

---

## 8. Optimistic locking — version_hash

`version_hash = sha1( updated_at . id )`, recomputed on every UPDATE.

Save path:

1. Client GETs record, receives `version_hash`.
2. Client PUTs changes, echoing `version_hash`.
3. Server compares posted hash to current; rejects with 409 on mismatch.
4. Server applies update, recomputes hash, returns new hash.

---

## 9. Logical foreign keys, no MySQL FK constraints

All FKs are logical, declared in this document. No `FOREIGN KEY` DDL.

Reasons: cross-host portability, easier `wp db import`, no cascade-lock pain
during migration. Referential integrity enforced in repository classes.
Orphan detection runs in nightly diagnostics scan.

---

## 10. Indexes — discipline rules

- Every column used in WHERE has an index.
- Every column used as JOIN key has an index.
- Composite indexes match query patterns.
- No more than 5 indexes per table without justification.
- `idx_*` prefix for non-unique indexes.

---

## 11. JSON storage convention

JSON columns are `LONGTEXT`, not native `JSON` type. Reasons:

- dbDelta portability across MySQL versions.
- Easier `wp db export`/`import` round-trips.
- Application-layer validation gives clearer errors.

Validation:

- On write: PHP validates JSON against a schema (per column).
- On read: assume valid JSON; corruption raises `data.json_invalid` log event.
- Empty default: `'[]'` for array columns, `'{}'` for object columns.

---

## 12. ENUM avoidance

Status and type fields use `VARCHAR(32)` with application-layer validation,
not native `ENUM` type. Reasons:

- dbDelta does not handle ENUM additions cleanly.
- Schema migrations are simpler.
- Allowed values can change without ALTER TABLE.

Allowed values are documented in the relevant column row.

---

## 13. Storage size estimates

For an expected Sologtak load:

| Table                         | Rows estimate | Notes                              |
|-------------------------------|---------------|------------------------------------|
| modules                       | ~15           |                                    |
| libraries                     | ~30           |                                    |
| library_items                 | ~3,000        |                                    |
| families                      | ~10           |                                    |
| templates                     | ~20           |                                    |
| template_versions             | ~200          |                                    |
| steps                         | ~120          |                                    |
| fields                        | ~600          |                                    |
| field_options                 | ~1,500        |                                    |
| rules                         | ~200          |                                    |
| lookup_tables                 | ~30           |                                    |
| lookup_cells                  | ~30,000       |                                    |
| log (rolling 30 days)         | ~500,000      |                                    |
| import_batches                | ~500          | accumulating over years            |
| import_rows                   | ~250,000      | accumulating over years            |

Total disk (incl. indexes): ~600 MB.

---

## 14. Backup and export

- Custom tables included in `wp db export` automatically.
- `wp configkit export-data` produces a portable JSON of all business data
  (templates, libraries, lookup tables, families) using **keys** for cross-
  environment transfer.
- `wp configkit import-data` accepts the JSON and applies idempotently.
- Cart and order meta are part of standard Woo data.

---

## 15. Acceptance criteria

This document is in DRAFT v2 status. Awaiting owner review.

Sign-off requires:

- [ ] Table list (§1) is accepted.
- [ ] Naming convention (§2) is the law.
- [ ] Schema definitions (§3) are accepted as starting point.
- [ ] Post meta keys using *_key (§4) is the law.
- [ ] Cart and order meta shape (§5, §6) accepted.
- [ ] Optimistic locking via version_hash (§8) accepted.
- [ ] No MySQL FK policy (§9) accepted.
- [ ] JSON via LONGTEXT (§11) accepted.
- [ ] No native ENUM (§12) accepted.

Schema details may be refined during MIGRATION_STRATEGY.md (Batch 2) and
during audit.
