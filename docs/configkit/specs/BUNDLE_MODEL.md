# ConfigKit Bundle Model

| Field          | Value                                                                  |
| -------------- | ---------------------------------------------------------------------- |
| Status         | DRAFT v1                                                               |
| Document type  | Bundle / composite library-item model                                  |
| Companion docs | PRICING_SOURCE_MODEL.md, MODULE_LIBRARY_MODEL.md, PRICING_CONTRACT.md, DATA_MODEL.md |
| Spec language  | English                                                                |
| Affects phases | Phase 4.2 (model + migration), Phase 5 (cart integration), Phase 6 (order display) |

---

## 1. Purpose

A customer-facing "option" sometimes corresponds to multiple Woo
products that need separate stock tracking and order accounting.
Real Sologtak example:

> **Somfy IO Premium Pakke** = 1× Somfy IO motor (SKU `MOT-IO-RS`) +
> 1× Telis 4 RTS remote (SKU `REM-T4-RTS`) + 1× Soliris sensor
> (SKU `SEN-SOL`) + 1× mounting kit (SKU `MNT-IO`).

The customer picks one option labelled "Somfy IO Premium Pakke" at
8 990 NOK. The warehouse has to pick four physical SKUs. Stock for
each SKU has to drop by one when an order ships.

Today's library-item model assumes a 1:1 mapping between a library
item and a Woo product (or no Woo product at all). Bundles formalise
N:1 — one library item, N Woo products.

---

## 2. Library item types

A new column `item_type` on `wp_configkit_library_items` encodes the
two shapes:

| Value           | Meaning                                                                                                            |
| --------------- | ------------------------------------------------------------------------------------------------------------------ |
| `simple_option` | Standalone option. May have `woo_product_id` or not. Default for new items and the only shape pre-Phase 4.2.       |
| `bundle`        | Composite. Carries a `bundle_components_json` array. The library item itself usually has no direct `woo_product_id`. |

Validation:

- `item_type = 'simple_option'` ignores `bundle_components_json` /
  `bundle_fixed_price` / `cart_behavior` / `admin_order_display`
  (they are NULL on save).
- `item_type = 'bundle'` requires `bundle_components_json` to be a
  JSON array with **at least one component**.
- Bundle items reject `price_source` values `configkit` and `woo`;
  the only legal sources are `bundle_sum` and `fixed_bundle`
  (see PRICING_SOURCE_MODEL §2).
- `fixed_bundle` requires a non-NULL `bundle_fixed_price`.

---

## 3. Bundle composition

`bundle_components_json` is a LONGTEXT column storing a JSON array.
Each element is an object describing one component of the bundle:

```json
[
  {
    "component_key": "motor",
    "woo_product_id": 123,
    "qty": 1,
    "price_source": "configkit",
    "price": 4500.00,
    "stock_behavior": "check_components",
    "label_in_cart": "Somfy IO motor"
  },
  {
    "component_key": "remote",
    "woo_product_id": 124,
    "qty": 1,
    "price_source": "woo",
    "price": null,
    "stock_behavior": "check_components",
    "label_in_cart": "Somfy Telis 4 RTS remote"
  }
]
```

Field semantics (one row = one component):

| Field            | Type                                  | Required | Notes                                                                  |
| ---------------- | ------------------------------------- | -------- | ---------------------------------------------------------------------- |
| `component_key`  | string, snake_case, ≤ 32 chars        | yes      | Stable identifier within the bundle. UNIQUE per bundle.                |
| `woo_product_id` | integer                               | yes      | The Woo product backing this component. v1 always Woo; see §10 q3.    |
| `qty`            | integer ≥ 1                           | yes      | Multiplied by cart quantity for stock and per-component pricing.       |
| `price_source`   | `'configkit'` / `'woo'` / `'fixed_bundle'` | yes  | Same enum as PRICING_SOURCE_MODEL §2. `bundle_*` is rejected here.    |
| `price`          | decimal ≥ 0 / null                    | conditional | Required when `price_source = 'configkit'`; forbidden otherwise.    |
| `stock_behavior` | `'ignore'` / `'check_components'`     | yes      | See §6.                                                                |
| `label_in_cart`  | string, ≤ 255 chars                   | optional | Falls back to the Woo product title.                                   |

Bundle-level validation (server-side, on item save):

- Component `component_key` set is unique within the bundle.
- Every `woo_product_id` resolves to a real, published Woo product
  (rejected if missing or status `trash`).
- `price`, when present, is a non-negative decimal.
- A bundle component cannot itself be a bundle (no recursion in v1
  — see §10 q1).

---

## 4. Bundle pricing

The bundle item's `price_source` (set on the library item, NOT on
each component) decides how the engine resolves the bundle's
contribution to the cart line:

| Item-level `price_source` | Resolution                                                                                  |
| ------------------------- | ------------------------------------------------------------------------------------------- |
| `bundle_sum`              | Walk components, sum `resolveComponentPrice( c ) × c.qty` for each. Component-level         |
|                           | sources are honoured: a `'woo'` component reads from Woo, a `'configkit'` component uses    |
|                           | `c.price`. The library item's own `price` is ignored.                                       |
| `fixed_bundle`            | Use `library_item.bundle_fixed_price`. Component prices are still computed for ledger       |
|                           | purposes (so `add_child_lines` cart_behaviour can split the price), but the bundle's        |
|                           | resolved cart contribution is the fixed number — the engine reconciles by allocating the    |
|                           | difference to the first component (or proportionally; see §10 q2).                          |

`product_override` works on bundles exactly the same as on simple
items: a binding-level override of the bundle's compound key
(`library_key:item_key`) replaces the item-level resolution
entirely.

---

## 5. Cart behavior

`cart_behavior` (column on the library item) decides how the bundle
appears in the WooCommerce cart object:

| Value                  | Effect                                                                                                                |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------- |
| `price_inside_main`    | The bundle's resolved price is rolled into the configurable product's cart line price. Components are NOT added       |
|                        | as separate cart lines. The cart shows one line per configurable product. **Default**.                                |
| `add_child_lines`      | The configurable product's cart line keeps its own resolved price; each bundle component becomes a separate cart      |
|                        | line item with its own `qty`, price, and SKU. The cart shows N+1 lines per configurable product.                      |

Owner-facing implications:

- `price_inside_main` keeps the cart visually clean and matches the
  customer's mental model ("I bought a markise"). Stock tracking
  still works because §6 is independent of this setting.
- `add_child_lines` matches what an ERP / accounting system expects
  when each component has its own SKU and revenue line — but the
  cart UX is busier and refunds become per-component.

The default is `price_inside_main`. Owners can flip per-bundle.

---

## 6. Stock behavior

Per-component `stock_behavior` controls Woo stock decrement when a
bundle is purchased:

| Value                | Effect                                                                                                  |
| -------------------- | ------------------------------------------------------------------------------------------------------- |
| `ignore`             | Don't touch this component's Woo product stock.                                                         |
| `check_components`   | Treat this component as if it were sold standalone: deduct `component.qty × cart_line_qty` from stock. |

Stock decrement runs on Woo's `woocommerce_reduce_order_stock` event
regardless of `cart_behavior`. The Phase 5 cart integration is
responsible for emitting the right Woo stock-reduction calls.

If any component with `stock_behavior = 'check_components'` is out
of stock with backorders disabled at add-to-cart time, the whole
bundle is rejected with a friendly error (`bundle.component_oos`).

---

## 7. Admin order display

`admin_order_display` (column on the library item) controls how the
order shows up in WooCommerce → Orders → single order:

| Value         | Effect                                                                                                |
| ------------- | ----------------------------------------------------------------------------------------------------- |
| `collapsed`   | Show only the bundle name as a single line (cart_behavior = `price_inside_main` matches naturally).  |
| `expanded`    | Show the bundle name as a header, then each component on its own row with SKU + qty + price.         |

Default is `expanded` so the warehouse can pick all components
without the order processor having to know what's inside each
bundle.

When `cart_behavior = 'add_child_lines'`, the cart already contains
the components as line items so `admin_order_display` is purely
cosmetic — the order will show component lines either way.

---

## 8. Worked examples

### 8.1 Somfy IO Premium Pakke (fixed-price bundle)

```
library_item:
  library_key             = motors_somfy
  item_key                = somfy_io_premium_pakke
  label                   = "Somfy IO Premium Pakke"
  item_type               = 'bundle'
  woo_product_id          = NULL
  price                   = NULL
  price_source            = 'fixed_bundle'
  bundle_fixed_price      = 8990.00
  cart_behavior           = 'price_inside_main'
  admin_order_display     = 'expanded'

  bundle_components_json  = [
    { component_key: 'motor',        woo_product_id: 123, qty: 1, price_source: 'configkit', price: 4500.00, stock_behavior: 'check_components', label_in_cart: 'Somfy IO motor' },
    { component_key: 'remote',       woo_product_id: 124, qty: 1, price_source: 'woo',       price: null,    stock_behavior: 'check_components', label_in_cart: 'Telis 4 RTS remote' },
    { component_key: 'sensor',       woo_product_id: 125, qty: 1, price_source: 'configkit', price: 1290.00, stock_behavior: 'check_components', label_in_cart: 'Soliris sensor' },
    { component_key: 'mounting_kit', woo_product_id: 126, qty: 1, price_source: 'configkit', price:  890.00, stock_behavior: 'check_components', label_in_cart: 'IO mounting kit' }
  ]

resolved (markise, qty 1) = { price: 8990, source: 'fixed_bundle' }
cart adds:                  1 line item (configurable product) with
                            +8990 rolled in; stock drops 1× per component.
admin order displays:       Bundle header + 4 component rows.
```

### 8.2 Manual crank (sum-of-components bundle)

```
library_item:
  library_key             = hand_systems
  item_key                = manual_crank_basic
  label                   = "Manual crank handle set"
  item_type               = 'bundle'
  price_source            = 'bundle_sum'
  bundle_fixed_price      = NULL
  cart_behavior           = 'price_inside_main'
  admin_order_display     = 'expanded'

  bundle_components_json  = [
    { component_key: 'handle',  woo_product_id: 200, qty: 1, price_source: 'woo',       price: null,   stock_behavior: 'check_components' },
    { component_key: 'rod',     woo_product_id: 201, qty: 1, price_source: 'configkit', price: 350.00, stock_behavior: 'check_components' },
    { component_key: 'bracket', woo_product_id: 202, qty: 2, price_source: 'configkit', price:  90.00, stock_behavior: 'check_components' }
  ]

priceProvider returns 200 → 480.00
resolved (markise, qty 1) = { price: 480 + 350 + 2*90 = 1010, source: 'bundle_sum' }
cart adds:                  1 line item with +1010 rolled in; stock drops
                            1×handle + 1×rod + 2×bracket.
```

---

## 9. Migration impact

`wp_configkit_library_items` gains four columns to support bundles
(plus the two from PRICING_SOURCE_MODEL §8 — total six new columns
land in the same migration):

```
ALTER TABLE wp_configkit_library_items
  ADD COLUMN item_type VARCHAR(32) NOT NULL DEFAULT 'simple_option'
    AFTER bundle_fixed_price,
  ADD COLUMN bundle_components_json LONGTEXT NULL
    AFTER item_type,
  ADD COLUMN cart_behavior VARCHAR(32) NULL DEFAULT 'price_inside_main'
    AFTER bundle_components_json,
  ADD COLUMN admin_order_display VARCHAR(32) NULL DEFAULT 'expanded'
    AFTER cart_behavior;

ALTER TABLE wp_configkit_library_items
  ADD KEY item_type ( item_type );
```

Backfill:

- All existing rows: `item_type = 'simple_option'`,
  `bundle_components_json = NULL`,
  `cart_behavior = NULL`,
  `admin_order_display = NULL`.
- The two `cart_*` / `admin_*` defaults only matter when an item
  flips to `'bundle'`; they stay NULL on simple items so it's clear
  the field is bundle-only.

Migration filename: `0017_extend_library_items_pricing_bundles.php`
(shared with PRICING_SOURCE_MODEL §8). Phase 4.2b authors the file.

---

## 10. Decisions locked

The five v1 questions raised in earlier drafts of this spec are
resolved by owner decision. Listed here so the spec is
self-contained and Phase 4.2b implementers do not need to chase
prior conversations.

1. **Bundle recursion.** No recursion. Bundles are exactly one
   level deep — `bundle_components_json[].woo_product_id` always
   refers to a Woo product, never to another ConfigKit library
   item or another bundle. `LibraryItemService` rejects recursive
   nests with code `bundle.recursion_forbidden`.
2. **`fixed_bundle` reconciliation across child cart lines.**
   When `price_source = 'fixed_bundle'` AND `cart_behavior =
   'add_child_lines'`, the difference between the per-component
   sum and `bundle_fixed_price` is distributed proportionally
   across components based on each component's resolved
   contribution, with banker's rounding (option (b) from the
   prior draft). Phase 4.2b unit tests lock this behaviour.
3. **Non-Woo components.** `bundle_components_json[].woo_product_id`
   is required in v1. Components MUST reference a real Woo
   product. ConfigKit-only "free sample" components are out of
   scope for Phase 4.2 — owners handle that via field-level rules
   or the existing `is_required = false` mechanic.
4. **Component qty > 1 stock semantics.** Standard Woo rule
   applies: the stock check uses the component's effective qty
   (`component.qty × cart_line_qty`). The check only blocks the
   add-to-cart when Woo stock management is enabled for that
   component's product (decision 7).
5. **Refunds / partial refunds.** Out of scope for Phase 4.2.
   When `cart_behavior = 'price_inside_main'` the order is a
   single bundle line; refund the whole line. Owners who need
   per-component refund granularity should choose `cart_behavior
   = 'add_child_lines'` at item creation time.

Three further owner-locked decisions inherited from
PRICING_SOURCE_MODEL §9:

6. **Cart default.** `cart_behavior = 'price_inside_main'`
   (default for new bundle items; toggle per item).
7. **Stock check default.** Per-component `stock_behavior`
   defaults to `check_components`, but the runtime check only
   blocks when Woo stock management is enabled for the component
   product. Component products with stock management off do not
   block the order.
8. **Admin order default.** `admin_order_display = 'expanded'`
   (warehouse always sees component lines unless owner opts
   into `'collapsed'`).

This spec is APPROVED for Phase 4.2b implementation. Future
modifications require a new spec version and explicit re-approval.

---

## 11. UI labels

Every UI surface that exposes the bundle model MUST cite
`UI_LABELS_MAPPING.md` for label, helper, and helper-text copy.
In particular:

- `UI_LABELS_MAPPING.md §3` — the `item_type` selector ("Single
  option" default vs "Package (multiple products combined)").
- `UI_LABELS_MAPPING.md §4` — the bundle composition editor
  (component picker, qty, price source, optional cart label,
  resolved-price column).
- `UI_LABELS_MAPPING.md §5` — `cart_behavior` radio pair labels
  ("Show customer one configured product line" /  "Show each
  component as a separate cart line").
- `UI_LABELS_MAPPING.md §6` — per-component stock-behaviour
  toggle ("Check stock for this component") with the persistent
  Woo-stock-management helper.
- `UI_LABELS_MAPPING.md §7` — `admin_order_display` toggle.
- `UI_LABELS_MAPPING.md §9.2` — the "Package breakdown preview"
  panel beneath the components editor.

Backend terms (`simple_option`, `bundle`, `bundle_sum`,
`fixed_bundle`, `price_inside_main`, `add_child_lines`,
`check_components`, `expanded`, `collapsed`) are forbidden as
primary UI labels per `UI_LABELS_MAPPING.md §11`. Implementation
MUST cite this mapping when rendering any bundle-related UI;
reviewers reject PRs that expose enum values as labels.
