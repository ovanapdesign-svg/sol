# ConfigKit Field Model

| Field            | Value                                       |
|------------------|---------------------------------------------|
| Status           | DRAFT v2 (regenerated with corrections)     |
| Document type    | Field model specification                   |
| Companion docs   | TARGET_ARCHITECTURE.md, DATA_MODEL.md       |
| Spec language    | English                                     |

---

## 1. Why a 5-axis model

Previous configurators mix UI input style, display rendering, value source,
and pricing behavior into a single `field_type` enum. This produces:

- Special-case logic everywhere.
- Impossible combinations silently allowed (a `heading` with a price).
- New requirements forcing new enum values, which forces refactor.

The fix is to separate concerns into orthogonal axes. A field is a tuple of:

```
(field_kind, input_type, display_type, value_source, behavior)
```

Plus a `source_config_json` blob that parameterizes the value source. The
Validation Engine constrains which combinations are valid.

---

## 2. Axis 1 — `field_kind`

What role does this field play in the configurator?

| Value        | Meaning                                                   |
|--------------|-----------------------------------------------------------|
| `input`      | Customer chooses or enters a value.                       |
| `display`    | Static content; no value, no price impact.                |
| `computed`   | Value is derived from other fields (read-only).           |
| `addon`      | Customer chooses a Woo product to attach as an add-on.    |
| `lookup`     | Field's value feeds the lookup table (e.g. width, height).|

A field has exactly one `field_kind`.

---

## 3. Axis 2 — `input_type`

How does the customer enter the value?

| Value       | Meaning                                       | Typical use         |
|-------------|-----------------------------------------------|---------------------|
| `number`    | Numeric input (with min/max/step)             | Width, height       |
| `radio`     | Single choice from a small set                | Manuell / Motorisert|
| `checkbox`  | Multi-select from a set                       | Optional accessories|
| `dropdown`  | Single choice from a long set                 | Cable length        |
| `text`      | Free-form text                                | Customer note       |
| `hidden`    | Not shown; value set programmatically         | Computed defaults   |

For `field_kind = display` and `field_kind = computed`, `input_type` is `null`.

---

## 4. Axis 3 — `display_type`

How is the field rendered visually?

| Value          | Meaning                                          | Typical pairing     |
|----------------|--------------------------------------------------|---------------------|
| `plain`        | Default rendering for the input_type             | Most fields         |
| `cards`        | Each option is a card with image + label + price | Fabric collections  |
| `image_grid`   | Grid of images, single-line label                | Profile colors      |
| `swatch_grid`  | Color swatches with hover labels                 | RAL colors          |
| `accordion`    | Group container with expand/collapse             | Grouped options     |
| `summary`      | Read-only summary of selections                  | Final review step   |
| `info_block`   | Static prose, no input                           | Helper paragraphs   |
| `heading`      | Visual section heading                           | Section dividers    |

The same `input_type = radio` can have `display_type = plain` (radio buttons),
`cards` (clickable cards), or `image_grid` (clickable images).

---

## 5. Axis 4 — `value_source`

Where do the available options come from?

| Value             | Meaning                                                   |
|-------------------|-----------------------------------------------------------|
| `manual_options`  | Inline options in `wp_configkit_field_options` table      |
| `library`         | Pulls from one or more libraries                          |
| `woo_products`    | Pulls a curated list of Woo products                      |
| `woo_category`    | Pulls Woo products in a given category                    |
| `lookup_table`    | Field is a lookup dimension; value feeds the lookup match |
| `computed`        | Value computed by a rule from other fields                |

The `source_config_json` column carries the parameters. See §7.

---

## 6. Axis 5 — `behavior`

What does selecting this field DO to pricing and downstream logic?

| Value                | Meaning                                                          |
|----------------------|------------------------------------------------------------------|
| `normal_option`      | Selection adds option price (or surcharge) to total              |
| `product_addon`      | Selection links a Woo product; its price is added to total       |
| `lookup_dimension`   | Field's value is consumed by the lookup engine (no direct price) |
| `price_modifier`     | Selection applies a multiplier or fixed surcharge per rules      |
| `presentation_only`  | Has no price impact; purely informational                        |

A `heading` is always `presentation_only`. A `lookup` field is always
`lookup_dimension`. An `addon` field is always `product_addon`.

---

## 7. `source_config_json` — JSON spec by value_source

The `source_config_json` column holds a JSON object whose shape depends on
`value_source`. Application code validates against a schema at write time.

### 7.1 type: `library`

```json
{
  "type": "library",
  "libraries": ["textiles_dickson", "textiles_sandatex"],
  "filters": ["markise"],
  "sort": "popular_first"
}
```

| Key         | Required | Type            | Notes                                |
|-------------|----------|-----------------|--------------------------------------|
| type        | yes      | string          | `"library"`                          |
| libraries   | yes      | array of strings| One or more `library_key` values     |
| filters     | no       | array of strings| Restrict items by filter tag         |
| sort        | no       | string          | `popular_first` / `alpha` / `manual` |

Multi-library merge deduplicates by `item_key`. Selections store
`library_key:item_key` compound keys.

### 7.2 type: `woo_category`

```json
{
  "type": "woo_category",
  "category_slug": "sensorer",
  "required_tags": ["io"],
  "exclude_out_of_stock": true
}
```

| Key                  | Required | Type            | Notes                          |
|----------------------|----------|-----------------|--------------------------------|
| type                 | yes      | string          | `"woo_category"`               |
| category_slug        | yes      | string          | Woo product category slug      |
| required_tags        | no       | array of strings| Filter by Woo product tags     |
| exclude_out_of_stock | no       | boolean         |                                |

### 7.3 type: `woo_products`

```json
{
  "type": "woo_products",
  "product_skus": ["SOM-IO-300", "SOM-IO-400"]
}
```

| Key          | Required | Type            | Notes                        |
|--------------|----------|-----------------|------------------------------|
| type         | yes      | string          | `"woo_products"`             |
| product_skus | yes      | array of strings| Woo product SKUs (preferred) |

SKUs are stable across environments; product IDs are not. Use SKUs.

### 7.4 type: `manual_options`

```json
{
  "type": "manual_options"
}
```

No parameters. Options live in `wp_configkit_field_options` keyed by
`(template_key, field_key, option_key)`.

### 7.5 type: `lookup_table`

```json
{
  "type": "lookup_table",
  "lookup_table_key": "markise_2d_v1",
  "dimension": "width"
}
```

| Key              | Required | Type   | Notes                                |
|------------------|----------|--------|--------------------------------------|
| type             | yes      | string | `"lookup_table"`                     |
| lookup_table_key | yes      | string | Which table feeds this dimension      |
| dimension        | yes      | string | `width` / `height` / `price_group`   |

### 7.6 type: `computed`

```json
{
  "type": "computed",
  "rule_key": "width_over_5000_surcharge"
}
```

| Key      | Required | Type   | Notes                              |
|----------|----------|--------|------------------------------------|
| type     | yes      | string | `"computed"`                       |
| rule_key | yes      | string | Rule that sets this field's value  |

---

## 8. Valid combinations matrix

✅ valid · ❌ forbidden · ⚠️ valid but unusual

| field_kind  | input_type  | display_type    | value_source       | behavior            | Status |
|-------------|-------------|-----------------|--------------------|---------------------|--------|
| input       | number      | plain           | manual_options     | normal_option       | ✅     |
| input       | radio       | plain           | manual_options     | normal_option       | ✅     |
| input       | radio       | cards           | library            | normal_option       | ✅     |
| input       | radio       | image_grid      | library            | normal_option       | ✅     |
| input       | radio       | swatch_grid     | library            | normal_option       | ✅     |
| input       | checkbox    | plain           | manual_options     | normal_option       | ✅     |
| input       | dropdown    | plain           | library            | normal_option       | ✅     |
| input       | text        | plain           | manual_options     | presentation_only   | ✅     |
| input       | hidden      | plain           | computed           | presentation_only   | ✅     |
| lookup      | number      | plain           | lookup_table       | lookup_dimension    | ✅     |
| lookup      | dropdown    | plain           | manual_options     | lookup_dimension    | ✅     |
| addon       | checkbox    | cards           | woo_products       | product_addon       | ✅     |
| addon       | checkbox    | cards           | woo_category       | product_addon       | ✅     |
| addon       | radio       | cards           | woo_category       | product_addon       | ✅     |
| display     | null        | heading         | (none)             | presentation_only   | ✅     |
| display     | null        | info_block      | (none)             | presentation_only   | ✅     |
| display     | null        | summary         | (none)             | presentation_only   | ✅     |
| computed    | hidden      | plain           | computed           | price_modifier      | ✅     |
| input       | radio       | plain           | manual_options     | price_modifier      | ⚠️     |
| display     | null        | heading         | (none)             | normal_option       | ❌     |
| display     | null        | info_block      | library            | normal_option       | ❌     |
| input       | number      | plain           | lookup_table       | normal_option       | ❌     |
| lookup      | radio       | cards           | library            | lookup_dimension    | ❌     |
| addon       | number      | plain           | woo_products       | normal_option       | ❌     |

The Validation Engine rejects forbidden combinations on save with a clear
error message naming the conflicting axes.

---

## 9. Concrete examples

### 9.1 Width input (lookup dimension)

```
field_key:          width_mm
label:              Bredde
field_kind:         lookup
input_type:         number
display_type:       plain
value_source:       lookup_table
source_config_json: {"type":"lookup_table","lookup_table_key":"markise_2d_v1","dimension":"width"}
behavior:           lookup_dimension
pricing_mode:       null
is_required:        true
default_value:      null
```

### 9.2 Profile color (image grid from library)

```
field_key:          profile_color
label:              Profilfarge
field_kind:         input
input_type:         radio
display_type:       image_grid
value_source:       library
source_config_json: {"type":"library","libraries":["profile_colors_ral"]}
behavior:           normal_option
pricing_mode:       fixed
pricing_value:      0
is_required:        true
default_value:      ral_9010
```

### 9.3 Fabric (cards from textile library)

```
field_key:          fabric_color
label:              Velg dukfarge
field_kind:         input
input_type:         radio
display_type:       cards
value_source:       library
source_config_json: {"type":"library","libraries":["textiles_dickson","textiles_sandatex"],"filters":["markise"],"sort":"popular_first"}
behavior:           normal_option
pricing_mode:       fixed
pricing_value:      0
is_required:        true
```

### 9.4 Sensor add-on (Woo category)

```
field_key:          sensor_addon
label:              Værsensor
field_kind:         addon
input_type:         checkbox
display_type:       cards
value_source:       woo_category
source_config_json: {"type":"woo_category","category_slug":"sensorer"}
behavior:           product_addon
pricing_mode:       null
is_required:        false
```

### 9.5 Section heading

```
field_key:          section_size_heading
label:              Skriv inn mål
field_kind:         display
input_type:         null
display_type:       heading
value_source:       null
source_config_json: null
behavior:           presentation_only
```

### 9.6 Computed surcharge

```
field_key:          large_size_surcharge
label:              Storformat-tillegg
field_kind:         computed
input_type:         hidden
display_type:       plain
value_source:       computed
source_config_json: {"type":"computed","rule_key":"width_over_5000_surcharge"}
behavior:           price_modifier
pricing_mode:       fixed
pricing_value:      1500
is_required:        false
```

---

## 10. Pricing impact rules

A field affects the order total ONLY if all conditions are true:

1. The field is currently **visible** (not hidden by a rule).
2. The field has a value (selection made or default applied).
3. The field's `behavior` is one of: `normal_option`, `product_addon`,
   `price_modifier`.
4. If `behavior = lookup_dimension`, the field affects price only via the
   lookup engine, never directly.

A hidden field MUST NOT affect price even if it has a saved value.

A field with `behavior = presentation_only` MUST NOT affect price.

---

## 11. Required/optional logic

`is_required = true` means: cannot proceed past this step without a value.

Rules can override:

- `require_field` makes a normally-optional field required.
- `hide_field` hides; hidden fields are never required even if `is_required`.

Effective rule:

```
effective_required(field) =
    is_visible(field) AND
    (field.is_required OR rule_says_required(field))
```

---

## 12. Default value semantics

`default_value` stores a stable `option_key` (or `library_key:item_key`, or
numeric value), never a label.

Default precedence:

1. Per-product binding default (`_configkit_default_values_json` post meta).
2. Field-level `default_value`.
3. None — field is empty until customer chooses.

---

## 13. Show in cart / order / email

| Column                  | Default | Purpose                              |
|-------------------------|---------|--------------------------------------|
| `show_in_cart`          | true    | Customer cart line summary           |
| `show_in_checkout`      | true    | Checkout page line summary           |
| `show_in_admin_order`   | true    | Admin order edit screen              |
| `show_in_customer_email`| true    | Customer order confirmation email    |

Production-internal fields set all four to false.

---

## 14. field_key uniqueness

`field_key` is unique within a TEMPLATE (across all steps), not just within a
step. Stored uniqueness: `UNIQUE(template_key, field_key)` in the fields
table.

This makes rules reference `field_key` directly without scoping by step.

`field_key` MUST match regex `^[a-z][a-z0-9_]{0,63}$`.

Reserved prefix: `_configkit_`.

---

## 15. Selection storage format

Selections in cart/order meta use stable keys:

```json
{
  "width_mm":      4000,
  "height_mm":     3000,
  "profile_color": "ral_9010",
  "fabric_color":  "textiles_dickson:u171",
  "control_type":  "motorized",
  "sensor_addon":  ["SOM-IO-WIND-300", "SOM-IO-SUN-200"]
}
```

- Numeric inputs: number value.
- Single-library selections: `option_key` or `item_key` string.
- Multi-library selections: `library_key:item_key` compound string.
- Multi-select (checkbox): array of keys.
- Woo addon by SKU: SKU string (resolved to product ID at cart write).

---

## 16. Acceptance criteria

This document is in DRAFT v2 status. Awaiting owner review.

Sign-off requires:

- [ ] Five-axis separation (§§2-6) is the model.
- [ ] `source_config_json` JSON spec (§7) is the law.
- [ ] Validity matrix (§8) is broadly correct.
- [ ] Examples (§9) match owner intent.
- [ ] Pricing impact rules (§10) are the law.
- [ ] Required/optional rule overrides (§11) are accepted.
- [ ] Default value precedence (§12) is accepted.
- [ ] Visibility control (§13) is accepted.
- [ ] field_key uniqueness scope (§14) is accepted.
- [ ] Selection storage format (§15) is accepted.

After approval, MODULE_LIBRARY_MODEL.md should be reviewed alongside.
