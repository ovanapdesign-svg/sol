# ConfigKit UI Labels Mapping

| Field          | Value                                                                  |
| -------------- | ---------------------------------------------------------------------- |
| Status         | DRAFT v1                                                               |
| Document type  | UX guardrail — backend term ↔ owner UI label mapping                  |
| Companion docs | PRICING_SOURCE_MODEL.md, BUNDLE_MODEL.md, PRODUCT_BINDING_SPEC.md, MODULE_LIBRARY_MODEL.md |
| Spec language  | English                                                                |
| Affects phases | Phase 4.2 (every UI surface that exposes the new model)                |

---

## 1. Purpose

ConfigKit's backend uses technical terms (`price_source`,
`item_type`, `bundle_sum`, `cart_behavior`, etc.) for clarity in
code, the database, REST payloads, and JSON. The owner-facing admin
UI must NEVER expose those terms as primary labels. This document
maps every backend term introduced by Phase 4.2 to its
owner-friendly label.

This is the Phase 3.6 lesson made structural: instead of
remembering to soften labels in each pull request, every
implementation chunk MUST cite this document for every UI element
it ships. A reviewer who finds a raw enum value or snake_case
identifier on a primary label rejects the PR.

Cross-reference matrix for implementation:

- **Library item form** → §3 (item type), §4 (bundle composition),
  §5 (cart behavior), §6 (stock), §7 (admin order display), §9.1
  (resolved-price preview).
- **Bundle composition editor** → §4 (component fields), §9.2
  (package breakdown preview).
- **Woo product ConfigKit tab → Pricing overrides** → §8
  (per-item overrides), §9.3 (test-default-config preview).
- **Order admin display** → §7 (expanded vs collapsed view).

---

## 2. Mapping table — Pricing source

The five-value `price_source` enum (PRICING_SOURCE_MODEL §2)
appears as a single dropdown on the library item form. The
dropdown shows owner-friendly labels; the saved value is the
technical enum.

| Backend term                       | Owner UI label                                       |
| ---------------------------------- | ---------------------------------------------------- |
| `price_source = 'configkit'`       | "Use price entered in ConfigKit"                     |
| `price_source = 'woo'`             | "Use WooCommerce product price"                      |
| `price_source = 'product_override'`| "Use special price for this Woo product"             |
| `price_source = 'bundle_sum'`      | "Calculate package price from components"            |
| `price_source = 'fixed_bundle'`    | "Use fixed package price"                            |

Helper text (rendered as a `description` paragraph beneath the
dropdown when each value is selected):

| Selected value      | Helper text                                                                                                                                |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| `configkit`         | "Set the price directly in this library item."                                                                                              |
| `woo`               | "Read the price from the linked WooCommerce product. The price freezes when customers add to cart."                                         |
| `product_override`  | "Specific Woo products can override this price in their ConfigKit tab."                                                                     |
| `bundle_sum`        | "Sum the prices of each component (each component uses its own price source)."                                                              |
| `fixed_bundle`      | "Set a fixed price for the whole package, regardless of component prices."                                                                  |

The dropdown is **conditionally filtered** by `item_type`: simple
items see only `configkit` / `woo` / `product_override`; bundle
items see only `bundle_sum` / `fixed_bundle` (per
PRICING_SOURCE_MODEL §2 validation). The `product_override` value
is read-only on the library item form — it appears only when a
product binding has applied an override (see §8).

---

## 3. Mapping table — Item type

| Backend term                  | Owner UI label                                      |
| ----------------------------- | --------------------------------------------------- |
| `item_type = 'simple_option'` | (no explicit label — see rendering rule below)      |
| `item_type = 'bundle'`        | "Package (multiple products combined)"              |

UI rendering rule:

- The library item form shows a two-card / radio-pair selector at
  the top:

  ```
  ○ Single option   (default — one library item, optional Woo link)
  ○ Package         (combines multiple Woo products into one selection)
  ```

- The "Single option" card is selected by default and bundle
  fields stay hidden. The owner never sees the string
  `simple_option` anywhere — the form just does not show bundle
  fields.
- Selecting "Package" reveals the bundle composition editor (§4),
  the bundle-only `cart_behavior` toggle (§5), and the
  `admin_order_display` toggle (§7).

---

## 4. Mapping table — Bundle composition

The bundle composition editor replaces the raw
`bundle_components_json` field. Owners NEVER see JSON.

| Backend field                   | Owner UI label                                          |
| ------------------------------- | ------------------------------------------------------- |
| `bundle_components_json`        | "Package contents"                                      |
| `component.woo_product_id`      | "WooCommerce product" (with search-by-name picker)      |
| `component.qty`                 | "Quantity"                                              |
| `component.price_source`        | "Price source for this component"                       |
| `component.price`               | "Price (kr)" — only when component price source is "Use price entered in ConfigKit" |
| `component.stock_behavior`      | "Check stock for this component" (toggle — see §6)      |
| `component.label_in_cart`       | "Display name in cart" (optional)                       |
| `bundle_fixed_price`            | "Fixed package price (kr)" — only when item price source is "Use fixed package price" |

UI rendering rule for components:

- Components appear as a vertical list of rows.
- Each row layout:

  ```
  [WooCommerce product picker]   [Qty −/+]   [Price source ▾]   [Resolved: 1 290 kr]   [×]
  └ "Display name in cart" (optional small input below the row)
  ```

- A right-aligned "Resolved" preview shows the live computed
  contribution for that component (see §9.2 for the full
  package-breakdown preview that aggregates them).
- "+ Add component" button at the bottom of the list.
- `component_key` is generated automatically from the picked Woo
  product (slugified product name with a numeric suffix on
  collision). Owner never enters `component_key`.

---

## 5. Mapping table — Cart behavior

| Backend term                          | Owner UI label                                                       |
| ------------------------------------- | -------------------------------------------------------------------- |
| `cart_behavior = 'price_inside_main'` | "Show customer one configured product line"                          |
| `cart_behavior = 'add_child_lines'`   | "Show each component as a separate cart line"                        |

Helper text under the radio pair:

| Selected value          | Helper text                                                                                                                                  |
| ----------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `price_inside_main`     | "Customer sees one cart line for the whole configuration. Components still appear in the admin order breakdown."                              |
| `add_child_lines`       | "Customer sees the main product plus each component as separate cart lines."                                                                  |

Default: `price_inside_main`.

---

## 6. Mapping table — Stock behavior

| Backend term                              | Owner UI label                                              |
| ----------------------------------------- | ----------------------------------------------------------- |
| `stock_behavior = 'check_components'`     | "Check stock for WooCommerce components"                    |
| `stock_behavior = 'ignore'`               | "Do not check component stock"                              |

UI rendering rule:

- Per-component toggle (`component.stock_behavior`) shown next to
  each row in §4 as "Check stock for this component" (defaulted
  on).
- A persistent helper sits above the components list:

  > "Stock is checked only when WooCommerce stock management is
  > enabled for that component product. If a component has stock
  > management off, its stock is not blocking — the order
  > proceeds."

Default per component: `check_components`. The toggle flips it to
`ignore` per component when the owner explicitly opts out.

---

## 7. Mapping table — Admin order display

| Backend term                       | Owner UI label                                       |
| ---------------------------------- | ---------------------------------------------------- |
| `admin_order_display = 'expanded'` | "Show package components in admin order"             |
| `admin_order_display = 'collapsed'`| "Show only package name in admin order"              |

Default: `expanded`. Owner toggles per item to `collapsed` if
they prefer cleaner orders.

---

## 8. Mapping table — Product binding overrides

In the Woo product ConfigKit tab (PRODUCT_BINDING_SPEC §18 / §21),
the "Item price overrides for this product" section uses these
labels.

| Backend field                       | Owner UI label                              |
| ----------------------------------- | ------------------------------------------- |
| `_configkit_item_price_overrides`   | "Item price overrides for this product"    |
| key (`library_key:item_key`)        | "Item" (rendered as picker showing item label, not the key) |
| `override.price`                    | "Override price (kr)"                       |
| `override.price_source`             | (hidden — always `product_override` on save; never shown) |
| `override.reason`                   | "Reason / note (internal)"                  |
| (resolved final price)              | "Customer sees" — read-only, see §9.3       |

UI rendering rule:

- Section title: **Item price overrides for this product**.
- Table columns: **Item | Original price | Override price | Note |
  Action**.
- The Item column shows the library item's display label and a
  small `↗` link to that item's edit screen (Phase 4.1
  cross-linking). The technical key never appears here as a label
  — it is only visible inside the Item picker as a faint helper
  next to the name.
- "+ Add price override" button below the table launches an
  inline picker (searchable dropdown of every library item
  reachable from the bound template's `allowed_sources`).
- Each row also shows the **Original price** (resolved per the
  library item's own source) so the owner can see what the
  override is replacing.
- Empty / cleared `Override price` cell on save = remove the
  entry (PRODUCT_BINDING §21).

---

## 9. Required preview panels

To make pricing transparent, three preview panels MUST appear in
the admin UI. Each computes the resolved value live from the
current form inputs without requiring a save.

### 9.1 Library item edit screen — "Resolved price preview"

Position: directly below the price-source dropdown (§2).

Render:

> "If a customer picks this item now, the price will be: **1 290 kr**"

Updates live when:

- `price_source` changes
- `price` / `bundle_fixed_price` field changes
- A bundle component is added / removed / edited

When the library item carries `price_source = 'woo'`, the preview
fetches the current Woo product price via the `PriceProvider` and
shows it with a small note: "(read from WooCommerce — frozen at
add-to-cart)".

When `price_source = 'product_override'` is theoretically active
on a library item (it never is — overrides live on bindings), the
preview shows the configkit fallback with a note: "Per-product
overrides apply only on specific Woo products."

### 9.2 Bundle edit screen — "Package breakdown preview"

Position: directly below the components editor (§4).

Render:

```
Package breakdown
─────────────────────────────────────────────────────────
Component               Qty   Source         Resolved   Subtotal
─────────────────────────────────────────────────────────
Somfy IO motor          1     ConfigKit      4 500 kr   4 500 kr
Telis 4 RTS remote      1     WooCommerce    1 490 kr   1 490 kr
Soliris sensor          1     ConfigKit      1 290 kr   1 290 kr
IO mounting kit         1     ConfigKit        890 kr     890 kr
─────────────────────────────────────────────────────────
Total                                                   8 170 kr
```

When `price_source = 'fixed_bundle'`, the totals row is replaced
with:

> Total: **Fixed at 8 990 kr** (component prices shown above are
> for stock and order-line accounting only)

Updates live as components / quantities / price sources change.

### 9.3 Woo product ConfigKit tab — "Test default configuration price"

Position: appended to the Pricing overrides section (between the
override table and the preview placeholder).

Render:

> "With current defaults, customer sees: **8 990 kr**" \[Recalculate\]

The button re-runs the snapshot computation against the current
binding state (defaults + overrides + `pricing_overrides`). The
preview is read-only; saving the binding is still required for
the customer to see anything new.

---

## 10. Technical key visibility

Backend keys (`item_key`, `library_key`, `module_key`,
`template_key`, etc.) stay in their existing "Technical key"
labelled fields per Phase 3.6. They do NOT need new labels —
Phase 3.6's rename pass already handled this.

For the new Phase 4.2 fields nothing becomes a Technical key:
`price_source` / `item_type` / `cart_behavior` /
`admin_order_display` / `stock_behavior` are all dropdowns or
toggles that owners pick by label, not keys they enter.
`component_key` is generated automatically (§4) and never shown
as a primary label.

---

## 11. Forbidden patterns

The owner-facing UI MUST NEVER show:

- Raw enum values like `configkit`, `woo`, `bundle_sum`,
  `fixed_bundle`, `simple_option`, `bundle`, `price_inside_main`,
  `add_child_lines`, `check_components`, `expanded`, `collapsed`.
- JSON blobs. `bundle_components_json` MUST be edited via the
  component editor in §4. There is no "raw JSON" toggle. Same for
  `_configkit_item_price_overrides` (§8 table is the only editor).
- Database column names (`woo_product_id`, `bundle_fixed_price`,
  `price_source`) as labels.
- snake_case identifiers in headings, primary labels, or button
  text. snake_case is allowed inside tooltips / monospaced
  helper text only when explaining a Technical key (Phase 3.6
  carve-out).

If a future chunk introduces a new technical concept, the
implementer MUST add the UI label mapping to this document
**before** the implementation lands. Reviewers will reject any PR
that introduces a new enum / column without an entry here.
