# ConfigKit Owner UX Flow

| Field            | Value                                       |
|------------------|---------------------------------------------|
| Status           | DRAFT v1                                    |
| Document type    | UX flow specification                       |
| Companion docs   | ADMIN_SITEMAP.md, TEMPLATE_BUILDER_UX.md    |
| Spec language    | English                                     |
| Affects phases   | Phase 3 (admin UI core), Phase 4+           |

---

## 1. Purpose

This document defines the workflows the owner performs to set up, manage,
and publish configurable products. It exists to prevent the next admin UI
from being a collection of CRUD pages that don't fit any real flow.

The owner's mission (TARGET_ARCHITECTURE.md §1):

1. Import product data via Excel.
2. Assign family + template + lookup table to a Woo product.
3. Run diagnostics, preview, publish.
4. Owner does NOT manually wire 100 fields per product.

If a flow forces the owner to wire fields per product, the flow is wrong.

---

## 2. Personas

There is one persona for v1:

**The Owner** — a Sologtak admin who:
- Knows Norwegian markise/screen/pergola products.
- Does NOT write code or rules JSON by hand.
- Adds 5-50 products at a time, mostly via Excel.
- Wants to see "is this product ready to sell?" at a glance.
- Will not read documentation while working.

---

## 3. Flow A — Add a configurable product end-to-end

**Goal:** Owner has a new Woo product. Make it a configurable Markise.

**Steps:**

1. Owner opens **Products** page in ConfigKit admin.
2. List shows all Woo products. Each row shows:
   - Product name
   - SKU
   - Woo category
   - ConfigKit status: `not_enabled` | `ready` | `missing_template` | `missing_lookup` | `missing_data`
   - Action: "Edit binding"
3. Owner clicks "Edit binding" on a Woo product.
4. Binding screen shows:
   - Toggle: "Enable ConfigKit for this product"
   - When enabled, dropdowns appear for:
     - Family (suggested by Woo category if matched)
     - Template (filtered by selected family)
     - Lookup Table (filtered by selected family)
   - Default values section (one row per template field, value picker)
   - Validation status: "ready" / "missing X" / "broken Y"
5. Owner saves.
6. Status updates immediately. If `ready`, the "Preview" button appears
   (Phase 4+). If not ready, the Diagnostics column lists what's missing.

**Fail states:**
- Family selected but no templates exist for that family → dropdown empty,
  link "Create a template for this family" → opens template builder.
- Template references a library that's empty → "Library X has no items.
  Add items here →" link.
- No lookup table assigned → "This template requires a lookup table. Pick
  one or create one."

**Phase 3 scope:** binding screen + status indicators. Preview, frontend
render, and order flow come later.

---

## 4. Flow B — Create a module

**Goal:** Owner wants to add a new option group not in the built-in modules.
Example: "Kappe types" — a list of valance variants for markisas.

**Steps:**

1. Owner opens **Settings → Modules** (or just **Modules** page).
2. List shows existing modules with capability flags. Empty initially
   (per A decision — no seed data).
3. Owner clicks "New module".
4. Form fields:
   - `module_key` (auto-suggested from name, editable, snake_case validated)
   - `name` (Norwegian display label)
   - `description`
   - Capability checkboxes:
     - supports_sku
     - supports_image
     - supports_main_image
     - supports_price
     - supports_sale_price
     - supports_filters
     - supports_compatibility
     - supports_price_group
     - supports_brand
     - supports_collection
     - supports_color_family
     - supports_woo_product_link
   - Allowed field kinds (multi-select from input/addon/lookup)
   - Attribute schema (advanced, JSON editor — toggle "show advanced")
5. Owner saves.
6. Module appears in the list. Available now as a `value_source = library`
   option in field editors.

**Important:** No `is_builtin = 1` checkbox in Phase 3. All owner-created
modules are user-managed. The `is_builtin` flag exists only for future
plugin-shipped seed packs.

---

## 5. Flow C — Create a library

**Goal:** Owner has a new fabric collection from Sandatex. Create a library.

**Steps:**

1. Owner opens **Libraries** page.
2. List shows all libraries grouped by module. Each row:
   - library_key, name, module, item count, active toggle.
3. Owner clicks "New library".
4. Form fields:
   - module (dropdown of existing modules)
   - library_key (auto-suggested)
   - name
   - description
   - brand (if module supports_brand)
   - collection (if module supports_collection)
5. Owner saves.
6. Library appears in list. Owner clicks into it.
7. Library detail shows:
   - Items list (empty for new library)
   - Buttons: "Add item", "Import from Excel" (Phase 4+), "Bulk activate"
8. Owner adds items one by one OR waits for Phase 4 import wizard.

**Phase 3 scope:** library CRUD + manual item entry. Excel import = Phase 4.

---

## 6. Flow D — Create a template

**Goal:** Owner builds the "Markise motorisert" template.

This is the heaviest flow. See TEMPLATE_BUILDER_UX.md for the detailed
template builder UX. High level:

1. Owner opens **Templates** page.
2. Clicks "New template".
3. Enters template_key, name, family.
4. Saves draft.
5. Adds steps in order: Mål → Duk og farge → Betjening → Tilbehør → Oppsummering.
6. Within each step, adds fields. For each field:
   - Picks field_kind first (input / display / addon / lookup).
   - Wizard then shows only relevant input_type / display_type / value_source
     combinations (per FIELD_MODEL.md §8 valid combinations matrix).
   - Configures source_config_json via a structured form (NOT raw JSON for
     normal owners).
7. Adds rules connecting fields (e.g., "if control_type = motorized, show
   step motor_and_control").
8. Saves draft.
9. Clicks "Publish version 1".
10. New `template_version` row created. Existing bindings keep working;
    new bindings get the new version.

**Phase 3 scope:** full template CRUD with steps, fields, rules, publish.
Visual preview of the customer-facing render = Phase 4+.

---

## 7. Flow E — Assign lookup table

**Goal:** Owner has a new Markise size×price grid in Excel.

1. Owner opens **Lookup Tables** page.
2. Clicks "New lookup table".
3. Enters lookup_table_key, name, family, unit (mm), match_mode (round_up
   default).
4. Saves.
5. Detail screen shows:
   - Cells grid (empty initially)
   - Buttons: "Add cell", "Import from Excel" (Phase 4+), "Export"
   - Stats: filled cells, min/max width, min/max height, missing combinations
6. Owner manually adds cells OR imports Excel (Phase 4+).
7. Validation status updates: "complete" / "incomplete" / "duplicates".

**Phase 3 scope:** lookup table CRUD + manual cell entry. Excel import
= Phase 4.

---

## 8. Flow F — Run diagnostics

**Goal:** Owner wants to know "what's broken right now?"

1. Owner opens **Diagnostics** page (or sees badge on **Dashboard**).
2. List shows issues, grouped by severity:
   - **Critical** — blocks customer purchases:
     - Product has ConfigKit enabled but no template
     - Template references missing field/option/library
     - Lookup table has zero cells
     - Rule targets non-existent field
   - **Warning** — should fix soon:
     - Library item without image where image display is enabled
     - Template version drift (binding uses outdated version)
     - Hidden field has saved values affecting historical orders
   - **Info** — heads-up:
     - Module created but no libraries use it
     - Template draft not published in 30 days

3. Each issue has:
   - Title
   - Object (product/template/library/etc.)
   - "Fix link" (jumps directly to the relevant edit screen)
   - "Suggested fix" text

**Phase 3 scope:** basic diagnostics — critical issues only. Warnings and
info come Phase 4+ together with import wizard.

---

## 9. Flow G — Publish a configurable product

**Goal:** Owner has finished setup. Make it live.

1. Owner returns to **Products** list.
2. Filters by status `ready`.
3. For each ready product:
   - Reviews the binding (one click).
   - Reviews diagnostics (zero critical for that product).
   - Toggles `_configkit_enabled` to true OR uses the bulk action "Publish
     selected".
4. Frontend now serves the configurator on that product page.

**Phase 3 scope:** the toggle exists. The actual frontend rendering is
Phase 4. So "publish" in Phase 3 means "the flag is set, but customers
won't see anything yet". This is OK — owner can work the binding and
Phase 4 turns on the frontend.

---

## 10. Flow H — Edit a published template

**Goal:** Owner needs to fix a typo in a label, or add a new fabric option.

1. Owner opens **Templates** → clicks template name.
2. Edit screen shows:
   - Live published version: read-only summary
   - Draft version (if exists): editable
   - Button "Edit" creates a new draft from the published version (or opens
     existing draft)
3. Owner edits draft (steps, fields, rules).
4. Saves draft. Publishes when ready.
5. Publishing creates a new `template_version` row with `version_number + 1`.
6. Existing bindings:
   - Active carts: keep using their pinned version (cart item meta has
     `_configkit_template_version_id`).
   - New bindings: use the latest published version.
   - Existing orders: NEVER change; they reference the snapshot.

**Phase 3 scope:** draft/publish workflow. Snapshot rendering of historical
orders is Phase 5/6 (TEMPLATE_VERSIONING.md will define the snapshot shape).

---

## 11. What is NOT in Phase 3

The following flows are deferred and explicitly NOT part of the Admin UI core:

| Flow                          | Phase | Reason                              |
|-------------------------------|-------|-------------------------------------|
| Excel import wizard           | 4     | Big, multi-step UX                  |
| Excel column mapping          | 4     |                                     |
| Excel dry-run + commit        | 4     |                                     |
| Product readiness board       | 4     | Advanced filtering, bulk actions    |
| Frontend product preview      | 4     | Requires renderer                   |
| Frontend customer experience  | 4     |                                     |
| Cart item display             | 5     | Requires Woo cart integration       |
| Order admin display           | 5     |                                     |
| Customer email integration    | 5     |                                     |
| Production summary blocks     | 5     |                                     |
| Pilot product end-to-end test | 7     |                                     |

---

## 12. Cross-cutting UX rules

### 12.1 Every visible button does something real

If a button is visible, it must have backend behavior wired up. No "coming
soon" placeholders. If a feature is incomplete, hide the button or mark it
explicitly with a `partial` badge per STATUS.md.

### 12.2 No raw JSON for normal owner tasks

The only places raw JSON appears in Phase 3 admin:

- Module attribute schema (advanced, behind a "Show advanced" toggle).
- Rule spec — but only via "Switch to JSON" button on the rules builder
  for power users. Default rule editor is structured form (RULES_BUILDER_UX.md
  later phase).

For all other fields (default values, source_config, etc.), the owner picks
from dropdowns, checkboxes, or typed inputs. The system serializes to JSON
under the hood.

### 12.3 Auto-suggest, owner overrides

When the owner types a name (module, library, template, etc.), the system
auto-suggests `*_key` from a slugified version. The owner can override.
After save, the key is locked — never auto-regenerates.

### 12.4 Save-or-discard, never silent persistence

Every form save is explicit. No autosave on field blur. Reason: drafts
contain partial state; autosave would publish broken templates.

The exception: rule reordering via drag-drop saves immediately, because
it's a low-stakes operation and undo is one click.

### 12.5 Validation surfaces inline + on save

- Inline (per field as owner types): format checks, snake_case validation,
  duplicate key detection.
- On save: cross-reference checks (does this field_key exist? does this
  library_key exist?).
- On publish: full template validation (all required fields have value
  sources, all rules target existing fields, etc.).

### 12.6 Mobile is for emergency reading, not editing

The owner uses desktop for all real work. Mobile admin shows read-only
status (dashboard, diagnostics, "is product X ready?"). Editing screens
are desktop-first.

---

## 13. Acceptance criteria

This document is in DRAFT v1. Awaiting owner review.

Sign-off requires:

- [ ] Flow A (product binding) matches owner intent.
- [ ] Flow B-E (module, library, template, lookup) covered.
- [ ] Phase 3 scope (§11) accepted.
- [ ] Cross-cutting UX rules (§12) accepted.

After approval, ADMIN_SITEMAP.md and TEMPLATE_BUILDER_UX.md should be
reviewed alongside; together they define Phase 3.
