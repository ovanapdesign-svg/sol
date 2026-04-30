# ConfigKit Pricing Contract

| Field            | Value                                       |
|------------------|---------------------------------------------|
| Status           | DRAFT v1                                    |
| Document type    | Engine contract specification               |
| Companion docs   | TARGET_ARCHITECTURE.md, FIELD_MODEL.md, RULE_ENGINE_CONTRACT.md |
| Spec language    | English                                     |
| Affects phases   | Phase 2 (engines), Phase 5 (cart/order)     |

---

## 1. Purpose

The Pricing Engine computes the final price of a configured product. It is
the **single source of truth** for prices written to cart and order item meta.

The engine runs:

- **Server-side (PHP)** — authoritative. Result is written to cart_item_meta
  and order_item_meta. Re-runs at every cart event (qty change, coupon, etc.).
- **Client-side (JS)** — preview only. UX live price. Server result overrides.

Mismatches between client and server prices are logged as
`pricing.frontend_server_mismatch` and do not block add-to-cart — the server
result wins silently.

---

## 2. Inputs

The engine receives:

```json
{
  "template_key": "markise_motorized_v1",
  "template_version_id": 42,
  "fields_state": { ... },
  "rule_results": { ... },
  "lookup_table_key": "markise_2d_v1",
  "selections": {
    "width_mm": 4000,
    "height_mm": 3000,
    "profile_color": "ral_9010",
    "fabric_color": "textiles_dickson:u171",
    "control_type": "motorized",
    "sensor_addon": ["SOM-IO-WIND-300"]
  },
  "config": {
    "currency": "NOK",
    "vat_mode": "incl_vat",
    "rounding": "round_half_up",
    "rounding_step": 1.00
  }
}
```

`fields_state` and `rule_results` come from the Rule Engine (RULE_ENGINE_CONTRACT.md §6.2).
`selections` is the customer's input.

---

## 3. Output — price breakdown

```json
{
  "currency": "NOK",
  "total": 14380.00,
  "lines": [
    {
      "type": "base",
      "label": "Grunnpris etter mål",
      "amount": 8900.00,
      "source": {
        "kind": "lookup_table",
        "lookup_table_key": "markise_2d_v1",
        "matched_cell": { "width": 4000, "height": 3000, "price_group_key": "" }
      }
    },
    {
      "type": "option",
      "label": "Dukgruppe II",
      "field_key": "fabric_color",
      "amount": 1200.00,
      "source": {
        "kind": "library_item_price_group",
        "library_key": "textiles_dickson",
        "item_key": "u171",
        "price_group_key": "II"
      }
    },
    {
      "type": "addon",
      "label": "Somfy IO Wind Sensor",
      "field_key": "sensor_addon",
      "amount": 1790.00,
      "source": {
        "kind": "woo_product",
        "sku": "SOM-IO-WIND-300",
        "product_id": 12345
      }
    },
    {
      "type": "surcharge",
      "label": "Motor (Somfy)",
      "field_key": "control_type",
      "amount": 2490.00,
      "source": {
        "kind": "field_pricing",
        "pricing_mode": "fixed"
      }
    }
  ],
  "vat": {
    "mode": "incl_vat",
    "rate_percent": 25,
    "amount_included": 2876.00
  },
  "warnings": [],
  "blocked": false,
  "block_reason": null
}
```

This breakdown is stored in cart item meta as `_configkit_price_breakdown_json`
and copied to order item meta at checkout.

---

## 4. Pricing modes per field

A field's `pricing_mode` (FIELD_MODEL.md §9 / DATA_MODEL.md §3.8) determines
how its selection contributes to the total.

| pricing_mode       | Meaning                                                |
|--------------------|--------------------------------------------------------|
| `none`             | Field has no price impact                              |
| `fixed`            | Fixed amount per selection (additive)                  |
| `per_unit`         | Amount × number of selections (for checkbox)           |
| `per_m2`           | Amount × (width × height) / 1,000,000 (mm to m²)       |
| `lookup_dimension` | Field is a lookup input; price comes from lookup table |

---

## 5. Formula

```
total = base_price
      + sum(option_surcharges)
      + sum(addon_prices)
      + sum(rule_surcharges)
      + sum(library_item_price_group_surcharges)
      − discounts (Phase 6)

base_price        = lookup_table_price(width, height, price_group_key)
                    OR static base from product (if no lookup table)
option_surcharges = sum over visible fields with pricing_mode in (fixed, per_unit, per_m2)
addon_prices      = sum of Woo product prices for selected addon SKUs
rule_surcharges   = sum of add_surcharge actions from rule results
```

`price_group_key` — if a selected library item has a `price_group_key`, that
key is used as the third dimension of the lookup. If the lookup table doesn't
support price groups (`supports_price_group = 0`), the price_group_key is
ignored.

---

## 6. Visibility filter (hidden fields excluded)

Hidden fields contribute zero. The engine reads `fields_state` from the Rule
Engine output:

```php
foreach ( $selections as $field_key => $value ) {
    if ( ! ( $fields_state[ $field_key ]['visible'] ?? true ) ) {
        continue; // hidden field, skip
    }
    // ... add to breakdown
}
```

A field with `visible = false` MUST contribute zero even if it has a
non-empty value in `selections`. This prevents stale selections from
inflating the price after a rule hides a field.

---

## 7. Lookup matching

The Lookup Engine handles dimension → price resolution. The Pricing Engine
calls it with `(lookup_table_key, width, height, price_group_key)` and
receives:

```json
{
  "matched": true,
  "cell_id": 1234,
  "width": 4000,
  "height": 3000,
  "price_group_key": "",
  "price": 8900.00,
  "match_strategy": "exact"
}
```

If `matched = false`:

```json
{
  "matched": false,
  "reason": "no_cell",
  "requested": { "width": 4000, "height": 3000, "price_group_key": "" }
}
```

Pricing Engine response when no match:

- `total` = 0
- `blocked` = true
- `block_reason` = "Denne kombinasjonen mangler pris. Kontakt oss."

The `block_reason` is shown to the customer. Add-to-cart is disabled.

---

## 8. Match strategies

Lookup table's `match_mode` column (DATA_MODEL.md §3.11) controls how the
engine resolves a requested width/height:

| match_mode  | Semantics                                                |
|-------------|----------------------------------------------------------|
| `exact`     | Width and height must be exact cell values; else no match|
| `round_up`  | Use the smallest cell ≥ requested dimensions             |
| `nearest`   | Use the cell with the smallest absolute distance         |

`round_up` is the default for Sologtak (matches industry practice — never
sell a smaller frame than ordered).

If `match_mode = round_up` and the requested width/height exceed all
available cells, the engine returns `matched = false` with reason
`exceeds_max_dimensions`.

---

## 9. Sale price precedence

Library items can carry both `price` and `sale_price`. When `sale_price` is
non-null, the engine uses it. The breakdown's source object includes both:

```json
{
  "type": "option",
  "label": "Dickson U171 (på tilbud)",
  "amount": 980.00,
  "source": {
    "kind": "library_item_price",
    "library_key": "textiles_dickson",
    "item_key": "u171",
    "regular_price": 1200.00,
    "sale_price": 980.00,
    "applied": "sale"
  }
}
```

For Woo addon products, the engine uses Woo's effective price
(`get_price()`), which already accounts for sale prices in Woo's logic.

---

## 10. Rounding policy

Rounding applies to the **final total only**, not to intermediate sums.

| `rounding`           | Meaning                                  |
|----------------------|------------------------------------------|
| `round_half_up`      | Standard rounding, .5 rounds up          |
| `round_half_even`    | Banker's rounding                        |
| `round_up`           | Always up to next step                   |
| `round_down`         | Always down to step                      |

`rounding_step` controls granularity:

```
total = 14380.37, rounding = round_half_up, rounding_step = 1.00 → 14380
total = 14380.37, rounding = round_half_up, rounding_step = 10.00 → 14380
total = 14385.00, rounding = round_half_up, rounding_step = 10.00 → 14390
```

Default for Sologtak: `round_half_up`, `rounding_step = 1.00` (NOK to
whole krone).

---

## 11. VAT handling

```
vat_mode = "incl_vat"  → prices in DB are tax-inclusive; breakdown shows VAT included
vat_mode = "excl_vat"  → prices in DB are tax-exclusive; breakdown adds VAT line
vat_mode = "off"       → no VAT line; breakdown shows raw amounts
```

VAT rate comes from WooCommerce tax settings, not from ConfigKit. The engine
delegates rate resolution to Woo's tax APIs at compute time. ConfigKit only
records the mode and resulting amount in the breakdown for transparency.

For Norway: standard rate is 25%, configured in Woo. ConfigKit reads it.

---

## 12. Minimum price floor

A template can declare a minimum price. If the computed `total` is below the
minimum:

```json
{
  "warnings": [
    {
      "message": "Minimumspris er 5000 kr.",
      "level": "info"
    }
  ],
  "total": 5000.00,
  "lines": [...original lines..., {
    "type": "min_price_floor",
    "label": "Minimumspris-tillegg",
    "amount": 1234.00
  }]
}
```

The minimum price floor is a template-level setting (stored in template's
config; column TBD in DATA_MODEL.md §3.5). Default: no minimum.

---

## 13. Server-side recalculation triggers

The server MUST recalculate price (and overwrite cart item meta) at:

- Add to cart (initial calculation)
- Cart item update (qty change, coupon apply/remove)
- Cart loaded into checkout
- Order creation (final snapshot)
- Order edit by admin (only if price-affecting fields change)

Frontend live price is allowed to be stale by ≤ 200ms relative to server.
Mismatch detection uses absolute delta:

```
abs(server_total - client_total) > 0.01 → log mismatch
```

---

## 14. Performance budget

Per TARGET_ARCHITECTURE.md §10:

| Operation                        | Target | Warning | Fail    |
|----------------------------------|--------|---------|---------|
| Pricing recalc (single)          | <100ms | >300ms  | >800ms  |
| Lookup cell match (single)       | <5ms   | >20ms   | >100ms  |

The Lookup Engine is critical-path. It must use the composite index on
`(lookup_table_key, width, height, price_group_key)` for sub-5ms reads.

---

## 15. Pure-PHP testability

The Pricing Engine is a pure function:

```php
public function calculate( PricingInput $input ): PricingResult { ... }
```

`PricingInput` is a value object built from arrays. `PricingResult` is a
value object containing the breakdown.

PHPUnit tests construct inputs in PHP, no DB, no WP bootstrap:

```php
$engine = new PricingEngine( $lookupEngine );
$input = PricingInput::fromArray( [ ... ] );
$result = $engine->calculate( $input );
$this->assertSame( 14380.00, $result->total );
```

Mandatory test coverage:

- Each pricing mode (fixed, per_unit, per_m2, lookup_dimension, none)
- Hidden field exclusion
- Sale price precedence
- Rounding (each policy + each step)
- VAT inclusion/exclusion
- Lookup mismatch → blocked = true
- Surcharges from rule results
- Minimum price floor

---

## 16. Edge cases — explicit decisions

| Case                                              | Decision                                  |
|---------------------------------------------------|-------------------------------------------|
| No lookup table assigned, no static base          | total = 0, no block                       |
| Lookup table assigned but no cell match           | blocked, total = 0                        |
| Field has pricing_mode='fixed' but pricing_value is null | Treated as 0                       |
| Selected library item has price=null              | Treated as 0; not blocked                 |
| Woo addon product is out of stock                 | blocked = true if Woo says unavailable    |
| Woo addon product is deleted (orphan SKU)         | blocked = true, log `pricing.addon_orphan` |
| Negative computed total                           | Clamp to 0; log `pricing.negative_clamped` |

---

## 17. Acceptance criteria

This document is in DRAFT v1. Awaiting owner review.

Sign-off requires:

- [ ] Output breakdown shape (§3) accepted.
- [ ] Pricing modes (§4) cover known cases.
- [ ] Formula (§5) accepted.
- [ ] Hidden field exclusion (§6) is the law.
- [ ] Lookup match strategies (§8) accepted; round_up = Sologtak default.
- [ ] Sale price precedence (§9) accepted.
- [ ] Rounding policy (§10) accepted; defaults match owner expectation.
- [ ] VAT handling (§11) accepted; delegates to Woo.
- [ ] Recalculation triggers (§13) accepted.
- [ ] Edge cases (§16) accepted.

After approval, Phase 2 may implement PricingEngine.

---

## 18. Pricing source resolution (Phase 4.2)

The original PRICING_CONTRACT (§4 / §5 / §9) treats per-field
pricing as a flat amount × quantity / area. From Phase 4.2 onward
the per-field amount is itself the output of a small resolver that
walks the item's `price_source` enum:

> For library-backed fields, `priceSourceResolver(item)` replaces
> the direct read of `library_item.price` in §5's formula. Lookup
> dimensions, manual options, and addon fields are unchanged.

See `PRICING_SOURCE_MODEL.md` for:

- §2 — the five-value `price_source` enum
- §3 — the deterministic resolution ladder (binding override →
  item.price_source → sale / surcharge / discount / floor)
- §5 — the `PriceProvider` adapter contract that keeps
  `PricingEngine` pure (zero `wc_get_product()` calls inside
  `src/Engines/`)
- §6 — VAT-mode interaction across sources
- §8 — required schema additions on `wp_configkit_library_items`

Engineering implication: `PricingEngine`'s constructor signature
changes from

```
new PricingEngine( LookupEngine $lookup )
```

to

```
new PricingEngine( LookupEngine $lookup, PriceProvider $price_provider )
```

The provider is injected by the Phase 4.2 cart wiring; tests
provide a stub.

---

## 19. Bundle pricing (Phase 4.2)

Bundles are composite library items whose resolved price is
computed from component prices (`bundle_sum`) or a single fixed
price (`fixed_bundle`). See `BUNDLE_MODEL.md` for:

- §2 — `item_type = 'simple' | 'bundle'`
- §3 — bundle component shape (`component_key`, `woo_product_id`,
  `qty`, `price_source`, `price`, `stock_behavior`,
  `label_in_cart`)
- §4 — bundle pricing rules (`bundle_sum` walks components honouring
  each component's `price_source`; `fixed_bundle` uses the bundle
  item's `bundle_fixed_price`)
- §5 — `cart_behavior` (`price_inside_main` default vs
  `add_child_lines`)
- §6 — `stock_behavior` per component; rejection on
  `bundle.component_oos`
- §7 — `admin_order_display` (`expanded` default)
- §10 q2 — open question on `fixed_bundle` reconciliation across
  child cart lines

Engineering implication: `priceSourceResolver` recurses one level
into `bundle_components_json` for `bundle_sum` items and emits a
`pricing.bundle_recursion_exceeded` warning if asked to nest deeper.
Recursion depth is capped at 2 in v1 (PRICING_SOURCE_MODEL §3 +
BUNDLE_MODEL §10 q1).
