# ConfigKit Admin Sitemap

| Field            | Value                                       |
|------------------|---------------------------------------------|
| Status           | DRAFT v1                                    |
| Document type    | Admin menu structure specification          |
| Companion docs   | OWNER_UX_FLOW.md, TEMPLATE_BUILDER_UX.md    |
| Spec language    | English                                     |
| Affects phases   | Phase 3 (admin UI core), Phase 4+           |

---

## 1. Top-level menu

ConfigKit appears as a single top-level menu item in the WP admin sidebar.
Position: just below WooCommerce. Icon: configurable cog.

```
WordPress Admin
├── Dashboard
├── Posts
├── Products (Woo)
├── Orders (Woo)
├── ConfigKit                        ← this plugin
│   ├── Dashboard                    (Phase 3)
│   ├── Products                     (Phase 3)
│   ├── Templates                    (Phase 3)
│   ├── Libraries                    (Phase 3)
│   ├── Lookup Tables                (Phase 3)
│   ├── Rules                        (Phase 3, basic CRUD only)
│   ├── Diagnostics                  (Phase 3, critical only)
│   ├── Imports                      (Phase 4)
│   └── Settings                     (Phase 3, basic)
│       └── Modules                  (Phase 3, sub-page of Settings)
└── ...
```

**Reasoning for "Modules" under "Settings":** Modules are a system-level
concept. The owner creates them rarely (once per option group type). They
do not belong in daily-use top-level menu. Settings → Modules is the
right depth.

---

## 2. Phase 3 in-scope pages

The following pages are part of Phase 3 (Admin UI core).

### 2.1 Dashboard

**Purpose:** Owner's home screen. Shows what needs attention.

**Sections:**

- **Critical issues** (count + jump links)
  - Products with ConfigKit enabled but no template
  - Products with no lookup table
  - Templates with broken rule targets
  - Lookup tables with zero cells
- **Counts**
  - Configurable products: N
  - Templates published: N (drafts: N)
  - Libraries active: N
  - Lookup tables: N
- **Recent activity** (real, not fake)
  - Last 5 entries from configkit_log filtered to admin actions
  - "Template X published 3 hours ago"
  - "Library Y created 1 day ago"
- **Quick actions**
  - "Open Products list"
  - "Run Diagnostics"
  - "View latest diagnostics report"

**Phase 3 scope:** all sections except Recent activity log filtering needs
basic admin event logging — if log is empty, hide the section.

**Forbidden:** fake activity entries, demo data, hardcoded counts.

### 2.2 Products

**Purpose:** Bind WooCommerce products to ConfigKit templates.

**List view:**

| Column                 | Notes                                          |
|------------------------|------------------------------------------------|
| Product name           | Link to Woo product edit                       |
| SKU                    | From Woo                                       |
| Woo category           | Pulled from Woo product categories             |
| ConfigKit enabled      | Yes/No badge                                   |
| Family                 | Family name or "—"                             |
| Template               | Template name + version, or "—"                |
| Lookup table           | Lookup name or "—"                             |
| Status                 | `ready` / `missing_template` / `missing_lookup` / `missing_data` / `disabled` |
| Action                 | "Edit binding"                                 |

**Filters:**
- ConfigKit enabled (yes/no)
- Status (multi-select)
- Family
- Woo category

**Bulk actions (Phase 3):** none in Phase 3. Bulk publish/unpublish in Phase 4.

**Edit binding screen:**
- See OWNER_UX_FLOW.md §3 (Flow A).

### 2.3 Templates

**Purpose:** Build and manage configurable product templates.

**List view:**

| Column          | Notes                                          |
|-----------------|------------------------------------------------|
| Name            | Link to template builder                       |
| template_key    | snake_case identifier                          |
| Family          | Linked family or "—"                           |
| Status          | `draft` / `published` / `archived`             |
| Published version | "v3" or "—"                                  |
| Used by         | Count of bindings                              |
| Last edited     | Timestamp                                      |

**Detail view:** see TEMPLATE_BUILDER_UX.md.

### 2.4 Libraries

**Purpose:** Manage reusable option datasets.

**List view:** grouped by module.

```
Module: textiles (3 libraries)
├── Dickson Orchestra Collection (147 items)
├── Sandatex (89 items)
└── Sunesta Suntex (52 items)

Module: motors (1 library)
└── Somfy Motors (12 items)

Module: colors (2 libraries)
├── RAL Profile Colors (213 items)
└── Sologtak Standard Colors (5 items)
```

**Per library row:**
- Name
- module
- item count (active / total)
- is_active toggle
- Actions: "Open", "Duplicate", "Soft delete"

**Library detail:**
- Items list with module-aware columns (sku, image, price, price_group, etc.).
- Item editor form generated from `attribute_schema_json`.
- Item actions: edit, duplicate, soft-delete, bulk activate/deactivate.

**Phase 3 scope:** library + item CRUD. Excel import = Phase 4.

### 2.5 Lookup Tables

**Purpose:** Size × price grid management.

**List view:**

| Column         | Notes                                          |
|----------------|------------------------------------------------|
| Name           | Link to detail                                 |
| lookup_table_key |                                              |
| Family         | Optional                                       |
| Cells filled   | "342 / 400" (filled / expected)                |
| Min/max width  | "1500 mm / 6000 mm"                            |
| Min/max height | "1500 mm / 4000 mm"                            |
| Match mode     | exact / round_up / nearest                     |
| Status         | `active` / `inactive` / `incomplete`           |

**Detail view:**
- Cells grid with width on rows, height on columns (or vice versa).
- Editable cell values.
- "Add row", "Add column" buttons.
- Stats: filled cells, missing combinations, duplicates.
- Validation: highlight missing cells in the bounding box of min/max.

**Phase 3 scope:** manual cell entry. Excel import = Phase 4.

### 2.6 Rules

**Purpose:** If-this-then-that logic per template.

**List view (per template):**

| Column             | Notes                                       |
|--------------------|---------------------------------------------|
| Rule name          | Owner-written label                         |
| rule_key           | snake_case                                  |
| Condition summary  | "control_type = motorized"                  |
| Action summary     | "show step motor_and_control"               |
| Priority           | Integer                                     |
| Active             | Toggle                                      |
| Last tested        | Timestamp (Phase 4 — preview tester)        |

**Edit form (Phase 3 minimum):**
- Rule name (text)
- rule_key (auto-suggested)
- "Switch to JSON" button → raw spec_json editor (developer mode)
- Default: structured form for simple rules:
  - Condition: pick field → pick operator → enter value
  - Logical group: "all" / "any" / "not" toggle (single level only in Phase 3)
  - Action: pick action type → pick target → enter parameters
- Priority + sort_order
- Active toggle

**Phase 3 scope:** basic CRUD. Multi-level nested conditions and visual
flow builder = Phase 4 (RULES_BUILDER_UX.md).

### 2.7 Diagnostics

**Purpose:** Find broken state. Critical-only in Phase 3.

**Tabs:**
- All issues (default)
- Products
- Templates
- Libraries
- Lookup tables
- Rules

**Per issue:**
- Severity badge
- Title
- Object link
- "Fix link" → jumps to relevant edit screen
- Suggested fix text
- "Mark as known" button (logs ack timestamp; suppresses in next scan)

**Phase 3 scope:** critical issues only. Full diagnostic catalogue = Phase 4.

### 2.8 Settings

**Sub-pages:**

#### 2.8.1 General
- Currency (NOK)
- Measurement unit (mm)
- Price display (incl_vat / excl_vat)
- Lookup match default (exact / round_up / nearest)
- Frontend mode (stepper / accordion) — Phase 4+, hidden in Phase 3
- Server-side validation toggle (default: on)
- Debug mode toggle

#### 2.8.2 Modules
- See §2.5 for the module model. CRUD page for `wp_configkit_modules`.

#### 2.8.3 Logs (Phase 3, basic)
- Read-only view of last 100 log entries.
- Filter by level (debug/info/warn/error/critical).
- Filter by event_type.
- Tools: "Clear logs older than 30 days".

---

## 3. Phase 4+ pages (out of Phase 3 scope)

### 3.1 Imports (Phase 4)

Excel upload wizard. See IMPORT_WIZARD_FLOW.md (later document).

### 3.2 Product Readiness Board (Phase 4)

Advanced bulk view for owners with 50+ products. Drag-drop publish, bulk
binding, side-by-side preview. The Phase 3 Products list is the basic
version; this is the upgrade.

### 3.3 Frontend preview (Phase 4)

In-template "preview as customer" mode. Renders the configurator inside
the admin without going to the public product page.

### 3.4 Cart / Order views (Phase 5)

Inline display of ConfigKit selections in Woo cart and order admin. Not a
new page — modifications to existing Woo screens.

---

## 4. Permissions

### 4.1 WP capabilities

ConfigKit defines these capabilities, mapped to roles by default:

| Capability                        | administrator | shop_manager | content_editor | viewer |
|-----------------------------------|---------------|--------------|----------------|--------|
| `configkit_view_dashboard`        | ✅            | ✅           | ✅             | ✅     |
| `configkit_manage_products`       | ✅            | ✅           | ❌             | ❌     |
| `configkit_manage_templates`      | ✅            | ✅           | ❌             | ❌     |
| `configkit_manage_libraries`      | ✅            | ✅           | ✅             | ❌     |
| `configkit_manage_lookup_tables`  | ✅            | ✅           | ❌             | ❌     |
| `configkit_manage_rules`          | ✅            | ✅           | ❌             | ❌     |
| `configkit_view_diagnostics`      | ✅            | ✅           | ✅             | ✅     |
| `configkit_manage_settings`       | ✅            | ❌           | ❌             | ❌     |
| `configkit_manage_modules`        | ✅            | ❌           | ❌             | ❌     |

**Phase 3 scope:** capability checks at REST controller level. Custom roles
configurable via Settings → Permissions in Phase 4+.

---

## 5. URL structure

All admin pages live under `/wp-admin/admin.php?page=configkit-<slug>`:

| Page              | Slug                       |
|-------------------|----------------------------|
| Dashboard         | `configkit-dashboard`      |
| Products          | `configkit-products`       |
| Templates         | `configkit-templates`      |
| Libraries         | `configkit-libraries`      |
| Lookup Tables     | `configkit-lookup-tables`  |
| Rules             | `configkit-rules`          |
| Diagnostics       | `configkit-diagnostics`    |
| Settings          | `configkit-settings`       |
| Modules           | `configkit-modules`        |
| Logs              | `configkit-logs`           |
| Imports           | `configkit-imports` (Phase 4) |

Detail screens use query params: `?page=configkit-templates&id=42&view=edit`.

---

## 6. Navigation rules

### 6.1 Breadcrumbs everywhere

Every detail page shows breadcrumbs at top:

```
ConfigKit › Templates › Markise Motorisert › Step "Mål"
```

Each segment is a link.

### 6.2 Save-then-redirect

After save:
- "Save and continue editing" → stays on form
- "Save and exit" → redirects to list

After delete:
- Confirmation dialog
- Soft delete by default
- "Delete permanently" requires admin capability + extra confirm

### 6.3 Cross-links

- Product binding screen links to: family, template, lookup table edit pages
- Template builder links to: family, libraries, lookup tables, rules
- Library detail links to: parent module
- Diagnostics issue links to: relevant edit screen

---

## 7. Phase 3 page checklist

Pages to deliver in Phase 3:

- [ ] Dashboard (basic, real data only)
- [ ] Products list + binding edit
- [ ] Templates list + builder
- [ ] Libraries list + library detail + item editor
- [ ] Lookup Tables list + cells editor
- [ ] Rules list + rule editor (basic structured form)
- [ ] Diagnostics (critical only)
- [ ] Settings → General
- [ ] Settings → Modules
- [ ] Settings → Logs

Pages NOT in Phase 3:
- Imports
- Product Readiness Board (advanced)
- Frontend preview
- Custom roles

---

## 8. Acceptance criteria

This document is in DRAFT v1. Awaiting owner review.

Sign-off requires:

- [ ] Top-level menu structure (§1) accepted.
- [ ] Phase 3 in-scope pages (§2) cover owner needs.
- [ ] Phase 4+ deferrals (§3) accepted.
- [ ] Permissions model (§4) accepted as starting point.
- [ ] URL structure (§5) accepted.
- [ ] Page checklist (§7) is the deliverable for Phase 3.
