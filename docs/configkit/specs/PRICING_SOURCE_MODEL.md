# ConfigKit Pricing Source Model

| Field          | Value                                                                  |
| -------------- | ---------------------------------------------------------------------- |
| Status         | DRAFT v1                                                               |
| Document type  | Pricing-source resolution model                                        |
| Companion docs | PRICING_CONTRACT.md, MODULE_LIBRARY_MODEL.md, PRODUCT_BINDING_SPEC.md, BUNDLE_MODEL.md, DATA_MODEL.md |
| Spec language  | English                                                                |
| Affects phases | Phase 4.2 (model + migration), Phase 5 (cart pricing parity)           |

---

## 1. Purpose

ConfigKit pricing is not always tied to the WooCommerce product price.
The Sologtak migration target has four real shapes that the engine
must support:

1. **Pure ConfigKit option** — no Woo product (e.g. fabric color).
2. **Linked Woo product, ConfigKit price wins** — owner sets a price
   on the library item that overrides Woo's stored price.
3. **Linked Woo product, Woo price wins** — library item has no
   ConfigKit price; engine fetches `wc_get_product()->get_price()`
   at render / cart time.
4. **Linked Woo product, per-product binding override** — the same
   item costs different amounts on different ConfigKit-bound Woo
   products.

This document defines the `price_source` field that distinguishes
these cases, the resolution algorithm the engine follows, and the
adapter contract that keeps `PricingEngine` pure (no `$wpdb`, no
`wc_get_product()`).

---

## 2. Price source enum

`library_item.price_source` is a VARCHAR(32) column on
`wp_configkit_library_items` with five permitted values:

| Value              | Meaning                                                                                                     |
| ------------------ | ----------------------------------------------------------------------------------------------------------- |
| `configkit`        | Use `library_item.price`. Default for new items.                                                            |
| `woo`              | Fetch from Woo product (`woo_product_id`) at render / cart time. `library_item.price` MUST be NULL.         |
| `product_override` | A per-product-binding override wins. Set automatically when the engine resolves an override; never a default. |
| `bundle_sum`       | This item is a `bundle` (see `BUNDLE_MODEL.md §2`); resolved price is the sum of its components.            |
| `fixed_bundle`     | This item is a `bundle`; resolved price is `library_item.bundle_fixed_price`.                               |

Validation (server-side, on item create / update):

- `bundle_sum` and `fixed_bundle` are valid only when
  `item_type = 'bundle'`.
- `fixed_bundle` requires `bundle_fixed_price IS NOT NULL`.
- `woo` requires `woo_product_id IS NOT NULL` and forbids a
  non-NULL `library_item.price` (so the source of truth is unambiguous).
- `configkit` is the only legal default for `item_type = 'simple_option'`.
- `product_override` is **not** writable on a library item — it is
  produced by the resolver when a binding-level override matches.

---

## 3. Resolution rules

Given a library item plus the currently bound Woo product, the
pricing engine walks a single deterministic ladder:

```
1. binding override hit?
   → returns { price: <override>, source: 'product_override' }

2. else, item.price_source switches:
   case 'configkit':
       price = item.price             (NULL → 0.00, log warning)
       source = 'configkit'
   case 'woo':
       price = priceProvider.fetchWooProductPrice( item.woo_product_id )
       source = 'woo'
       (NULL → engine emits 'pricing.woo_product_unavailable' warning,
        falls back to 0.00 so the cart doesn't break;
        DiagnosticsService surfaces this as a critical issue.)
   case 'fixed_bundle':
       price = item.bundle_fixed_price
       source = 'fixed_bundle'
   case 'bundle_sum':
       price = sum( resolve(component) for each component )
       source = 'bundle_sum'

3. Apply sale_mode + product_surcharge + discount_percent + minimum
   floor as already specified in PRICING_CONTRACT.md §9 / §12 / §13
   on top of the resolved base.
```

The ladder runs once per item per render. The engine does not retry
or fall through — if step 2 yields NULL, the warning fires and the
fallback price is 0.00.

For bundle items the resolver walks each component's
`price_source` independently and sums the results. Bundles are
single-level only — components MUST be simple Woo products, never
other bundles or other ConfigKit library items
(BUNDLE_MODEL §10 decision 1). `LibraryItemService` rejects
recursive nests with code `bundle.recursion_forbidden` at save
time, so the resolver never has to defend against runaway depth.

---

## 4. Where prices live

| Field                                 | Lives in                                                                          |
| ------------------------------------- | --------------------------------------------------------------------------------- |
| `library_item.price`                  | `wp_configkit_library_items.price` (DECIMAL(12,2) NULL)                           |
| `library_item.price_source`           | `wp_configkit_library_items.price_source` (VARCHAR(32), DEFAULT `configkit`)      |
| `library_item.woo_product_id`         | `wp_configkit_library_items.woo_product_id` (BIGINT UNSIGNED NULL)                |
| `library_item.bundle_fixed_price`     | `wp_configkit_library_items.bundle_fixed_price` (DECIMAL(12,2) NULL)              |
| `library_item.bundle_components_json` | `wp_configkit_library_items.bundle_components_json` (LONGTEXT NULL)               |
| `product_override.item_price`         | Post meta `_configkit_item_price_overrides` on the Woo product (JSON keyed map)   |
| `woo_product.price`                   | WooCommerce's own `_price` post meta (read via `wc_get_product`)                  |
| `library_item.sale_price`             | `wp_configkit_library_items.sale_price` (DECIMAL(12,2) NULL) — see PRICING §9     |

---

## 5. Engine purity — `PriceProvider` adapter

`PricingEngine` has been pure since Phase 2 (zero `$wpdb` / WP /
Woo references). To preserve that, the `woo` price-source path is
served through a constructor-injected adapter:

```php
namespace ConfigKit\Pricing;

interface PriceProvider {
    /**
     * @return float|null  Resolved price in store currency (whatever
     *                     unit Woo is configured to store — see §6),
     *                     or null if the product is unavailable / out
     *                     of stock with backorders disabled.
     */
    public function fetchWooProductPrice( int $woo_product_id ): ?float;
}
```

Production binding lives outside `src/Engines/` (e.g.
`src/Frontend/WooPriceProvider.php`) and may call `wc_get_product()`,
`wc_prices_include_tax()`, etc. as needed. Tests inject a stub that
returns a hash-mapped fixed value per id:

```php
$stub = new class implements PriceProvider {
    public function fetchWooProductPrice( int $id ): ?float {
        return [ 123 => 5200.0, 124 => 1490.0 ][ $id ] ?? null;
    }
};
```

`grep -rE "wp_|get_option|WP_Query|\$wpdb|wc_get_product" src/Engines/`
must continue to return zero matches after this work lands.

---

## 6. VAT handling

VAT semantics flow through the resolver unchanged from PRICING §11.
The resolved base price's tax class is:

| Source path        | Tax class                                                                                                                   |
| ------------------ | --------------------------------------------------------------------------------------------------------------------------- |
| `configkit`        | Treated as ex-VAT or inc-VAT according to **Settings → ConfigKit → VAT mode** (currently the only ConfigKit-side VAT toggle). |
| `woo`              | Inherits Woo's `wc_prices_include_tax()` configuration. The adapter is responsible for returning a price in that mode.      |
| `fixed_bundle`     | Same as `configkit`.                                                                                                        |
| `bundle_sum`       | Each component contributes per its own source rule; the sum inherits whichever mode dominates. If components mix modes the engine emits `pricing.vat_mode_mismatch` and uses ConfigKit mode. |
| `product_override` | Always treated as the dominant mode (ConfigKit's VAT setting).                                                              |

The binding's `vat_display` (`incl_vat` / `excl_vat` / `off` /
`use_global`) only affects how the customer sees the number — it
does not change the stored price. See PRICING §11 + PRODUCT_BINDING
§8.5.

---

## 7. Examples

### 7.1 Pure ConfigKit color (no Woo product)

```
library_item:
  library_key      = textiles_dickson
  item_key         = orchestra_max_blue
  woo_product_id   = NULL
  price            = 0
  sale_price       = NULL
  price_source     = 'configkit'

resolved = { price: 0, source: 'configkit' }
```

The price contribution is zero — color picks affect rule output and
maybe pricing modes (`per_m2`) but the item itself has no own price.

### 7.2 Woo motor with ConfigKit price overriding Woo's

```
woo_product (id=123):  _price = 5200.00 (NOK)

library_item:
  library_key      = motors_somfy
  item_key         = somfy_io_premium
  woo_product_id   = 123
  price            = 4500.00
  sale_price       = NULL
  price_source     = 'configkit'   ← ConfigKit price wins

resolved = { price: 4500, source: 'configkit' }
```

Owner sets the ConfigKit price intentionally — perhaps because the
markise-bundle context warrants a contract price. Woo's price
remains 5200 for direct-product purchases.

### 7.3 Woo motor with Woo price (read-through)

```
woo_product (id=123):  _price = 5200.00

library_item:
  library_key      = motors_somfy
  item_key         = somfy_io_basic
  woo_product_id   = 123
  price            = NULL
  price_source     = 'woo'

priceProvider.fetchWooProductPrice(123) = 5200.00
resolved = { price: 5200, source: 'woo' }
```

Library item has no ConfigKit price; engine fetches Woo's at run
time. Owner can change Woo's price and the markise reflects it on
next render without re-saving the library item.

### 7.4 Per-product binding override

```
woo_product binding (markise X):
  _configkit_item_price_overrides = {
    "motors_somfy:somfy_io_premium": {
      "price": 4200.00,
      "reason": "Volume discount agreement"
    }
  }

library_item (motors_somfy:somfy_io_premium):
  price        = 4500.00
  price_source = 'configkit'

resolved (markise X) = { price: 4200, source: 'product_override' }
resolved (markise Y) = { price: 4500, source: 'configkit' }
```

The override is stored on the Woo product post meta, not on the
library item. Same item costs different amounts on different
configurable products. See PRODUCT_BINDING §18.

---

## 8. Migration impact

`wp_configkit_library_items` gains two columns to support this model
(plus four more for bundles — see BUNDLE_MODEL §9):

```
ALTER TABLE wp_configkit_library_items
  ADD COLUMN price_source VARCHAR(32) NOT NULL DEFAULT 'configkit'
    AFTER price,
  ADD COLUMN bundle_fixed_price DECIMAL(12,2) NULL
    AFTER price_source;
```

Backfill:

- All existing rows: `price_source = 'configkit'`,
  `bundle_fixed_price = NULL`.
- No existing rows are bundles yet, so no other backfill is needed.

Migration filename: `0017_extend_library_items_pricing_bundles.php`
(Phase 4.2b authors the actual migration; Phase 4.2a is specs only).

---

## 9. Decisions locked

The five v1 questions raised in earlier drafts of this spec are
resolved by owner decision. They are reproduced here so the spec
is self-contained and Phase 4.2b implementers do not need to
chase prior conversations.

1. **Override scope.** Per-product binding only. There is no
   family-level price override surface in v1.
2. **Woo price freeze.** When `price_source = 'woo'`, the resolved
   price is **snapshot at add-to-cart / order creation** and
   stored on the cart line meta. Subsequent Woo price changes do
   not retro-affect open carts or completed orders.
3. **Bundle recursion.** No recursion. Bundles are exactly one
   level deep — components are simple Woo products only. Any
   attempt to nest a bundle inside another bundle is rejected by
   `LibraryItemService`'s validator with code
   `bundle.recursion_forbidden`.
4. **`bundle_sum` with mixed component price sources.** Each
   component resolves its own `price_source` independently
   (`configkit` → `component.price`, `woo` → `PriceProvider`,
   `fixed_bundle` not allowed at component level), then the
   bundle total is the sum. No source coercion across components.
5. **Negative prices.** Clamp to 0 at the line level (PRICING §16
   already mandates this). The clamp applies to the post-resolution
   line price, not to individual bundle components — a component
   whose resolved price is negative still feeds into the bundle
   sum negative; the line's final clamp is what guards the cart.

Two further owner-locked decisions that this spec inherits from
BUNDLE_MODEL §10 (also resolved):

6. **Cart default.** `cart_behavior = 'price_inside_main'` is the
   default for new bundle items; owner toggles per item.
7. **Stock check default.** Per-component `stock_behavior` defaults
   to `check_components`, but the runtime check only blocks when
   Woo stock management is enabled for the component product. If
   Woo stock management is off for a component, the order is not
   blocked regardless of the toggle.
8. **Admin order default.** `admin_order_display = 'expanded'` is
   the default — warehouse always sees component lines unless the
   owner explicitly opts into `'collapsed'`.
9. **Migration backfill (DATA_MODEL §3.3).** Existing rows
   backfill to `price_source = 'configkit'`,
   `item_type = 'simple_option'`, and all bundle-only fields NULL.
   No behavioural change ships with the schema change.

This spec is APPROVED for Phase 4.2b implementation. Future
modifications require a new spec version and an explicit
re-approval — Phase 4.2b implementers should NOT re-open these
decisions.

---

## 10. UI labels

Every UI surface that exposes the model defined in this spec MUST
cite `UI_LABELS_MAPPING.md` for label, helper, and helper-text
copy. In particular:

- `UI_LABELS_MAPPING.md §2` — the `price_source` dropdown labels
  and per-value helper text.
- `UI_LABELS_MAPPING.md §9.1` — the "Resolved price preview" panel
  on the library item edit screen.

Backend terms (`configkit`, `woo`, `product_override`, `bundle_sum`,
`fixed_bundle`) are forbidden as primary UI labels per
`UI_LABELS_MAPPING.md §11`. Implementation MUST cite this mapping
when rendering any pricing-source UI; reviewers reject PRs that
expose enum values as labels.
