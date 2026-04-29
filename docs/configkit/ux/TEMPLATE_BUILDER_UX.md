# ConfigKit Template Builder UX

| Field            | Value                                       |
|------------------|---------------------------------------------|
| Status           | DRAFT v1                                    |
| Document type    | UX specification — most complex screen      |
| Companion docs   | OWNER_UX_FLOW.md, ADMIN_SITEMAP.md, FIELD_MODEL.md, RULE_ENGINE_CONTRACT.md |
| Spec language    | English                                     |
| Affects phases   | Phase 3 (admin UI core)                     |

---

## 1. Purpose

The template builder is where the owner defines what a configurable product
looks like for the customer. It is the heaviest screen in the admin.

This document defines how it works without becoming an Elementor-style
page builder. The goal:

- Owner does not write JSON.
- Owner does not learn the 5-axis field model by heart.
- Owner can build a working Markise template in 30-60 minutes the first
  time, 5-10 minutes thereafter.
- Every action saves real data.
- No fake buttons.

---

## 2. Three-pane layout

Desktop layout (1280px+):

```
┌─────────────────────────────────────────────────────────────────────┐
│  Breadcrumb: ConfigKit › Templates › Markise Motorisert (draft v3)  │
├──────────────┬──────────────────────────┬───────────────────────────┤
│              │                          │                           │
│  STEPS       │  FIELDS in selected step │  SETTINGS for selected   │
│  (left pane) │  (middle pane)           │  field/step              │
│              │                          │  (right pane)            │
│              │                          │                           │
│  + Step      │  + Field                 │  Field key               │
│              │                          │  Label                   │
│  ▸ Mål       │  □ width_mm              │  Field kind              │
│  ▸ Duk og    │  □ height_mm             │  Input type              │
│    farge     │  □ section_size_heading  │  Display type            │
│  ▸ Betjening │                          │  Source                  │
│  ▸ Tilbehør  │                          │  Pricing                 │
│  ▸ Oppsumm.  │                          │  ...                     │
│              │                          │                           │
└──────────────┴──────────────────────────┴───────────────────────────┘
│  Footer: [Save draft] [Discard] [Validate] [Publish v4]              │
└─────────────────────────────────────────────────────────────────────┘
```

Sub-1280px: collapses to one pane at a time, with tab switcher at top.

---

## 3. Top bar — template basics

Shown above all panes:

| Element              | Notes                                          |
|----------------------|------------------------------------------------|
| Template name        | Editable inline                                |
| template_key         | Editable inline (validated snake_case)         |
| Family               | Dropdown                                       |
| Status badge         | `draft` / `published` / `archived`             |
| Published version    | "v3" link → opens read-only view of v3 snapshot|
| Draft version        | "draft (12 unsaved changes)"                   |
| Save state           | "Saved 2 min ago" / "Saving..." / "Unsaved"   |

**Editing while published:**
- The first edit click on a field of a published template prompts: "Create a
  new draft? Changes won't go live until you publish v(N+1)."
- After confirm, draft created from current published snapshot. Owner edits
  draft. Published version stays live for customers.

---

## 4. Steps pane (left)

Each step shows:

- Drag handle (reorder)
- Step name
- Step key (small, monospace)
- Field count badge
- Visibility flag (always visible / conditional via rule)
- Required flag

Actions:
- "+ Step" button at top: creates new step inline (auto-generates step_key,
  prompts for label, sort_order = last)
- Click step → selects, middle pane updates
- Right-click → context menu: rename, duplicate, delete (soft)

Empty state (new template):
```
No steps yet.

Templates need at least one step.

[+ Add first step]
```

---

## 5. Fields pane (middle)

Shows fields in the currently-selected step.

Each field row shows:
- Drag handle
- Type icon (number, radio, checkbox, dropdown, image_radio, swatch, info, heading)
- Field key (monospace, small)
- Label
- Required badge
- Pricing badge (free / +X kr / lookup / addon)
- Source badge (manual_options / library:textiles_dickson / woo_category:sensorer / lookup)

Actions:
- "+ Field" button → opens the field creation wizard (§7).
- Click field → selects, right pane updates.
- Right-click → context menu: duplicate, delete, move to other step.

Empty step state:
```
No fields in this step yet.

[+ Add first field]
```

---

## 6. Settings pane (right) — field editor

When a field is selected, the right pane shows its editor. This is where the
5-axis model meets the owner.

### 6.1 Visible owner-facing form

The owner does NOT see "field_kind / input_type / display_type / value_source /
behavior" labels directly. Instead, the form starts with a single question:

> **What does the customer do here?**

Five high-level choices with icons and one-line descriptions:

| Choice             | What happens behind the scenes                                |
|--------------------|---------------------------------------------------------------|
| Enter a number     | field_kind=input, input_type=number, display=plain            |
| Pick one option    | field_kind=input, input_type=radio (display configurable)     |
| Pick multiple options | field_kind=input, input_type=checkbox                      |
| Pick a Woo product (add-on) | field_kind=addon, input_type=checkbox or radio       |
| Show information (no input) | field_kind=display                                   |

Plus a 6th advanced option (under "Show advanced"):
- "Configure as a lookup dimension" → field_kind=lookup, etc.

### 6.2 Per-choice follow-up

After choosing "Pick one option", the form asks:

> **Where do the options come from?**

| Choice                              | Maps to                                  |
|-------------------------------------|------------------------------------------|
| I'll type them in here              | value_source = manual_options            |
| Pull from a library                 | value_source = library                   |
| All Woo products in a category      | value_source = woo_category              |
| Specific Woo products by SKU        | value_source = woo_products              |

If "library" → owner picks one or more libraries from a multi-select. The
list is grouped by module (textiles, motors, colors, etc.) so the owner
sees relevant libraries together.

If "woo_category" → owner picks a category from existing Woo categories +
optional tag filter.

If "manual_options" → owner enters options inline (see §6.4).

### 6.3 Display style follow-up

After source is chosen, the form asks:

> **How should it look?**

| Choice           | Maps to                                  |
|------------------|------------------------------------------|
| Plain radio buttons | display_type = plain                  |
| Cards with images | display_type = cards                    |
| Image grid       | display_type = image_grid                |
| Color swatches   | display_type = swatch_grid               |
| Dropdown (lots of options) | input_type=dropdown, display=plain |

If the chosen library doesn't `supports_image`, image-based options are
disabled with a tooltip: "Library X has no image support."

### 6.4 Manual options inline editor

When `value_source = manual_options`, an inline table appears below the
field settings:

| option_key | Label | Price | Sale price | Image | Active | Actions |
|------------|-------|-------|------------|-------|--------|---------|
| manual     | Manuell | 0 |            |       | ✓      | edit/delete |
| motorized  | Motorisert | 0 |        |       | ✓      | edit/delete |

"+ Add option" button at bottom.

### 6.5 Pricing settings

Always-visible section in the right pane (collapsed by default):

| Field            | Notes                                          |
|------------------|------------------------------------------------|
| Pricing mode     | none / fixed / per_unit / per_m2 / lookup_dimension |
| Pricing value    | Number (NOK), if mode != none and != lookup     |
| Apply pricing to | this field's selection / library item's price (default) |

Plain language helper:
- "none": this field doesn't affect price
- "fixed": adds the same amount when selected
- "per_unit": multiplies by number of selections (for checkbox)
- "per_m2": multiplies by area (uses width × height)
- "lookup_dimension": this field is a width/height/group input

### 6.6 Display in cart/order/email

Four checkboxes (default all on):

- Show in cart line item
- Show in checkout
- Show in admin order
- Show in customer email

Helper text: "Internal-only fields (e.g. production notes) should be turned off here."

### 6.7 Required + default

- "Required" checkbox
- "Default value" — picker matched to the source:
  - manual_options → dropdown of options
  - library → dropdown of items
  - woo_products → SKU search
  - woo_category → SKU search filtered by category
  - number → numeric input
- "Helper text" — shown to customer below the field
- "Description" (admin-only) — context for whoever edits this template later

### 6.8 Advanced section (collapsed by default)

For power users:
- field_key (editable; warning if changed: "Changing field_key breaks rules
  referencing this field. Are you sure?")
- Sort order (manual integer, normally drag-set)
- View raw source_config_json (read-only with "Edit JSON" button to switch
  to JSON editor for that one field)

---

## 7. Field creation wizard

Triggered by "+ Field" in the middle pane. Modal dialog, three steps:

### Step 1 — Type
"What does the customer do here?" (the 5 options from §6.1)

### Step 2 — Source (if applicable)
Skipped for "Show information" type. Otherwise: choose value_source.

### Step 3 — Basics
- Field name (label) — required
- field_key auto-suggested, editable
- Step (current step pre-selected)
- "+ Save and configure" button → creates field, opens right-pane editor
- "+ Save and add another" button → creates field, resets wizard for next

The wizard is only the entry path. Full configuration happens in the right
pane (§6).

---

## 8. Rules section

Toggle in the top bar: "Rules (4)" → opens a Rules drawer/modal alongside
the three panes.

Rules drawer shows:
- List of rules for this template
- Each rule: name, condition summary, action summary, priority, active toggle
- "+ New rule" button

Rule editor (basic — Phase 3):

```
WHEN
[ Field: control_type ▼ ]  [ Operator: equals ▼ ]  [ Value: motorized ▼ ]

[ + Add condition (AND) ]  [ Switch to "any" (OR) ]

THEN
[ Action: show step ▼ ]  [ Target: motor_and_control ▼ ]
[ + Add action ]
```

For complex rules (nested groups, NOT, multiple action types), a "Switch to
JSON" button toggles to a raw spec_json editor with syntax highlighting.

The JSON editor validates against RULE_ENGINE_CONTRACT.md schema on save.

**Phase 3 scope:** flat AND/OR conditions + single-level NOT. Nested groups
in JSON mode. Visual nested builder = Phase 4.

---

## 9. Validation states

The builder runs validation continuously:

### 9.1 Field-level (real-time)
- field_key format (snake_case regex)
- field_key uniqueness within template (red border + tooltip if duplicate)
- Required source for input/lookup/addon kinds
- Valid 5-axis combination (per FIELD_MODEL.md §8 matrix)

### 9.2 Template-level (on save)
- All fields have a valid source_config
- All required fields have a way to get a value
- All steps have at least one field (warning if empty step)
- All rules reference existing fields

### 9.3 Pre-publish (on publish click)
- Strict version of template-level
- Rules cycle detection
- Lookup table assigned (warning if not, since binding overrides this)
- All library references resolve

If any pre-publish check fails, the publish button is disabled with a
tooltip listing the blockers. "View issues" link opens diagnostics for
this template.

---

## 10. Save and publish workflow

### 10.1 Auto-save vs manual save

**Manual save only.** No autosave.

The owner clicks "Save draft" to persist. Unsaved indicator stays visible
until clicked.

Drag-drop reordering of fields/steps saves immediately (low-stakes operation).

### 10.2 Discard

"Discard changes" button reverts to last saved draft state. Confirmation
required.

### 10.3 Publish

"Publish v(N+1)" button:

1. Runs pre-publish validation (§9.3).
2. If green, prompts: "Publishing creates version (N+1). Existing carts
   keep version N. New configurations use version (N+1). Continue?"
3. On confirm:
   - Snapshot serialized to `template_versions.snapshot_json`
   - `templates.published_version_id` updated
   - Draft cleared (or kept as next-draft, owner choice)
   - Diagnostics re-run for this template

### 10.4 Version history view

Top bar "Versions" link opens a side drawer:

| Version | Status      | Published    | Used by  | Actions       |
|---------|-------------|--------------|----------|---------------|
| v3      | published   | 2 days ago   | 12 carts, 47 orders | View, Archive |
| v2      | archived    | 3 weeks ago  | 0 carts, 23 orders  | View          |
| v1      | archived    | 2 months ago | 0 carts, 8 orders   | View          |

"View" opens read-only snapshot. "Archive" demotes a published version
(rare; mostly for emergency rollback by archiving v3 and re-publishing v2,
which Phase 3 does not need to support — Phase 5+).

---

## 11. Preview (Phase 4, but referenced here)

The "Preview" button in the top bar is **disabled** in Phase 3 with tooltip:
"Preview comes in Phase 4 (frontend renderer)."

Phase 4 will add an in-admin preview iframe that renders the configurator
as the customer sees it, against the current draft + a sample binding.

---

## 12. Mobile / tablet

Below 1024px: the three-pane layout collapses to one pane at a time with
a tab bar:

```
[ Steps ] [ Fields ] [ Settings ] [ Rules ]
```

This is a degraded experience. The owner is expected to use desktop for
real template editing. Mobile is for read-only review and tiny tweaks.

Below 768px (phone): show "Template editing requires a larger screen.
Open this on desktop." Block editing UI. List of fields shown read-only.

---

## 13. Forbidden patterns

Things the template builder MUST NOT do, per architectural law:

- **No raw `field_kind` / `input_type` / `display_type` labels** in the
  default form. These are technical concepts. Owner sees natural language
  questions (§6.1).
- **No JSON editor as the default** for any owner-facing data. JSON
  appears only in advanced toggles (raw source_config, rule spec).
- **No buttons that don't work yet.** "Preview" can be disabled with
  tooltip; "Publish" can be disabled with validation tooltip; but no
  buttons that just sit there as decoration.
- **No autosave.** Drafts must be explicit.
- **No demo / fake data.** Empty states show empty-state UI, not seeded
  examples.
- **No hardcoded option lists** — every dropdown pulls from the database.
- **No labels-as-keys.** field_key is its own field, never derived
  silently from label.

---

## 14. Phase 3 deliverables

In Phase 3, the template builder must:

- [ ] Three-pane desktop layout works
- [ ] Step CRUD with drag-reorder
- [ ] Field CRUD with drag-reorder
- [ ] Field creation wizard (3-step modal)
- [ ] Right-pane field editor with all settings (§6)
- [ ] Manual options inline editor
- [ ] Pricing settings section
- [ ] Display-in-cart/order/email checkboxes
- [ ] Required + default + helper text
- [ ] Advanced section (collapsed)
- [ ] Rules drawer with basic CRUD (flat AND/OR)
- [ ] Switch-to-JSON for rules (developer mode)
- [ ] Field-level real-time validation
- [ ] Template-level validation on save
- [ ] Pre-publish strict validation
- [ ] Publish workflow with version snapshot
- [ ] Version history drawer
- [ ] Mobile collapse to tabs / desktop-only message below 768px
- [ ] Optimistic locking on save (version_hash)
- [ ] No fake buttons
- [ ] No demo data
- [ ] No labels-as-keys

---

## 15. Acceptance criteria

This document is in DRAFT v1. Awaiting owner review.

Sign-off requires:

- [ ] Three-pane layout (§2) accepted.
- [ ] Owner-facing field editor language (§6.1) doesn't expose 5-axis labels.
- [ ] Manual options inline editor (§6.4) covers needs.
- [ ] Pricing settings (§6.5) cover all pricing modes.
- [ ] Field creation wizard (§7) accepted.
- [ ] Rules section (§8) basic CRUD scope accepted.
- [ ] Validation states (§9) cover real failure modes.
- [ ] Save/publish workflow (§10) accepted.
- [ ] Forbidden patterns (§13) are the law.
- [ ] Phase 3 deliverables (§14) accepted as Phase 3 scope.

After approval of all three Phase 3 prep docs (OWNER_UX_FLOW.md,
ADMIN_SITEMAP.md, TEMPLATE_BUILDER_UX.md), Phase 3 implementation may begin.
