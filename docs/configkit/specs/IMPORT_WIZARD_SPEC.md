# ConfigKit Import Wizard Specification

|Field         |Value                                                     |
|--------------|----------------------------------------------------------|
|Status        |DRAFT v1                                                  |
|Document type |Import wizard specification                               |
|Companion docs|TARGET_ARCHITECTURE.md, DATA_MODEL.md, PRICING_CONTRACT.md|
|Spec language |English                                                   |
|Affects phases|Phase 4 (import wizard)                                   |

-----

## 1. Purpose

ConfigKit’s import wizard turns owner-friendly Excel files into
structured database rows in `wp_configkit_lookup_cells` (and later,
`wp_configkit_library_items`). This eliminates manual data entry for
large datasets like 200+ pricing cells per markise size grid.

**The owner never writes JSON.** JSON is internal. The owner uploads
`.xlsx`, the system parses, previews, and commits.

-----

## 2. Scope

### 2.1 In scope (Phase 4 priority order)

1. **Lookup table cells import** — width × height × price grids
1. **Library items import** — module-aware rows (e.g., textile catalogs)

### 2.2 Out of scope

- Template structure import (steps + fields + rules) — Phase 5+
- Real-time spreadsheet sync (Google Sheets) — Phase 7+
- CSV/TSV import — only `.xlsx` for v1 (PhpSpreadsheet handles both
  but UX simpler with one format)

-----

## 3. Architectural placement

### 3.1 Top-level menu

ConfigKit → Imports (already in ADMIN_SITEMAP.md §3.1, marked Phase 4)

### 3.2 Inline import buttons

Where it makes sense, each entity detail page has an “Import from
Excel” button that pre-selects the right import type:

- Lookup table detail → “Import cells from Excel”
- Library detail → “Import items from Excel”

Both routes lead to the same import wizard, with the destination
pre-filled.

-----

## 4. Data flow

### 4.1 Three-stage import

```
Upload → Parse + Validate (dry-run) → Preview → Owner commits → Insert
                                                         │
                                                         └─ stored as
                                                            wp_configkit_
                                                            import_batches
                                                            + import_rows
```

### 4.2 Tables involved

Already defined in DATA_MODEL.md §3.15 (`wp_configkit_import_batches`)
and §3.16 (`wp_configkit_import_rows`). Phase 1 created these
tables.

Each upload creates exactly one `import_batch` row. Each parsed row
becomes one `import_row` regardless of validity. The batch state
machine controls progression:

|State       |Meaning                                        |
|------------|-----------------------------------------------|
|`received`  |File uploaded, parser not yet started          |
|`parsing`   |PhpSpreadsheet parsing in progress             |
|`parsed`    |Rows extracted, awaiting validation            |
|`validated` |Validation done; preview can be shown          |
|`committing`|Owner clicked Commit; insert/update in progress|
|`applied`   |Insert/update finished                         |
|`failed`    |Any blocking error; batch can be inspected     |
|`cancelled` |Owner cancelled before commit                  |

### 4.3 Idempotency

Re-running the same file on the same target yields the same result.
The import is idempotent at the row level: each row’s
`(target_table, business_key)` is checked; existing rows are updated,
new rows are inserted, and removed rows are NOT touched (this is
NOT a sync, it is an import).

For a hard reset, owner must explicitly “clear all cells” before
import.

-----

## 5. Lookup table cells import

### 5.1 Two supported formats

The owner can upload Excel in either format:

#### Format A — Grid format (owner-friendly)

Width values across the top row. Height values down the left column.
Prices in the intersecting cells.

Example:

```
            1000   1500   2000   2500
    1000    4990   5490   5990   6490
    1500    5990   6490   6990   7490
    2000    6990   7490   7990   8490
    2500    7990   8490   8990   9490
```

System interprets:

- Top-left corner empty (or labeled “width \ height”)
- First row (after corner) = width values
- First column (after corner) = height values
- Intersecting cells = prices

For tables with **price groups**, the owner can either:

**Option 1** — Multiple sheets, one per price group. Sheet name =
`price_group_key`:

- Sheet “A” → price group A grid
- Sheet “B” → price group B grid

**Option 2** — Single sheet with merged label rows separating groups
(detected via empty rows between blocks):

```
Price group: A
            1000   1500   2000
    1000    4990   5490   5990
    1500    5990   6490   6990

Price group: B
            1000   1500   2000
    1000    5490   5990   6490
    1500    6490   6990   7490
```

#### Format B — Long format (technical)

One row per cell. Header row with required columns:

|lookup_table_key|width_mm|height_mm|price_group_key|price|
|----------------|--------|---------|---------------|-----|
|markise_vika    |1000    |1000     |A              |4990 |
|markise_vika    |1500    |1000     |A              |5490 |
|markise_vika    |1000    |1000     |B              |5490 |

Format B is preferred for:

- Files exported from another system
- Large tables (1000+ cells)
- Tables with many price groups

### 5.2 Format detection

Parser auto-detects format on upload:

- If headers row 1 contains numeric values OR “Price group:” tag →
  Format A
- If headers row 1 contains the strings `lookup_table_key`,
  `width_mm`, `height_mm`, `price` (case-insensitive) → Format B
- If ambiguous → ask owner to confirm via a dropdown in preview

### 5.3 Required columns (Format B)

- `lookup_table_key` — must match an existing lookup table OR be
  pre-selected via UI dropdown (then this column is optional)
- `width_mm` — integer > 0
- `height_mm` — integer > 0
- `price` — decimal ≥ 0

Optional columns:

- `price_group_key` — string, snake_case (only required if any cell
  has a value)
- `is_active` — bool (default true)

### 5.4 Pre-import options (UI before upload)

- **Target lookup table** — pre-select if uploading from a lookup
  table detail page; otherwise pick from dropdown
- **Mode** — “Replace all cells” (delete then insert) /
  “Insert/update only” (idempotent, no deletes) — default insert/update
- **Match unit** — mm/cm/m, defaulted from lookup table’s `unit`
  setting; used if Excel has a different unit (e.g., owner enters cm,
  system normalizes to mm)

-----

## 6. Library items import

### 6.1 Format

Always long format. One row per item. Required + optional columns
depend on the module’s `attribute_schema_json` and capability flags.

### 6.2 Required columns (always)

- `library_key` — must match an existing library OR be pre-selected
- `item_key` — snake_case, unique within library
- `name` — display label

### 6.3 Capability-driven columns

For each capability the module supports:

|Module capability          |Required column |Optional column     |
|---------------------------|----------------|--------------------|
|`supports_sku`             |`sku`           |                    |
|`supports_image`           |`image_url`     |`image_alt`         |
|`supports_main_image`      |`hero_image_url`|                    |
|`supports_price`           |`price`         |`currency`          |
|`supports_sale_price`      |                |`sale_price`        |
|`supports_filters`         |                |`filter_tags`       |
|`supports_compatibility`   |                |`compatibility_tags`|
|`supports_price_group`     |                |`price_group_key`   |
|`supports_brand`           |                |`brand`             |
|`supports_collection`      |                |`collection`        |
|`supports_color_family`    |                |`color_family`      |
|`supports_woo_product_link`|                |`woo_sku`           |

Custom attributes (per `attribute_schema_json`) become additional
columns named after the schema key.

### 6.4 Pre-import options

- **Target library** — pre-select if from library detail page
- **Mode** — Replace all / insert-update only
- **Auto-create missing** — if a value references a non-existent
  reference (e.g., `color_family` not yet created), auto-create or
  fail

-----

## 7. Wizard flow

Step-by-step UX:

### 7.1 Step 1 — Pick destination

- “Import what?”
  - Lookup table cells
  - Library items
- “Target?” — dropdown of existing tables/libraries (or “Create new”)

### 7.2 Step 2 — Upload file

- Drag-drop area + “Choose file” button
- Accept: `.xlsx` only
- Max size: 10 MB (configurable)
- On upload: file validated for Excel format, parsed in background

### 7.3 Step 3 — Preview

After parsing, the wizard shows:

```
┌─ Import preview ──────────────────────────────────────┐
│                                                       │
│  Target: Lookup table "Markise VIKA pricing"          │
│  Format detected: Grid (Format A)                     │
│                                                       │
│  ✓ 47 rows parsed                                    │
│  ✓ 45 valid                                          │
│  ⚠ 2 warnings (duplicate cells)                      │
│  ✗ 0 errors                                          │
│                                                       │
│  Width range: 1000 – 5000 mm                          │
│  Height range: 1000 – 4000 mm                         │
│  Price range: 4990 – 18900 NOK                        │
│  Price groups detected: A, B (2)                      │
│                                                       │
│  Action on commit:                                    │
│  • Insert 30 new cells                                │
│  • Update 15 existing cells                           │
│  • Skip 2 duplicates (in same file)                   │
│                                                       │
│  [Show details ▼]  [Cancel]  [Commit import]          │
│                                                       │
└───────────────────────────────────────────────────────┘
```

“Show details” expands to show the full row-by-row table, with row
status badges (valid/warning/error) and per-row error messages.

### 7.4 Step 4 — Commit

Owner clicks “Commit import”:

1. Backend transitions batch state → `committing`
1. Insert/update rows into `wp_configkit_lookup_cells` (or
   `wp_configkit_library_items`)
1. On error: rollback batch, mark `failed`, preserve
   `import_rows` for inspection
1. On success: mark `applied`, redirect to lookup table detail

### 7.5 Step 5 — Result

Result page shows:

- Summary (X cells imported)
- Link to view the populated lookup table
- “Import another file” button

-----

## 8. Validation rules

### 8.1 Per-row validation (during parse)

- Numeric fields must be parseable
- `width_mm`, `height_mm` must be > 0
- `price` must be ≥ 0 (negative prices rejected)
- `price_group_key` must match snake_case if provided
- Row must not be entirely empty
- Cell coordinates within reasonable range (e.g., width < 100,000 mm)

### 8.2 Cross-row validation

- Duplicate `(width_mm, height_mm, price_group_key)` within file →
  warning (last value wins, but flag it)
- Missing dimensions in grid (gaps) → warning
- Detected `price_group_key` values consistent across blocks

### 8.3 Cross-target validation

- If “insert/update” mode and existing cells have different
  `price_group_key` not in the file, those cells are NOT touched
  (per idempotency rule §4.3)
- If “replace all” mode, those cells WILL be deleted

-----

## 9. Error handling

### 9.1 Parse-time errors

If PhpSpreadsheet can’t open the file (corrupt, password-protected,
unsupported format):

- Batch state → `failed`
- Show error to owner: “Could not read Excel file. Make sure it’s
  saved as .xlsx and not password-protected.”
- Provide “Try another file” button

### 9.2 Validation-time errors

Any row-level errors are stored in `import_row.errors_json`:

```json
{
  "errors": [
    {"field": "width_mm", "message": "Not a number: '15O0' (letter O instead of zero)"}
  ]
}
```

Preview shows error count + per-row drill-down.

### 9.3 Commit-time errors

If insert fails (DB constraint violation, etc.):

- Rollback transaction
- Batch state → `failed`
- Per-row error stored
- Owner can re-attempt with corrections

-----

## 10. Performance

For files up to 5,000 rows (e.g., 50×50×2 = 5,000 cell grid with 2
price groups):

- Parse + validate: < 5 seconds
- Commit: < 10 seconds

Larger files (10,000+ rows) require background processing (Action
Scheduler or wp-cron). Phase 4 minimum: synchronous up to 5,000
rows. Phase 5+ async for larger.

-----

## 11. UI components

### 11.1 Upload widget

- Standard HTML5 file input + drag-drop zone
- Progress indicator during upload
- File size display
- Clear/cancel button

### 11.2 Preview table

- Sortable by column
- Filterable by status (valid/warning/error)
- Pagination if > 100 rows
- Per-row “Show errors” expand

### 11.3 Commit confirmation modal

```
┌─ Confirm import ─────────────────────────────────────┐
│                                                      │
│  This will:                                          │
│  • Insert 30 new cells                               │
│  • Update 15 existing cells                          │
│                                                      │
│  This action cannot be undone.                       │
│                                                      │
│  [Cancel]  [Yes, import]                             │
│                                                      │
└──────────────────────────────────────────────────────┘
```

-----

## 12. Forbidden patterns

- Owner must NEVER manually write or edit JSON to import data
- Importing directly without preview is FORBIDDEN
- Silent skipping of invalid rows is FORBIDDEN — every row goes to
  `import_rows` with status
- Fake upload buttons (UI without backend) are FORBIDDEN
- The “Replace all” mode is OPT-IN only and requires explicit
  confirmation

-----

## 13. PHPUnit test coverage (Phase 4)

- `tests/unit/Service/ImportParserTest.php`
  - Format A grid parsing
  - Format B long parsing
  - Multi-sheet price group parsing
  - Single-sheet price group parsing with separator rows
  - Format auto-detection
  - Reject non-numeric in numeric fields
  - Reject negative prices
  - Reject duplicates within file (warning)
- `tests/unit/Service/ImportRunnerTest.php`
  - Batch state transitions
  - Idempotent re-import
  - Replace-all mode
  - Insert-update mode
  - Rollback on commit error
- `tests/integration/ImportEndToEndTest.php`
  - Upload XLSX, parse, preview, commit, verify cells in DB

-----

## 14. Phase 4 deliverables

For Phase 4 import wizard chunk, deliver:

- [ ] PhpSpreadsheet dependency added to composer.json
- [ ] Parser supporting Format A (grid) and Format B (long)
- [ ] Multi-sheet + single-sheet price group support
- [ ] Format auto-detection with manual override
- [ ] `ConfigKit\Import\Parser` service
- [ ] `ConfigKit\Import\Runner` service for state machine
- [ ] REST endpoints under `configkit/v1/imports`
- [ ] Wizard UI in admin (4 steps)
- [ ] Batch listing page (admin → Imports)
- [ ] Per-batch detail page (rows + errors)
- [ ] Idempotency at row level
- [ ] All forbidden patterns enforced
- [ ] PHPUnit + integration tests

-----

## 15. Acceptance criteria

This document is in DRAFT v1. Awaiting owner review.

Sign-off requires:

- [ ] Two supported formats (§5.1) cover all real Sologtak files.
- [ ] Pre-import options (§5.4) cover normal owner flow.
- [ ] Wizard UX (§7) accepted.
- [ ] Storage in `wp_configkit_import_batches` + `import_rows` confirmed.
- [ ] Forbidden patterns (§12) are the law.
- [ ] Phase 4 deliverables (§14) accepted.

After approval, Phase 4 import wizard chunk implements per this spec.
