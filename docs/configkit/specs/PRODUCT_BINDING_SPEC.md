# ConfigKit Product Binding Specification

|Field         |Value                                                                                                   |
|--------------|--------------------------------------------------------------------------------------------------------|
|Status        |DRAFT v1                                                                                                |
|Document type |Product binding specification                                                                           |
|Companion docs|TARGET_ARCHITECTURE.md, ADMIN_SITEMAP.md, OWNER_UX_FLOW.md, RULE_ENGINE_CONTRACT.md, PRICING_CONTRACT.md|
|Spec language |English                                                                                                 |
|Affects phases|Phase 3 (admin UI core), Phase 4+ (preview)                                                             |

-----

## 1. Purpose

Product binding is where the owner connects a specific WooCommerce product
to ConfigKit configuration. The owner’s job here is product-level setup:

- Pick the template
- Pick the lookup table
- Set defaults
- Restrict allowed options
- Override pricing where needed
- Lock or hide fields per-product
- Verify diagnostics
- Preview

This must happen **inside the WooCommerce product edit screen**, not in
a separate ConfigKit page. The owner already lives in WooCommerce when
managing products. Forcing them to navigate to a separate ConfigKit
page for product-specific setup wastes time and breaks flow.

-----

## 2. Architectural placement

### 2.1 Where ConfigKit configuration lives

WooCommerce → Products → Edit Product → **ConfigKit tab** (in Product
Data meta box, alongside General/Inventory/Shipping/etc.).

The ConfigKit tab is the **primary** place where owner configures
product-specific binding.

### 2.2 ConfigKit admin “Products” page

The top-level ConfigKit → Products page exists as an **overview /
readiness board**:

- Lists all WooCommerce products
- Shows ConfigKit status badge per product
- Allows filtering by status (ready / missing template / etc.)
- “Edit binding” link **deep-links to WooCommerce product edit screen
  with the ConfigKit tab pre-selected**

The Products page is read-only-with-jumps. It does NOT contain inline
edit forms. All editing happens in the Woo product tab.

### 2.3 Why this split

- Owner workflow lives in WooCommerce most of the day.
- Shared assets (modules, libraries, templates, lookup tables) are
  managed in ConfigKit admin (since they are reused across many
  products).
- Product-specific binding belongs to the product, not to ConfigKit.

-----

## 3. ConfigKit product tab — section structure

Eight sections, in order:

```
┌─ ConfigKit ───────────────────────────────────────────────────────┐
│                                                                   │
│  1. Enable + status                                               │
│  2. Base setup                                                    │
│  3. Product defaults                                              │
│  4. Allowed options / source overrides                            │
│  5. Pricing overrides                                             │
│  6. Visibility / locking                                          │
│  7. Diagnostics                                                   │
│  8. Preview                                                       │
│                                                                   │
└───────────────────────────────────────────────────────────────────┘
```

Sections collapse/expand. Empty sections (no template selected →
sections 3-6 disabled) show a lock icon and “Pick a template first.”

-----

## 4. Section 1 — Enable + status

### 4.1 Enable toggle

- Checkbox: “Enable ConfigKit for this product”
- When OFF, the product is a normal Woo product. ConfigKit is dormant.
- When ON, sections 2-8 unlock.

### 4.2 Status badge

Shown next to the toggle. One of:

|Status                |Meaning                                       |
|----------------------|----------------------------------------------|
|`disabled`            |ConfigKit toggled OFF                         |
|`missing_template`    |Toggled ON but no template selected           |
|`missing_lookup_table`|Template selected but no lookup table assigned|
|`invalid_defaults`    |Defaults reference deleted fields/items       |
|`pricing_unresolved`  |Default config doesn’t resolve a price        |
|`ready`               |All checks pass; product is frontend-ready    |

Status is derived server-side, not stored. Re-evaluated on every page
load and after any change.

### 4.3 “Run diagnostics” button

Triggers section 7’s diagnostic checklist. Useful when owner makes
changes and wants explicit re-check.

-----

## 5. Section 2 — Base setup

Four pickers:

### 5.1 Family

Dropdown of available families (`wp_configkit_families` where
`is_active = 1`). Auto-suggested from product’s WooCommerce category if
a heuristic match exists (e.g., Woo category “Markiser” → family
`markiser`). Owner can override.

### 5.2 Template

Dropdown filtered by selected family. Shows only published templates.
Each option displays: template name + “v(N) published” version.

### 5.3 Template version

Read-only display: which version of the template is bound. Default is
“latest published”. Advanced toggle: “Pin to specific version” for
edge cases (e.g., a customer ordered v1 and never wants the
configuration to change).

### 5.4 Lookup table

Dropdown filtered by selected family. Shows only `is_active = 1`
lookup tables with at least one cell.

### 5.5 Frontend mode

Dropdown: stepper / accordion / single-page. Default: stepper. This
controls how the configurator renders to the customer (Phase 4+).

-----

## 6. Section 3 — Product defaults

After template is picked, this section dynamically renders the
template’s fields with their default-value pickers.

### 6.1 Rendering rules

For each field in the template:

- **Number field** → number input
- **Single-choice (radio/dropdown)** → dropdown of options/items
- **Multi-choice (checkbox)** → checkbox group
- **Library field** → searchable picker of library items
- **Woo addon field** → searchable Woo product picker by SKU
- **Display field** → not shown (no value to default)
- **Lookup dimension field** → number input (e.g., default width, height)

### 6.2 Storage

Defaults stored as `_configkit_defaults_json` post meta on the
WooCommerce product. JSON shape:

```json
{
  "width_mm": 3000,
  "height_mm": 2000,
  "profile_color": "ral_9010",
  "fabric_collection": "dickson",
  "operation_type": "motorized",
  "motor": "somfy_io"
}
```

Keys are `field_key`. Values are `option_key`/`item_key` (NEVER labels,
per architectural rule “no labels-as-keys”).

### 6.3 Defaults vs locked values

This section sets defaults — the value the customer **starts with**.
Section 6 controls whether values can be **changed** by the customer.

A defaulted value that is also locked = the customer never sees a
choice and the value is fixed.

-----

## 7. Section 4 — Allowed options / source overrides

Per-product narrowing of template’s value sources.

### 7.1 Concept

Template defines what kinds of options exist. Product binding
restricts which subsets are available.

Example:

- Template “Markise motorisert” has field `fabric` with `value_source = library`, allowing libraries `textiles_dickson`, `textiles_sandatex`,
  `textiles_sunesta`.
- Product “VIKA Markise” only sells Dickson and Sandatex, not Sunesta.
- Product binding: `allowed_libraries[fabric] = [textiles_dickson, textiles_sandatex]`.

### 7.2 UI

For each template field with a non-empty value_source:

- **Library source** → multi-select of libraries available for that
  module. Owner ticks which libraries are allowed for THIS product.
  - Optional: per-library, “Exclude specific items” — allows excluding
    individual items by item_key.
  - Optional: “Allowed price groups” — restrict to specific
    `price_group_key` values.
- **Woo category source** → multi-select of categories. Owner can
  also pick specific Woo product SKUs for finer control.
- **Manual options source** → checkbox per option to enable/disable
  for this product. (Manual options are template-defined; product
  cannot add new ones, only filter.)

### 7.3 Storage

`_configkit_allowed_sources_json` post meta:

```json
{
  "fabric": {
    "allowed_libraries": ["textiles_dickson", "textiles_sandatex"],
    "excluded_items": ["dickson:legacy_2018"],
    "allowed_price_groups": ["1", "2", "3"]
  },
  "motor": {
    "allowed_libraries": ["motors_somfy"],
    "excluded_items": [],
    "allowed_price_groups": []
  }
}
```

### 7.4 Empty arrays

Empty array = “no restriction, use template default”. Not the same
as missing key.

-----

## 8. Section 5 — Pricing overrides

Six per-product pricing controls:

### 8.1 Base price fallback

If the lookup table doesn’t resolve a price (e.g., requested
dimensions outside the table’s range), this fallback is used. Default:
empty (= block add-to-cart per PRICING_CONTRACT.md §7).

### 8.2 Minimum price

Floor applied after all calculations per PRICING_CONTRACT.md §12.
Default: empty (= no floor).

### 8.3 Product surcharge

Flat addition applied to every configuration of this product. Useful
for product-specific labor or shipping costs.

### 8.4 Sale price override

Optional discount mode for the product:

- “off” → use template/library sale prices as-is
- “force_regular” → ignore all sale prices for this product
- “discount_percent” → apply percentage off the final total

### 8.5 VAT display override

- “use_global” → use ConfigKit Settings → General default
- “incl_vat” / “excl_vat” / “off” → override per product

### 8.6 Allowed price groups

Restrict which `price_group_key` values are allowed for this product.
E.g., product binding can disallow expensive price group “5” if this
SKU is positioned as budget tier.

### 8.7 Storage

`_configkit_pricing_overrides_json` post meta.

-----

## 9. Section 6 — Visibility / locking

Per-product field-level overrides. Four operations per field:

### 9.1 Hide field

Field is excluded from the customer-facing configurator entirely. Not
shown, not validated, not contributing to price (per PRICING_CONTRACT.md
§6 — hidden fields contribute zero).

Use case: a basic product variant doesn’t expose `kappe_type`.

### 9.2 Require field

Field is mandatory for this product, even if the template marks it as
optional.

### 9.3 Lock field to value

Field is shown read-only with a fixed value. Customer cannot change.

Use case: VIKA product is always motorized → `operation_type` locked
to `motorized`.

### 9.4 Preselect value

Same as default (section 3) but with extra emphasis: the field is
shown but pre-filled. Customer can change. (This is largely redundant
with defaults; consider whether to keep both or merge in v1.)

### 9.5 Storage

`_configkit_field_overrides_json` post meta:

```json
{
  "operation_type": { "lock": "motorized" },
  "kappe_type": { "hide": true },
  "wind_sensor": { "require": true }
}
```

-----

## 10. Section 7 — Diagnostics

Inline checklist showing per-product readiness.

### 10.1 Checks (Phase 3 minimum)

- [ ] Template selected
- [ ] Published template version exists
- [ ] Lookup table selected
- [ ] Lookup table has at least 1 cell
- [ ] All allowed libraries exist and are active
- [ ] All allowed library items exist and are active
- [ ] Defaults reference valid field/option/item keys
- [ ] Default configuration resolves to a price
- [ ] All template rules have valid targets (within product context)
- [ ] No locked field has invalid value
- [ ] Frontend mode selected

### 10.2 Output

Each check shows:

- Green ✓ if passed
- Red ✗ if failed
- Yellow ⚠ if warning (e.g., warning is allowed but worth noting)

Failed checks have a “Fix” link. The link either:

- Jumps within the same screen to the relevant section
- Jumps to the relevant ConfigKit admin page (e.g., “Add cells to
  lookup table → opens lookup table detail”)

### 10.3 Status derivation

The overall status badge in section 1.2 is computed from these checks:

- Any critical failure → `missing_template`/`missing_lookup_table`/
  `invalid_defaults`/`pricing_unresolved` (most specific failure wins)
- All passes → `ready`

-----

## 11. Section 8 — Preview

Phase 3 placeholder. Phase 4+ will add:

- “Preview as customer” button → renders the configurator in admin
  iframe
- “Test default configuration” → runs full price calc + validation
- “Test add to cart” (Phase 5+) → simulates add-to-cart

For Phase 3, this section shows: “Customer preview comes in Phase 4.
For now, run diagnostics to verify readiness.”

-----

## 12. Storage summary

All product binding state lives in WooCommerce post meta on the
product post:

|Meta key                           |Type    |Description                      |
|-----------------------------------|--------|---------------------------------|
|`_configkit_enabled`               |bool    |Master toggle                    |
|`_configkit_template_key`          |string  |Bound template                   |
|`_configkit_template_version_id`   |int     |Pinned version (or 0 = latest)   |
|`_configkit_lookup_table_key`      |string  |Bound lookup table               |
|`_configkit_family_key`            |string  |Bound family                     |
|`_configkit_frontend_mode`         |string  |stepper / accordion / single-page|
|`_configkit_defaults_json`         |LONGTEXT|Default values per field_key     |
|`_configkit_allowed_sources_json`  |LONGTEXT|Per-field source overrides       |
|`_configkit_pricing_overrides_json`|LONGTEXT|Pricing overrides                |
|`_configkit_field_overrides_json`  |LONGTEXT|Hide/lock/require/preselect      |

The product binding does NOT duplicate template field definitions into
post meta. Template fields are read live from `wp_configkit_fields` via
the bound `template_version_id`. Only overrides and defaults live on
the product.

-----

## 13. REST endpoints

Product binding is owned by WooCommerce post meta, but ConfigKit
provides REST endpoints for:

- `GET /configkit/v1/products/(?P<product_id>\d+)/binding` — read full binding state
- `PUT /configkit/v1/products/(?P<product_id>\d+)/binding` — update binding (with version_hash)
- `POST /configkit/v1/products/(?P<product_id>\d+)/diagnostics` — run diagnostic checklist
- `GET /configkit/v1/products` — overview list (used by ConfigKit → Products page)

Capability check: `configkit_manage_products`.

-----

## 14. Forbidden patterns

- Do NOT store template fields as post meta. Only overrides and defaults.
- Do NOT make product binding only available in a separate ConfigKit page.
- Do NOT require raw JSON in the product tab — UI is structured forms only.
- Do NOT persist label values as keys. Always store `field_key`/`option_key`/`item_key`.
- Do NOT show “save” buttons that don’t work — every action wired to backend.
- Do NOT autosave field-level changes — single explicit Save button at top of tab.
- Do NOT make defaults edit happen outside this tab (e.g., on a separate
  “Defaults” admin page).

-----

## 15. UX rules

- Owner must not have to leave the WooCommerce product edit screen to
  configure product-specific settings.
- Owner only leaves it when creating shared assets (modules, libraries,
  templates, lookup tables).
- Status badge updates after every save (no stale state).
- Save button is sticky at the bottom of the tab so it’s visible while
  scrolling through 8 sections.

-----

## 16. Phase 3 deliverables

For Phase 3 Products binding chunk, deliver:

- [ ] Sections 1, 2, 3 fully working
- [ ] Sections 4, 5, 6 working with structured form (no JSON visible)
- [ ] Section 7 diagnostics with all 11 checks
- [ ] Section 8 placeholder with Phase 4 message
- [ ] ConfigKit → Products overview page with deep-link to Woo edit
- [ ] All 10 post meta keys persisted
- [ ] All 4 REST endpoints
- [ ] PHPUnit tests for ProductBindingService validation logic
- [ ] No fake buttons, no demo data, no autosave

-----

## 17. Acceptance criteria

This document is in DRAFT v1. Awaiting owner review.

Sign-off requires:

- [ ] Section structure (§3-§11) accepted.
- [ ] Storage model (§12) accepted.
- [ ] Forbidden patterns (§14) are the law.
- [ ] Phase 3 deliverables (§16) accepted as Phase 3 Products binding scope.

After approval, Phase 3 Products binding chunk may be implemented
following this spec as the source of truth.

---

## 18. Per-item price overrides (Phase 4.2)

A binding can override the price of one or more library items
**for THIS Woo product only**. The override wins the resolution
ladder per `PRICING_SOURCE_MODEL.md §3`, regardless of what the
library item declares.

### 18.1 Storage

Stored as a single post meta key on the bound Woo product:

```
_configkit_item_price_overrides
```

Value shape (JSON):

```json
{
  "motors_somfy:somfy_io_premium": {
    "price": 4200.00,
    "price_source": "product_override",
    "reason": "Volume discount agreement"
  },
  "textiles_dickson:orchestra_max_blue": {
    "price": 0.00,
    "price_source": "product_override",
    "reason": "Free with motor purchase"
  }
}
```

Keyed by `library_key:item_key`. Each entry carries:

| Field          | Type        | Notes                                                                            |
| -------------- | ----------- | -------------------------------------------------------------------------------- |
| `price`        | decimal ≥ 0 | Required. The override is what flows into PricingEngine for this binding.        |
| `price_source` | string      | Always `'product_override'`. Stored for round-trip clarity; not owner-editable.   |
| `reason`       | string      | Optional, free-text. Surfaced in the binding admin UI and Diagnostics.           |

### 18.2 UI

A new "Item price overrides" section appears in the binding admin
between section 5 (Pricing overrides) and section 6 (Visibility /
locking). For each library item that's reachable from the bound
template's library-backed fields, the owner can:

- See the resolved default price (and which source it came from —
  `library`, `woo`, `bundle_*`).
- Provide an override price, with optional reason.
- Clear the override (returns to the library item's own resolution).

Items with `item_type = 'bundle'` show their component breakdown
read-only beneath the override input so the owner understands what
they're overriding.

### 18.3 Bundle override semantics

When the override targets a bundle item:

- `cart_behavior = 'price_inside_main'` → the override is the new
  fixed price for the bundle. Component prices are still computed
  for stock / order display, but the customer sees the override.
- `cart_behavior = 'add_child_lines'` → the override wins the
  cart-line price for the configurable product, but each child
  component still bills at its own resolved price. The reconciliation
  rule (BUNDLE_MODEL §10 q2) applies after the override is applied.

### 18.4 Diagnostics

ProductDiagnosticsService gains a new check:

- `item_overrides_valid` (critical) — every key in
  `_configkit_item_price_overrides` resolves to a real, active
  library item that's reachable from the bound template. Stale
  overrides referencing deleted items are flagged with a precise
  fix link to this section.

---

## 19. Allowed item-level rules

By default every library item reachable from the bound template
can be overridden. Owners who want a tighter contract can pin the
override surface via the binding's existing
`allowed_sources[field_key].allowed_items` list (PRODUCT_BINDING §6):
items NOT in that list cannot be overridden, and the admin UI
hides their input rows.

This is purely a UI guard — the resolver still respects whatever's
stored in the JSON, so older bindings with overrides on now-disallowed
items continue to work until the owner clears them. Diagnostics
flags such drift as `item_overrides_disallowed` (warning).

---

## 20. Phase 4.2 deliverables

For the Phase 4.2 implementation chunk these specs must land
together:

- [ ] `0017_extend_library_items_pricing_bundles.php` migration
  adding the six columns enumerated in DATA_MODEL §3.3 / Phase 4.2
  additions block.
- [ ] `_configkit_item_price_overrides` post meta key in
  PRODUCT_BINDING §18 storage.
- [ ] `PriceProvider` interface + production binding under
  `src/Frontend/`.
- [ ] `PricingEngine` constructor takes the new dependency; existing
  pure-PHP tests gain a stub provider.
- [ ] `LibraryItemService` validates `item_type` / `price_source` /
  `bundle_components_json` per the new specs.
- [ ] Library item admin form gets the new fields (item type select,
  bundle components editor, price source dropdown) per
  MODULE_LIBRARY_MODEL.
- [ ] Binding admin §18 UI for per-item overrides.
- [ ] DiagnosticsService gains the new checks
  `item_overrides_valid` (critical) and `item_overrides_disallowed`
  (warning).
- [ ] Cart integration picks up bundle components per
  `cart_behavior` and emits stock decrements per `stock_behavior`.
- [ ] Order admin honours `admin_order_display`.

---

## 21. Per-item price override UI

The §18 storage model lays down WHERE overrides live; this section
locks HOW they appear in the Woo product ConfigKit tab. Every
field, label, and copy decision MUST cite
`UI_LABELS_MAPPING.md §8` and `§9.3` (the "Test default
configuration price" preview).

### 21.1 Section placement and copy

Section title: **"Item price overrides for this product"**. Sits
between §8 (Pricing overrides) and §9 (Visibility / locking) in
the binding admin scroll order. The technical key
`_configkit_item_price_overrides` never appears as a label.

### 21.2 Editor layout

The editor is a table with five columns. Owner sees three numbers
per row (original / override / resolved) — never enums:

| Column            | Label                                    | Notes                                                                                  |
| ----------------- | ---------------------------------------- | -------------------------------------------------------------------------------------- |
| Item              | "Item"                                   | Library item display label + small `↗` link to the item's edit screen (Phase 4.1).    |
| Original price    | "Original price"                         | Read-only. Resolved per the library item's own `price_source`.                         |
| Override price    | "Override price (kr)"                    | Editable numeric input. Empty → no override.                                           |
| Resolved final    | "Customer sees"                          | Read-only. Override (if set) → otherwise Original. Updated live as owner edits.       |
| Note              | "Reason / note (internal)"               | Optional free-text. Not shown to customers; surfaced in Diagnostics.                   |

A "**+ Add price override**" button below the table launches an
inline picker: a searchable dropdown of every library item
reachable from the bound template's `allowed_sources` (PRODUCT_BINDING
§6 + §19). The picker shows the item label as the primary string;
the technical key `library_key:item_key` is a faint helper next to
the label so the owner can disambiguate items that share a display
label.

### 21.3 Removing an override

When the owner clears the **Override price** cell on a row and
saves the binding, that row is **removed** from the
`_configkit_item_price_overrides` JSON entirely (the row is not
saved with `price = null`). The library item reverts to its own
default `price_source` resolution.

The "Action" / row-delete button (`×`) in the editor performs the
same operation explicitly without requiring the owner to clear
the cell.

### 21.4 Bundle override semantics

When the override targets a `bundle` library item (BUNDLE_MODEL §2)
the editor still shows three numbers in the same shape, but the
**Original price** column reflects the bundle's resolved value
(per `bundle_sum` / `fixed_bundle`) and the resolved column flows
into the cart line as documented in PRODUCT_BINDING §18.3:

- `cart_behavior = 'price_inside_main'` → the override is the
  whole bundle's new fixed cart contribution; per-component
  prices remain visible in §9.2's preview for stock and order
  display.
- `cart_behavior = 'add_child_lines'` → the override wins the
  configurable product's cart line; child component lines bill at
  their own resolved prices, with the BUNDLE_MODEL §10 decision 2
  proportional reconciliation applied.

### 21.5 Test default configuration price

A read-only "**Test default configuration price**" preview sits
beneath the override table per `UI_LABELS_MAPPING.md §9.3`:

> "With current defaults, customer sees: **8 990 kr**" \[Recalculate\]

The button re-runs the resolver against the binding's saved
defaults + the in-memory override edits. It does NOT save anything
— it's a sanity-check tool. Owner still has to click "Save
binding" to persist override changes.

### 21.6 Diagnostics integration

Two diagnostic checks land alongside this UI in Phase 4.2b
(PRODUCT_BINDING §18.4):

- `item_overrides_valid` (critical) — every key in
  `_configkit_item_price_overrides` resolves to a real, active
  library item that is reachable from the bound template's
  `allowed_sources`. Stale overrides for deleted items are
  flagged with a precise `fix_link` to this section.
- `item_overrides_disallowed` (warning) — overrides exist for
  items the binding's `allowed_sources` filter would otherwise
  exclude (PRODUCT_BINDING §19). The override still applies, but
  Diagnostics flags the drift so the owner can clear or
  re-allow.

---

## 22. Future enhancement — Apply to similar products

Out of scope for Phase 4.2b. Documented here so the data model
leaves room for it.

A "**Copy overrides to other products**" action will live in the
same Woo product ConfigKit tab once Phase 4.2b ships and owners
have real overrides to manage. The UX:

1. Owner clicks the action while on product A's binding.
2. A modal lists candidate products (filtered by family, by
   bound template, or by free search) with checkboxes.
3. On confirm, ConfigKit writes the same
   `_configkit_item_price_overrides` JSON to each selected
   product's post meta, preserving any per-product reasons.
4. A diff preview before write shows owners which products will
   gain / overwrite / merge override entries.

This is achievable without schema changes — `_configkit_item_price_overrides`
is per-product post meta and a bulk-apply utility is just a loop
over `update_post_meta`. The constraint is UX, not data model:
the modal needs careful empty-state copy ("no products eligible")
and a clear undo / audit trail.

Acceptance:

- Phase 4.2b ships with the per-product editor only.
- The bulk-apply enhancement gets its own chunk after the editor
  has lived with real owners for at least one production cycle.
- This section gets re-opened then with a concrete UX sketch and
  a deliverables checklist.
