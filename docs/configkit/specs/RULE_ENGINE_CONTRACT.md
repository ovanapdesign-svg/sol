# ConfigKit Rule Engine Contract

| Field            | Value                                       |
|------------------|---------------------------------------------|
| Status           | DRAFT v1                                    |
| Document type    | Engine contract specification               |
| Companion docs   | TARGET_ARCHITECTURE.md, FIELD_MODEL.md, DATA_MODEL.md |
| Spec language    | English                                     |
| Affects phases   | Phase 2 (engines), Phase 4 (frontend)       |

---

## 1. Purpose

The Rule Engine evaluates JSON rule specifications against a configuration
state and produces a list of effects (show/hide fields, filter sources, add
surcharges, block add-to-cart, etc.).

The same rule specification runs in two places:

- **Server-side (PHP)** — authoritative. Runs at validation, pricing, and
  add-to-cart. Server result is the truth.
- **Client-side (JS)** — preview only. Runs on every field change to give
  live UX. Server re-evaluates and overrides if mismatch.

This contract defines the rule schema, evaluation semantics, and the result
shape both implementations must produce.

---

## 2. Rule storage

Rules live in `wp_configkit_rules` (DATA_MODEL.md §3.10). One row per rule,
JSON spec in `spec_json` column.

Each rule has:

- `template_key` — owning template
- `rule_key` — stable snake_case identifier within template
- `name` — display label for the admin
- `spec_json` — the rule specification (this document defines its shape)
- `priority` — integer; lower runs earlier
- `is_active` — bool; inactive rules are skipped

A template's rules are loaded all at once and evaluated in priority order.

---

## 3. Rule spec shape (top level)

```json
{
  "when": <condition>,
  "then": [<action>, <action>, ...]
}
```

| Key   | Required | Type             | Notes                              |
|-------|----------|------------------|------------------------------------|
| when  | yes      | condition object | The trigger logic                  |
| then  | yes      | array of actions | Effects when condition is true     |

There is no `else` clause. To express "if A then X else Y", write two rules
with negated conditions.

---

## 4. Conditions

A condition is a JSON object. Three forms are supported.

### 4.1 Atomic condition (field comparison)

```json
{ "field": "<field_key>", "op": "<operator>", "value": <value> }
```

| Operator       | Value type        | Meaning                              |
|----------------|-------------------|--------------------------------------|
| `equals`       | scalar            | field value === provided value       |
| `not_equals`   | scalar            | inverse of equals                    |
| `greater_than` | number            | numeric comparison                   |
| `less_than`    | number            | numeric comparison                   |
| `between`      | [min, max]        | min ≤ value ≤ max                    |
| `contains`     | scalar            | field value (string) contains substring; or array contains element |
| `is_selected`  | (no value)        | field has any non-empty value        |
| `is_empty`     | (no value)        | field has no value or empty array    |
| `in`           | array             | field value matches any in array     |
| `not_in`       | array             | field value matches none in array    |

Examples:

```json
{ "field": "control_type", "op": "equals", "value": "motorized" }
{ "field": "width_mm", "op": "greater_than", "value": 5000 }
{ "field": "width_mm", "op": "between", "value": [3000, 5000] }
{ "field": "control_system", "op": "in", "value": ["io", "rts"] }
{ "field": "fabric_color", "op": "is_empty" }
```

### 4.2 Logical group (AND / OR / NOT)

```json
{
  "all": [<condition>, <condition>, ...]
}
```

```json
{
  "any": [<condition>, <condition>, ...]
}
```

```json
{
  "not": <condition>
}
```

| Form  | Meaning                                  |
|-------|------------------------------------------|
| `all` | AND — every nested condition must match  |
| `any` | OR — at least one nested condition must match |
| `not` | Inverse of the nested condition          |

Groups can nest arbitrarily.

Example — "motorized AND (IO OR RTS) AND width > 4000":

```json
{
  "all": [
    { "field": "control_type", "op": "equals", "value": "motorized" },
    {
      "any": [
        { "field": "control_system", "op": "equals", "value": "io" },
        { "field": "control_system", "op": "equals", "value": "rts" }
      ]
    },
    { "field": "width_mm", "op": "greater_than", "value": 4000 }
  ]
}
```

### 4.3 Always / never (utility)

```json
{ "always": true }
{ "always": false }
```

`always: true` matches unconditionally — useful for default actions.
`always: false` never matches — useful for disabling a rule without
deleting it.

---

## 5. Actions

Each action is a JSON object with an `action` key naming the action type.
The remaining keys are action-specific parameters.

### 5.1 show_field / hide_field

```json
{ "action": "show_field", "field": "<field_key>" }
{ "action": "hide_field", "field": "<field_key>" }
```

Toggles field visibility. Hidden fields are excluded from pricing (per
FIELD_MODEL.md §10) and from add-to-cart payload validation.

### 5.2 show_step / hide_step

```json
{ "action": "show_step", "step": "<step_key>" }
{ "action": "hide_step", "step": "<step_key>" }
```

Toggles entire step visibility. All fields in a hidden step are also hidden.

### 5.3 require_field

```json
{ "action": "require_field", "field": "<field_key>" }
```

Marks a normally-optional field as required. Has no effect if the field is
hidden.

### 5.4 disable_option

```json
{
  "action": "disable_option",
  "field": "<field_key>",
  "option": "<option_key_or_item_key>"
}
```

Removes a specific choice from a field's option list. The option is shown
greyed out with a tooltip explaining why (admin can configure tooltip text
in the rule's `name`).

### 5.5 filter_source

Restricts a field's value source by tag, key list, or attribute.

```json
{
  "action": "filter_source",
  "field": "<field_key>",
  "filter": {
    "tag": "<tag_name>"
  }
}
```

Other filter shapes:

```json
{ "filter": { "tags_any": ["io", "rts"] } }
{ "filter": { "tags_all": ["popular", "in_stock"] } }
{ "filter": { "item_keys": ["u171", "u172"] } }
{ "filter": { "library_keys": ["textiles_dickson"] } }
{ "filter": { "attribute": { "blackout": true } } }
```

Multiple `filter_source` actions on the same field are combined with AND
semantics.

### 5.6 set_default

```json
{
  "action": "set_default",
  "field": "<field_key>",
  "value": "<option_key_or_value>"
}
```

Sets the field's default value if no value is currently set. Does not
override an existing user selection.

### 5.7 reset_value

```json
{ "action": "reset_value", "field": "<field_key>" }
```

Clears the current value. Used in cascading reset chains — e.g. when width
changes, reset fabric_color because the available textiles depend on width.

### 5.8 switch_lookup_table

```json
{
  "action": "switch_lookup_table",
  "lookup_table_key": "<key>"
}
```

Replaces the current product's lookup table for pricing. Used for size-band
pricing or seasonal table swaps.

### 5.9 add_surcharge

```json
{
  "action": "add_surcharge",
  "label": "Storformat-tillegg",
  "amount": 1500
}
```

Adds a fixed amount to the price total. Surcharges appear in the price
breakdown with the provided label. Multiple surcharges accumulate.

For percentage surcharges:

```json
{
  "action": "add_surcharge",
  "label": "VAT modifier",
  "percent_of_base": 5
}
```

Either `amount` or `percent_of_base` must be present, not both.

### 5.10 show_warning

```json
{
  "action": "show_warning",
  "message": "Maks bredde for manuell betjening er 5000 mm.",
  "level": "warning"
}
```

Displays a non-blocking notice to the customer. `level` is one of `info`,
`warning`, `error`. Does not block add-to-cart.

### 5.11 block_add_to_cart

```json
{
  "action": "block_add_to_cart",
  "message": "Denne kombinasjonen er ikke tilgjengelig. Kontakt oss for tilbud."
}
```

Disables the add-to-cart button and displays the message. The most strict
action — server enforces this even if the client UI fails to.

---

## 6. Evaluation contract

### 6.1 Inputs

The engine receives:

```json
{
  "template_key": "markise_motorized_v1",
  "template_version_id": 42,
  "rules": [<rule>, <rule>, ...],
  "selections": {
    "<field_key>": <value>
  },
  "field_metadata": {
    "<field_key>": {
      "is_required": true,
      "default_visible": true,
      "default_value": null
    }
  }
}
```

### 6.2 Output

```json
{
  "fields": {
    "<field_key>": {
      "visible": true,
      "required": false,
      "options_filter": { ... },
      "disabled_options": ["<key>", ...],
      "default": null,
      "value": <effective value after resets and defaults>
    }
  },
  "steps": {
    "<step_key>": { "visible": true }
  },
  "lookup_table_key": "markise_2d_v1",
  "surcharges": [
    { "label": "...", "amount": 1500 }
  ],
  "warnings": [
    { "message": "...", "level": "warning" }
  ],
  "blocked": false,
  "block_reason": null,
  "rule_results": [
    { "rule_key": "...", "matched": true, "actions_applied": [...] }
  ]
}
```

`rule_results` is for diagnostics/logging; it does not affect rendering.

### 6.3 Evaluation order

1. Initialize `fields` and `steps` from `field_metadata` defaults
   (visible = true, required = field's static is_required).
2. Sort rules by `priority` ascending, then by `sort_order`.
3. For each active rule:
   a. Evaluate `when` against current state (selections + already-applied effects)
   b. If matched: apply each action in `then` order to the result accumulator
4. After all rules: run reset cascade (see §6.4).
5. Apply defaults from `set_default` actions to fields with no value.
6. Return result.

### 6.4 Reset cascade

After rule application, for each field where:
- `visible = false`, OR
- `options_filter` excludes the currently-selected value, OR
- `disabled_options` includes the currently-selected value, OR
- a `reset_value` action targeted this field

→ the field's value in the output is set to `null`. Cascading resets do not
re-trigger rule evaluation in the same pass; this is single-pass intentional.

### 6.5 Cycle detection

Rules can theoretically reference each other (rule A's result changes a field,
rule B reads that field). Single-pass evaluation prevents infinite loops by
construction — each rule sees the state after previously-applied rules.

If two rules in the same priority bucket conflict (e.g. one shows, one hides
the same field), the one with the higher `sort_order` wins. This is logged
as `rule.conflict` in the diagnostics log but does not throw an error.

---

## 7. Missing target handling

If a rule references a `field_key`, `step_key`, `option_key`, or `lookup_table_key`
that does not exist in the current template:

- Server-side: skip the action, write `rule.target_missing` log entry with
  the rule_key and missing target.
- Client-side: skip silently (server log will catch it).

This is preferred over throwing. Templates evolve; broken references should
be caught by the diagnostics scan, not by runtime errors that block customers.

---

## 8. Server vs client parity

Both implementations MUST:

- Accept the same JSON rule spec.
- Produce the same `fields`, `steps`, `surcharges`, `warnings`, `blocked`,
  `lookup_table_key` outputs for the same input.
- Skip missing targets identically.

Both implementations MAY differ in:

- `rule_results` (diagnostics-only, server-side has fuller logging).
- Performance (server is allowed to be slower; client must be ≤100ms).

Test contract:

- Phase 2 PHPUnit tests load fixture rule specs from JSON files.
- Phase 4 JS tests load the same fixtures.
- Same input + same fixtures → same output. Parity is enforced via fixture-
  driven tests.

---

## 9. Validation at write time

When the admin saves a rule via REST:

- `spec_json` is parsed and validated against the schema in this document.
- All `field_key`, `step_key`, `option_key`, `lookup_table_key` references
  are checked to exist in the current template draft.
- Unknown action types or unknown operators are rejected.
- A successfully-validated rule is stored with its `version_hash` updated.

Validation errors return 400 with a structured error payload pointing at
the offending JSON path.

---

## 10. Examples

### 10.1 Show motor step only when control_type = motorized

```json
{
  "when": { "field": "control_type", "op": "equals", "value": "motorized" },
  "then": [
    { "action": "show_step", "step": "motor_and_control" }
  ]
}
```

(With a counter rule:)

```json
{
  "when": { "field": "control_type", "op": "equals", "value": "manual" },
  "then": [
    { "action": "hide_step", "step": "motor_and_control" }
  ]
}
```

### 10.2 Filter sensors to IO-compatible when control_system = IO

```json
{
  "when": { "field": "control_system", "op": "equals", "value": "io" },
  "then": [
    {
      "action": "filter_source",
      "field": "sensor_addon",
      "filter": { "tag": "io" }
    }
  ]
}
```

### 10.3 Block manual crank at large widths

```json
{
  "when": { "field": "width_mm", "op": "greater_than", "value": 5000 },
  "then": [
    {
      "action": "disable_option",
      "field": "control_type",
      "option": "manual"
    },
    {
      "action": "show_warning",
      "message": "Manuell betjening er ikke tilgjengelig over 5000 mm.",
      "level": "info"
    }
  ]
}
```

### 10.4 Surcharge for oversized markisas

```json
{
  "when": {
    "all": [
      { "field": "width_mm", "op": "greater_than", "value": 5000 },
      { "field": "height_mm", "op": "greater_than", "value": 3500 }
    ]
  },
  "then": [
    {
      "action": "add_surcharge",
      "label": "Storformat-tillegg",
      "amount": 1500
    }
  ]
}
```

### 10.5 Cascading reset when width changes

```json
{
  "when": { "field": "width_mm", "op": "is_selected" },
  "then": [
    { "action": "reset_value", "field": "fabric_color" }
  ]
}
```

(This is too aggressive in practice — reset_value triggers on any width
selection. A more refined version uses a custom `op` like `changed_since_last`,
which is out of scope for v1. For v1, owners should reset selectively per
business rule.)

---

## 11. Version compatibility

`spec_json` payloads carry no version field. Adding new operators or actions
is backward-compatible; removing or renaming is breaking. Breaking changes
require a migration that rewrites existing `spec_json` rows.

If a future version needs explicit versioning, a top-level `"version": 2` key
will be introduced; absent = version 1.

---

## 12. Acceptance criteria

This document is in DRAFT v1. Awaiting owner review.

Sign-off requires:

- [ ] Rule spec shape (§3) accepted.
- [ ] Condition operators (§4) cover known cases.
- [ ] Action types (§5) cover known cases.
- [ ] Evaluation contract output shape (§6) accepted.
- [ ] Missing target handling (§7) accepted.
- [ ] Server/client parity rule (§8) accepted.
- [ ] Examples (§10) match owner intent.

After approval, Phase 2 may implement RuleEngine.
