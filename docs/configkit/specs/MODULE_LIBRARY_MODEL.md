# ConfigKit Module and Library Model

| Field            | Value                                       |
|------------------|---------------------------------------------|
| Status           | DRAFT v2 (regenerated with corrections)     |
| Document type    | Module/library specification                |
| Companion docs   | TARGET_ARCHITECTURE.md, DATA_MODEL.md, FIELD_MODEL.md |
| Spec language    | English                                     |

---

## 1. The core distinction

Two concepts to keep separate:

- **Module** = a *capability definition*. It declares "here is a kind of data
  source, and here are the properties items of this kind have."
- **Library** = an *instance of a module*. A concrete dataset with items.

A module has zero, one, or many libraries. A library belongs to exactly one
module.

A template field with `value_source = library` chooses libraries via
`source_config_json`. The module's capabilities determine what attributes
items can have.

This is the layer that prevents the next refactor: adding a new option group
becomes a registry entry plus a library, not a new admin tab plus new code.

---

## 2. What a module declares

| Property                    | Meaning                                                |
|-----------------------------|--------------------------------------------------------|
| `module_key`                | Stable snake_case identifier                           |
| `name`                      | Display label (NO)                                     |
| `description`               | Short prose                                            |
| `supports_sku`              | Items may carry a SKU                                  |
| `supports_image`            | Items may carry a thumbnail image                      |
| `supports_main_image`       | Items may carry a hero/full-size image                 |
| `supports_price`            | Items may carry their own price                        |
| `supports_sale_price`       | Items may carry a sale price                           |
| `supports_filters`          | Items may carry filter tags                            |
| `supports_compatibility`    | Items may carry compatibility tags                     |
| `supports_price_group`      | Items belong to price groups                           |
| `supports_brand`            | Items belong to a brand                                |
| `supports_collection`       | Items belong to a collection                           |
| `supports_color_family`     | Items have a color family classification               |
| `supports_woo_product_link` | Items may link to a Woo product                        |
| `allowed_field_kinds_json`  | JSON array: which `field_kind` values can use this    |
| `attribute_schema_json`     | JSON: schema of module-specific item attributes        |

The capability flags drive:

- Which columns are populated for items.
- Which form fields are shown in the admin item editor.
- Which filters appear on the frontend item picker.
- Which validation rules apply.

---

## 3. `attribute_schema_json` — module-specific item attributes

Each module declares the shape of its item attributes via a JSON schema. The
shared library admin UI generates form fields from this schema. The frontend
picker can filter by these attributes. The rule engine can read them.

Example for `textiles`:

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

Example for `motors`:

```json
{
  "voltage":          "integer",
  "power_w":          "integer",
  "torque_nm":        "integer",
  "control_protocol": "string"
}
```

Supported types in v1: `string`, `integer`, `boolean`. (Decimal, date, enum
deferred until needed.)

The library item's `attributes_json` column stores values matching the
schema. Validation runs at write time.

---

## 4. Built-in modules

These ship with ConfigKit and cannot be deleted (`is_builtin = 1`). They can
be deactivated (`is_active = 0`).

| module_key      | Purpose                                       | Key capabilities           |
|-----------------|-----------------------------------------------|----------------------------|
| `colors`        | Profile colors, RAL colors                    | sku, image, color_family   |
| `textiles`      | Fabric collections                            | brand, collection, price_group, color_family, filters |
| `motors`        | Motor types                                   | sku, price, woo_product_link, compatibility |
| `controls`      | Control types (switches, remotes)             | sku, price, woo_product_link, compatibility |
| `accessories`   | Generic accessories                           | sku, price, woo_product_link |
| `sensors`       | Wind/sun/rain sensors                         | sku, price, woo_product_link, compatibility |
| `mounts`        | Mounting brackets                             | sku, image, compatibility  |
| `brackets`      | Wall/ceiling brackets                         | sku, image, compatibility  |
| `sides`         | Side options (left/right)                     | (minimal — manual_options) |
| `kappe`         | Kappe variants (markise valance)              | sku, image, price          |
| `kabel`         | Cable lengths                                 | sku, price                 |
| `sveiv`         | Crank handle types                            | sku, image, price          |
| `woo_groups`    | Pre-curated groups of Woo products            | woo_product_link           |

This is seed data, not architecture. New modules can be added without code
changes by registering rows in `wp_configkit_modules`.

---

## 5. What a library declares

| Property         | Meaning                                                |
|------------------|--------------------------------------------------------|
| `library_key`    | Stable snake_case identifier, unique across libraries  |
| `module_key`     | Logical FK to modules                                  |
| `name`           | Display label                                          |
| `description`    | Optional                                               |
| `brand`          | If module supports_brand                               |
| `collection`     | If module supports_collection                          |
| `is_builtin`     | Locked — cannot be deleted                             |
| `is_active`      | Soft disable                                           |
| `sort_order`     | Admin display order                                    |

Examples:

| library_key                | module_key   | name                              |
|----------------------------|--------------|-----------------------------------|
| `profile_colors_ral`       | colors       | RAL Profilfarger                  |
| `profile_colors_sologtak`  | colors       | Sologtak Standard Profilfarger    |
| `textiles_dickson`         | textiles     | Dickson Orchestra Collection      |
| `textiles_sandatex`        | textiles     | Sandatex                          |
| `textiles_sunesta`         | textiles     | Sunesta Suntex                    |
| `motors_somfy`             | motors       | Somfy Motors                      |
| `controls_somfy_io`        | controls     | Somfy IO Controls                 |
| `controls_somfy_rts`       | controls     | Somfy RTS Controls                |
| `sensors_somfy`            | sensors      | Somfy Sensors                     |

---

## 6. Library item — schema by capability

Library items live in `wp_configkit_library_items`. Core columns are
universal; capability-gated columns are populated when the module's flag is
on. The `attributes_json` column carries module-specific extras matching
`attribute_schema_json`.

Universal columns (always present):

```
id, library_key, item_key, label, sort_order, is_active,
created_at, updated_at, version_hash
```

Capability-gated columns (populated when module supports them):

| Column            | Module capability         |
|-------------------|---------------------------|
| `sku`             | `supports_sku`            |
| `image_url`       | `supports_image`          |
| `main_image_url`  | `supports_main_image`     |
| `price`           | `supports_price`          |
| `sale_price`      | `supports_sale_price`     |
| `price_group_key` | `supports_price_group`    |
| `color_family`    | `supports_color_family`   |
| `woo_product_id`  | `supports_woo_product_link` |
| `filters_json`    | `supports_filters`        |
| `compatibility_json` | `supports_compatibility` |
| `attributes_json` | (always available, shape via attribute_schema_json) |

JSON columns store arrays/objects:

```json
filters_json:       ["blackout", "eco", "popular"]
compatibility_json: ["markise", "screen"]
attributes_json:    {"fabric_code": "U171", "blackout": false, ...}
```

---

## 7. Field source binding via `source_config_json`

A field with `value_source = library` references libraries through
`source_config_json` (see FIELD_MODEL.md §7.1):

```json
{
  "type": "library",
  "libraries": ["textiles_dickson", "textiles_sandatex"],
  "filters": ["markise"],
  "sort": "popular_first"
}
```

- Single library: array of one `library_key`.
- Multi-library merge: array of multiple `library_key` values.
- All active libraries of a module: future extension; can be expressed as
  a server-side query that resolves library list at render time. Not in
  v1 spec; templates must enumerate libraries explicitly.

---

## 8. Library item key uniqueness

`item_key` is unique WITHIN a library, NOT globally.

Two textile libraries can both have `item_key = u171`:

- `textiles_dickson` / `u171` → Dickson U171
- `textiles_sandatex` / `u171` → Sandatex U171

When a field references multiple libraries, the resolved key is the
compound `library_key:item_key`. This compound form is what gets stored in
cart/order meta. Single-library fields can store just `item_key` since the
library is implied by the field's `source_config_json`.

---

## 9. Rules can target items by stable identity

Rules reference items by:

| Reference style                  | Meaning                                |
|----------------------------------|----------------------------------------|
| `item_key`                       | Item in single-library context         |
| `library_key:item_key`           | Specific item in specific library      |
| `module:module_key`              | Any item from any library of a module  |
| `tag:tag_name`                   | Any item carrying a filter/compat tag  |

Example rule using `source_config_json` modification — if `control_system =
io`, restrict sensor addons to those tagged `io`:

```json
{
  "when": {
    "field": "control_system",
    "op": "equals",
    "value": "io"
  },
  "then": [
    {
      "action": "filter_source",
      "field": "sensor_addon",
      "filter": { "tag": "io" }
    }
  ]
}
```

The rule references stable keys only. See RULE_ENGINE_CONTRACT.md (Batch 2)
for the full rule schema.

---

## 10. Excel import — library items

Libraries are imported via Excel. Per row:

| Column           | Notes                                              |
|------------------|----------------------------------------------------|
| `library_key`    | Required. Must exist or row creates new library.   |
| `item_key`       | Required. snake_case. Unique within library.       |
| `sku`            | Required if module supports_sku.                   |
| `label`          | Required. Display label (NO).                      |
| `price`          | Required if module supports_price.                 |
| `sale_price`     | Optional.                                          |
| `image_url`      | Optional. Imported into Media Library on commit.   |
| `main_image_url` | Optional.                                          |
| `description`    | Optional.                                          |
| `is_active`      | true/false. Defaults true.                         |
| `sort_order`     | Optional integer.                                  |
| `filters`        | Optional CSV input → stored as JSON array.         |
| `compatibility`  | Optional CSV input → stored as JSON array.         |
| `<attribute>:*`  | Module-specific extras → `attributes_json`.        |

For `textiles`, additional columns:

```
brand, collection, fabric_code, price_group_key, color_family,
material, transparency, blackout, flame_retardant, eco_label
```

`brand` and `collection` flow to the `libraries` row (creating one if
absent). Per-item attributes flow to `attributes_json` matching the module's
`attribute_schema_json`.

CSV input columns (`filters`, `compatibility`) are normalized to JSON arrays
during import. Storage is JSON, not CSV.

---

## 11. Library admin UX

ONE shared CRUD UI for libraries, parameterized by module capabilities.

- Libraries list, filterable by module.
- Library detail: items list with module-aware columns.
- Bulk actions: activate, deactivate, sort, delete.
- Item editor: form generated from `attribute_schema_json`.

There is NOT a separate hardcoded admin page for "Colors", "Textiles",
"Motors". One "Libraries" page handles all modules.

---

## 12. Adding a new module — process

1. Settings → Modules → "New module".
2. Enter `module_key`, name, capabilities, `attribute_schema_json`.
3. Save → row inserted into `wp_configkit_modules`.
4. Libraries → "New library" → select the new module → name it.
5. Add items via admin or Excel import.
6. New module appears as a `value_source = library` option in field editors.

Zero code changes. Zero new admin pages.

The exception: highly bespoke item attributes that need custom validation
may need a small validator class registered via filter:

```php
add_filter( 'configkit/library_item/attribute_validators',
    fn( $v ) => $v + [ 'motors' => Motor_Attribute_Validator::class ]
);
```

This is an advanced extension point.

---

## 13. Compatibility tags vs filters — distinction

Two similar-looking systems serve different purposes:

| System              | Purpose                                                   |
|---------------------|-----------------------------------------------------------|
| `filters_json`      | Customer-facing search/filter on the frontend item picker |
| `compatibility_json`| Rule-engine input for show/hide/disable logic             |

A textile might have:

```json
filters_json:       ["blackout", "eco", "popular"]
compatibility_json: ["markise", "screen"]
```

Filters appear as facets in the customer's fabric picker. Compatibility tags
let a rule say "if family = markise, only show fabrics with compatibility
tag `markise`".

---

## 14. Library deletion and references

A library cannot be hard-deleted if any field references it. Diagnostics
detects references and blocks hard delete. Soft delete (`is_active = 0`)
is allowed and keeps existing references working.

Same rule for items: hard-deleting an item present in any cart, order, or
saved default is blocked. Soft-delete is allowed.

---

## 15. Migration legacy columns

During migration, library_items and similar tables carry:

- `legacy_source VARCHAR(64) NULL` — e.g. `"v0_configkit"`, `"sologtak_scrape"`
- `legacy_id VARCHAR(128) NULL` — the source-system identifier

These let migrations be re-runnable and let diagnostics trace items back to
their origin. Indexed: `KEY(legacy_source, legacy_id)`.

After migration is fully sunset, these columns can be dropped via a final
migration. Until then, they remain.

---

## 16. Acceptance criteria

This document is in DRAFT v2 status. Awaiting owner review.

Sign-off requires:

- [ ] Module vs library separation (§1) is the model.
- [ ] Module capability flags (§2) are the right axis set.
- [ ] `attribute_schema_json` mechanism (§3) is accepted.
- [ ] Built-in module list (§4) is acceptable as seed data.
- [ ] Library schema (§5) is accepted.
- [ ] Universal + capability-gated + JSON attributes pattern (§6) is accepted.
- [ ] `source_config_json` for field source binding (§7) is the law.
- [ ] Item key scoping rule (§8) is accepted.
- [ ] Rule reference styles (§9) are accepted.
- [ ] Excel import shape (§10) is accepted.
- [ ] Shared CRUD admin UI approach (§11) is accepted.
- [ ] New module workflow (§12) is accepted.
- [ ] Filters vs compatibility distinction (§13) is accepted.
- [ ] Deletion safety rules (§14) are accepted.
- [ ] Legacy columns approach (§15) is accepted.

After approval of all four Batch 1 documents (TARGET_ARCHITECTURE,
DATA_MODEL, FIELD_MODEL, MODULE_LIBRARY_MODEL), proceed to Batch 2.

---

## 17. Item type + price source (Phase 4.2)

Library items grow two orthogonal fields in Phase 4.2 that were
previously implicit:

### 17.1 `item_type`

| Value     | Meaning                                                                                        |
| --------- | ---------------------------------------------------------------------------------------------- |
| `simple`  | Standalone option. Default for every existing item. Matches the current shape exactly.         |
| `bundle`  | Composite item that maps to multiple Woo products under a single customer-visible label.       |

Validation (server-side, on item create / update):

- `bundle` items MUST carry a `bundle_components_json` array with at
  least one component (BUNDLE_MODEL §3).
- Each component MUST reference a real, published Woo product (no
  trashed / deleted IDs).
- A bundle component cannot itself be a `bundle` (no recursion in
  v1 — BUNDLE_MODEL §10 q1).
- `simple` items ignore bundle-only fields; the validator nulls them
  out on save so the schema stays clean.

See `BUNDLE_MODEL.md` for the full bundle model.

### 17.2 `price_source`

`price_source` is **per-item**, not per-module. Modules no longer
constrain which `price_source` values their items can use — the
constraint is local to the item:

- `simple` items may use `configkit` (default), `woo`, or
  `product_override` (set by the resolver, never authored).
- `bundle` items may use `bundle_sum` or `fixed_bundle` only.
- Validation is enforced by `LibraryItemService` per
  PRICING_SOURCE_MODEL §2.

This means a single module (e.g. `motors`) can hold items whose
prices come from different sources — one motor priced from Woo's
own table, another priced via a ConfigKit-side number, a third
shipped as a bundle. The `module.supports_*` capability flags only
gate which fields are *visible* on the library item form, not how
prices are sourced.

Modules created before Phase 4.2 keep working unchanged: their
existing items default to `item_type = 'simple'` /
`price_source = 'configkit'`, exactly the behaviour they already had.
