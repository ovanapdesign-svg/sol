# ConfigKit Status

Single source of truth for feature status. Updated on every PR that changes
status.

Statuses:
- `complete` — passes full DoD checklist
- `partial` — works for stated scope; gaps listed
- `wip` — under active development, do not use
- `broken` — known broken, do not use
- `pending` — not yet started

---

## Phase 0 — Scaffold + specs

| Item                                         | Status   | Notes                              |
|----------------------------------------------|----------|------------------------------------|
| Plugin scaffold (configkit.php)              | complete | Header, ABSPATH guard, version constants |
| GitHub repo connected                        | complete | git@github.com:ovanapdesign-svg/sol.git |
| WP install on demo.ovanap.dev/sol1           | complete | Tomas verified                     |
| WooCommerce installed                        | pending  | WooCommerce must be installed and active before Phase 3 Admin UI starts. Currently pending — owner will install via `wp plugin install woocommerce --activate` before Phase 3 entry. |
| TARGET_ARCHITECTURE.md (Batch 1)             | complete | DRAFT v2 — approved 2026-04-29     |
| DATA_MODEL.md (Batch 1)                      | complete | DRAFT v2 — approved 2026-04-29     |
| FIELD_MODEL.md (Batch 1)                     | complete | DRAFT v2 — approved 2026-04-29     |
| MODULE_LIBRARY_MODEL.md (Batch 1)            | complete | DRAFT v2 — approved 2026-04-29     |
| RULE_ENGINE_CONTRACT.md (Batch 2)            | pending  |                                    |
| PRICING_CONTRACT.md (Batch 2)                | pending  |                                    |
| TEMPLATE_VERSIONING.md (Batch 2)             | pending  |                                    |
| MIGRATION_STRATEGY.md (Batch 2)              | pending  |                                    |
| MULTILANGUAGE_MODEL.md (Batch 2)             | pending  |                                    |
| AUDIT_CHECKLIST.md (Batch 2)                 | pending  |                                    |
| UX roadmap (8 docs)                          | pending  |                                    |

**Phase 0 exit:** Owner approved Batch 1 v2 on 2026-04-29. All four Batch 1
specs (TARGET_ARCHITECTURE, DATA_MODEL, FIELD_MODEL, MODULE_LIBRARY_MODEL)
are marked `complete`. Phase 0 closed; awaiting owner approval to enter
Phase 1.

---

## Phase 1 — Schema migrations

| Item                                                | Status   | Notes                                                                  |
|-----------------------------------------------------|----------|------------------------------------------------------------------------|
| Plugin bootstrap + Composer/PSR-4 autoloader        | complete | `configkit.php`, `src/Plugin.php`, `composer.json`. Fallback autoloader if `vendor/` absent. |
| Migration interface + Runner                        | complete | `src/Migration/Migration.php`, `src/Migration/Runner.php`. Uses `microtime(true)`; logs applied/failed to `wp_configkit_migrations`. |
| 16 migration files (0001–0016)                      | complete | Schemas per `DATA_MODEL.md §3`. No seed migration — `wp_configkit_modules` ships empty (owner adds modules in Phase 3). |
| WP-CLI command `wp configkit migrate`               | complete | `src/CLI/Command.php`. Supports `--dry-run` and `--status`.            |
| PHPUnit unit tests                                  | complete | `tests/unit/Migration/RunnerTest.php`. 4 tests / 16 assertions, green. |
| Apply migrations on staging DB                      | complete | Owner verified 2026-04-29. `wp_configkit_migrations` shows 16 rows, all `status='applied'`. |
| Verify idempotent re-run                            | complete | Second run of `wp configkit migrate` reported no pending migrations.   |
| Verify tables via `SHOW TABLES LIKE 'wp_configkit%'` | complete | 16 tables present on staging.                                          |

**Phase 1 verified on staging 2026-04-29.** 16 tables created, migration
runner operational, idempotent confirmed. Total migration duration on
staging: ~140 ms. `wp_configkit_modules` is empty by design — owner will
add modules in Phase 3.

---

## Phase 2 — Engines

### Phase 2 questions

Resolved before implementation per owner instruction "If the spec is
ambiguous, document the chosen behavior in STATUS.md before implementing
it." Both Batch 2 specs are DRAFT v1; chosen interpretations:

1. **VAT rate source.** `PRICING_CONTRACT.md §11` says the engine delegates
   rate resolution to Woo at compute time. The Phase 2 prompt forbids WP
   function calls inside engines. → Engine accepts
   `config.vat_rate_percent` as input. A non-engine caller resolves the
   rate from Woo and passes it in.
2. **Addon product data.** `PRICING_CONTRACT.md §16` references Woo
   stock / orphan checks. → Caller pre-resolves addons into
   `addon_products_resolved` keyed by SKU with
   `{ label, price, available, product_id }`. Engine treats
   `available = false` as a block.
3. **Library item resolution.** `PRICING_CONTRACT.md §3 / §9` reference
   library item prices and sale prices. → Caller pre-resolves selected
   library items into `library_items_resolved` keyed by
   `library_key:item_key` with `{ label, price, sale_price, price_group_key }`.
4. **Lookup cell data.** Phase 2 prompt explicitly says `LookupEngine`
   takes `cells_data` as input. → Engine never reads
   `wp_configkit_lookup_cells` directly.
5. **Reset cascade — `options_filter excludes value` trigger.**
   `RULE_ENGINE_CONTRACT.md §6.4` lists four reset triggers; one requires
   evaluating filters against actual library / source data the engine
   does not have. → Engine implements three of four triggers
   (`visible = false`, `reset_value` action targeted, `disabled_options`
   includes selected value). The fourth is recorded as a filter on
   `fields[*].options_filter`; enforcement is the renderer's or a later
   server-side validation pass's responsibility.
6. **Conflict resolution — same priority.** `RULE_ENGINE_CONTRACT.md §6.5`
   says "the one with the higher `sort_order` wins". → Rules sorted by
   `priority` ascending, then `sort_order` ascending. Later rules
   overwrite earlier ones, so a higher `sort_order` wins. Precedence is
   implemented; spec-mentioned `rule.conflict` diagnostic marker is
   deferred (detection requires per-bucket field-touch tracking, not
   needed for correctness — only diagnostics).
7. **Width / height fields for `per_m2`.** `PRICING_CONTRACT.md §4`
   defines `per_m2 = amount × (width × height) / 1,000,000` but does not
   say how the engine identifies width/height fields. → Caller passes
   `width_field_key` and `height_field_key` in pricing input. Missing →
   `per_m2` fields contribute 0 and a
   `pricing.per_m2_dimensions_missing` warning is emitted.
8. **Minimum price floor.** `PRICING_CONTRACT.md §12` references a
   template-level minimum price stored in a column that does not yet
   exist in `DATA_MODEL.md §3.5`. → Engine accepts
   `config.minimum_price` (float|null) as input. Caller passes `null`
   until the column lands.
9. **`spec` vs `spec_json` in rule input.** `RULE_ENGINE_CONTRACT.md §6.1`
   implies parsed rules. → Each rule input carries `spec` (already-decoded
   array). Callers `json_decode` `spec_json` from the DB and supply
   `spec`.
10. **`set_default` ordering.** `RULE_ENGINE_CONTRACT.md §6.3` step 5
    applies defaults after the reset cascade. → `set_default` actions
    are queued during rule application, not applied in place; only
    applied after cascade if the field's effective value is null/empty.

### Progress

| Item                                                | Status   | Notes                                                                  |
|-----------------------------------------------------|----------|------------------------------------------------------------------------|
| `ConfigKit\Engines\RuleEngine`                      | complete | All operators (equals/not_equals/greater_than/less_than/between/contains/in/not_in/is_selected/is_empty/all/any/not/always); all 12 actions; single-pass eval; reset cascade for hidden fields and disabled selections; missing-target marker. |
| `ConfigKit\Engines\LookupEngine`                    | complete | Three match strategies (exact, round_up, nearest); 2D and 3D matching; price-group filtering; reasons `no_cell` and `exceeds_max_dimensions`. |
| `ConfigKit\Engines\PricingEngine`                   | complete | All pricing modes; hidden-field exclusion; sale-price precedence; rule surcharges (amount + percent_of_base); minimum price floor; negative clamp; rounding (4 modes × any step); VAT (incl/excl/off); Woo addon availability check; lookup mismatch blocks. Uses LookupEngine via DI. |
| `ConfigKit\Engines\ValidationEngine`                | complete | Effective-required logic (FIELD_MODEL §11); type coercion (number→int/float, scalar→array for checkbox, array dropped for radio/dropdown/text); blocked-by-rule propagation. |
| PHPUnit unit tests                                  | complete | 78 tests / 163 assertions, all green. Covers each engine spec section with positive and negative cases. |
| Engine purity                                       | complete | `grep -rE "wp_\|get_option\|WP_Query\|\\$wpdb" src/Engines/` returns zero matches. |
| `declare(strict_types=1)` everywhere in `src/Engines/` | complete |                                                                        |

**Phase 2 status:** code complete, all tests green, engines pure. No DB
writes, no WP function calls inside engine methods.

---

## Phase 3 — Admin UI core

**Status:** in progress, partial. Foundation + 2 of 10 in-scope pages
landed in this session; remaining work is multi-session (the template
builder alone is multi-session). Owner direction needed on next-chunk
priority.

### Phase 3 questions

1. **Custom roles `content_editor` / `viewer`.** `ADMIN_SITEMAP.md §4.1`
   maps caps to four roles, but `content_editor` and `viewer` are not
   standard WP roles. → Phase 3 populates `administrator` and
   `shop_manager` only; custom-role provisioning is deferred to Phase 4
   per the spec ("Custom roles configurable via Settings → Permissions
   in Phase 4+").
2. **Frontend mode select on Settings → General.** Spec says "Phase 4+,
   hidden in Phase 3". → Field omitted from the General settings form.
3. **Plugin options vs entity REST.** WP has two reasonable patterns
   (Settings API for plugin options; custom REST for entity CRUD). →
   Phase 3 uses WP Settings API for plugin-level options
   (Settings → General) and the custom `configkit/v1/*` REST namespace
   for entity CRUD (modules, libraries, etc.) once those land.
4. **Families form: schema vs. brief.** The Families CRUD chunk brief
   listed `family_kind` (5-value dropdown) and `sort_order` form
   fields, but `DATA_MODEL.md §3.4` / migration 0005 do not declare
   those columns. Editing specs in `docs/configkit/specs/` is
   forbidden in Phase 3, so this chunk shipped only schema-backed
   fields (name, family_key, description, is_active). Pending owner
   decision: (a) approve an additive migration 0017 that adds
   `family_kind VARCHAR(32)` + `sort_order INT` (with indexes) and
   updates `DATA_MODEL.md §3.4` in the same approved edit, then
   re-enable the form fields; or (b) drop `family_kind` /
   `sort_order` from the brief. Other §3.4 columns
   (`default_template_key`, `allowed_modules_json`,
   `default_step_order_json`) are deferred until Templates CRUD
   exists, since they reference templates and modules.
5. **Product binding: where validation ends and diagnostics begins.**
   `PRODUCT_BINDING_SPEC.md §10` lists 11 readiness checks for the
   status badge. ProductBindingService.validate intentionally accepts
   dangling references (unknown template_key / lookup_table_key /
   library_key, defaults that point at since-deleted fields) so an
   owner can save partial state and fix forward. All cross-reference
   verification lives in ProductDiagnosticsService.run, which the UI
   refreshes on load + after every save. Decision recorded so a future
   reader does not "tighten" the binding service and break the
   half-saved-state workflow.
6. **Phase 3 minimums for two diagnostic checks.** Two checks are
   shipped at the minimum bar permitted by the spec:
   - `excluded_items_format` (§10.1.6) verifies `library_key:item_key`
     format only; full existence verification waits for a unified
     library-items index that is out of scope here.
   - `price_resolvable` (§10.1.8) accepts when `cells_ok` and there are
     either width/height defaults or no defaults yet; it does not run
     the full LookupEngine. PricingEngine is pure but reaching it from
     here would require synthesizing a config snapshot — owner-confirm
     before doing that work.

### Progress

| Item                                                  | Status   | Notes                                                                  |
|-------------------------------------------------------|----------|------------------------------------------------------------------------|
| Capability registration on activation                 | complete | `Capabilities\Registrar` — 9 caps mapped to administrator + shop_manager. |
| Admin menu shell                                      | complete | `Admin\Menu` — incremental sub-page registration; no fake / placeholder pages. |
| AbstractPage base + capability guard                  | complete |                                                                        |
| Asset loader + admin.css / admin.js bootstrap         | complete | `ConfigKit.request()` wraps `fetch` with REST URL + wp_rest nonce.     |
| REST namespace + `AbstractController` + `Router`      | complete | `configkit/v1`. Controllers added per chunk.                           |
| `CountsService` (read-only)                           | complete | Used by Dashboard. Table-existence guards so renders before/between migrations. |
| Dashboard page (real-data counts)                     | complete | No fake activity entries; section hidden when no data.                 |
| Settings → General                                    | complete | 6 fields per `ADMIN_SITEMAP §2.8.1` via WP Settings API. Allow-list sanitization. Frontend mode deferred. |
| Settings → Modules CRUD                               | complete | `ModuleRepository`, `ModuleService`, `ModulesController`, `ModulesPage`, `modules.js`. Pattern-establishing chunk. Save now redirects to list with one-shot success toast. |
| Settings → Logs (read-only viewer)                    | pending  |                                                                        |
| Libraries list + detail + item editor                 | complete | Two repositories, two services, two REST controllers, single page with four JS views (list / library form / library detail / item form). Capability-conditional fields driven by parent module. attribute_schema enforcement for items. |
| Lookup Tables list + cells editor                     | complete | Two repositories, two services, two REST controllers (incl. POST /cells/bulk for Phase-4 grid editor). Phase 3 cell editor is paginated table + form (pivot grid deferred to Phase 4). Cells use real DELETE; tables use soft-delete + version_hash. supports_price_group → cells_have_price_groups guard prevents orphaning grouped cells. |
| Families CRUD                                         | complete | Pattern copy. New capability `configkit_manage_families` (admin + shop_manager). `CAPS_VERSION` bumped to 2 so the admin_init safety net replays registration. Form ships only schema-backed fields — see Phase 3 question 4 below for the family_kind / sort_order gap. |
| Products list + binding edit                          | complete | ProductBindingRepository (only `get_post_meta`/`update_post_meta` toucher in code; 10 storage keys per `PRODUCT_BINDING_SPEC.md §12` + version_hash + updated_at), ProductBindingService (validates frontend_mode/sale_mode/vat_display allow-lists, numeric pricing fields, structural shape of allowed_sources / pricing_overrides / field_overrides — cross-refs deferred to diagnostics), ProductDiagnosticsService (all 11 readiness checks per §10.1 + §10.3 status derivation), ProductsController (4 endpoints: GET /products, GET+PUT /products/{id}/binding, POST /products/{id}/diagnostics, GET /products/{id}/template-fields), WooIntegration (Woo product-data tab + panel shell), ProductsPage (read-only-with-jumps overview), product-binding.js (8 sections: enable/status badge, base setup, defaults, allowed sources, pricing overrides, visibility/locking, diagnostics, preview placeholder; sticky save bar; deep-link `#configkit_product_data` fragment), products.js (filterable overview list with deep-link "Edit binding" buttons). Diagnostics-derived status badge: ready / disabled / missing_template / missing_lookup_table / invalid_defaults / pricing_unresolved. |
| Templates B1: list + metadata CRUD                    | complete | Pattern copy. Soft delete sets `status='archived'` (schema has no `is_active`; the brief's `is_active` checkbox was substituted with the status dropdown per `DATA_MODEL.md §3.5`). family_key is optional and KeyValidator-checked when provided. "Open template" routes to a placeholder detail view; no fake builder. |
| Templates B2: steps CRUD inside template              | complete | StepRepository / StepService / StepsController + reorder endpoint. Detail view replaced placeholder with single-column header + Steps panel. step_key unique per template (different templates can share keys). Real DELETE (no is_active in schema; B5 publish will snapshot history). Reorder UI is up/down arrow buttons; HTML5 drag-drop deferred to B3 with the three-pane layout. |
| Templates B3: fields CRUD + 3-pane builder layout     | complete | FieldRepository, FieldOptionRepository, FieldService (full `FIELD_MODEL.md §8` axis-combination matrix + per-source `source_config` validation), FieldOptionService, FieldsController, FieldOptionsController. Detail view is a three-pane CSS-grid (steps / fields / settings) collapsing to single-column below 1024px. 3-step modal wizard for field creation (owner-friendly choices; no raw axis labels). Right-pane field editor: Basics, Source, Display style, Pricing, "Show in" flags, Required+default, Advanced. Manual options inline editor. Reorder via up/down buttons. fields use real DELETE; field_options soft-delete (per schema). Mobile tab-bar layout deferred. |
| Templates B4: rules drawer (basic CRUD)               | complete | RuleRepository + RuleService (full RULE_ENGINE_CONTRACT.md schema validator: top-level shape, atomic / all / any / not / always conditions, per-action shape, operator-specific value shape) + RulesController + drawer UI in templates.js (structured editor for flat AND/OR + single NOT, JSON mode for nested groups, inline Active toggle in list, summarized WHEN / THEN columns). Cross-ref validation against live template fields / steps / manual options; errors carry a `path` field pointing at the offending JSON location. Visual nested-group builder + rule preview deferred to Phase 4. |
| Templates B5: publish workflow + version snapshot     | complete | TemplateVersionRepository + TemplateValidator (pre-publish drift catcher per `TEMPLATE_BUILDER_UX.md §9.3`) + TemplateVersionService (snapshot builder + publish) + TemplateVersionsController (`/validate`, `/publish`, `/versions`, `/versions/{vid}`). Detail toolbar gains Validate / Publish v(N+1) / Versions buttons + a "Published vN" badge. Validate panel surfaces errors (red) and warnings (yellow). Publish runs validation, refuses on errors, opens a confirmation modal on clean, then snapshots and increments. Versions drawer lists immutable snapshots; View action shows read-only JSON. **Templates is feature-complete per `TEMPLATE_BUILDER_UX.md §14`.** |
| Rules basic CRUD                                      | pending  |                                                                        |
| Diagnostics (critical issues only)                    | complete | LogRepository (read/write `wp_configkit_log`; microsecond-precision `created_at`; ack-index builder), SystemDiagnosticsService (eight checks per `ADMIN_SITEMAP.md §2.7` + `OWNER_UX_FLOW.md §8` Flow F: products_missing_template, products_missing_lookup_table, templates_no_published_version, templates_no_steps, lookup_tables_empty, library_items_orphaned, modules_no_field_kinds, rules_broken_targets — six critical, two warning), DiagnosticsController (3 endpoints: GET /diagnostics, POST /diagnostics/refresh, POST /diagnostics/acknowledge), DiagnosticsPage shell, diagnostics.js (tabs by object_type + Re-scan + "Show all (incl. acknowledged)" toggle + per-issue Fix/Mark-as-known). Acknowledgements stored as `wp_configkit_log` rows with `event_type='diagnostic_acknowledged'` and `context_json` carrying issue_id / object_type / object_id / note (per chunk brief). Capability `configkit_view_diagnostics` (already shipped on activation). |
| Optimistic locking via `version_hash` everywhere      | partial  | Wired for Modules, Libraries, Library Items, Lookup Tables, Families, Templates, Steps, Fields, Field Options, Rules (returns 409 on stale hash). Lookup cells intentionally have no version_hash per schema. Other entities wire on landing. |
| Save-button freeze guard                              | complete | All save handlers (Modules, Libraries, Library Items, Lookup Tables, Families, Templates, Steps, Fields, Field Options) use try/catch/finally; showError() is defensive against missing `ConfigKit.describeError`. Button always re-enables on validation error. |
| Capability auto-assign on activation + safety net     | complete | `Capabilities\Registrar::register / deregister / ensure_registered`. `register_deactivation_hook` clears caps + version flag; `admin_init` re-runs registration once when option `configkit_caps_version` ≠ current. Bumped to v2 with the addition of `configkit_manage_families`. |
| Shared key validation (`Validation\KeyValidator`)     | complete | One source of truth for `*_key` rules across Modules, Libraries, Library Items, Lookup Tables, Families: 3–64 chars, lowercase ASCII, must start with letter, reserved-word block list. |
| User-friendly REST error display                      | complete | `ConfigKit.describeError()` in admin.js maps 404 / 401-403 / 409 / 400-422 / 5xx to natural-language messages. Each banner has a "Show technical details" collapsible with the raw message + code + status. |

**Engine purity preserved.** `grep -rE "wp_\|get_option\|WP_Query\|\\$wpdb" src/Engines/`
returns zero matches. Phase 2 + 3 + 3.5 + 3.6 + 4 PHPUnit tests green
(442 / 1125).

**Phase 3 status.** Seven of seven in-scope entities are landed
(Modules, Libraries, Lookup Tables, Families, Templates, Products,
Diagnostics). The Rules CRUD page row above is "pending" because the
top-level "Rules" admin page is intentionally deferred — rules are
authored inside the Templates rules drawer (B4) and that surface is
feature-complete; a standalone cross-template rules dashboard is a
Phase 4 polish item, not a blocker. Settings → Logs viewer remains
pending and is the only deferred Phase 3 item. Phase 3 is feature-
complete; Phase 3.5 polish landed on top.

---

## Phase 3.5 — Admin UX polish

CSS / JS / minor PHP refinements after Phase 3 backend feature-
completion. No schema migrations, no REST contract changes, engines
untouched.

| Item                                                  | Status   | Notes                                                                  |
|-------------------------------------------------------|----------|------------------------------------------------------------------------|
| Mobile responsive tables → stacked cards              | complete | All `wp-list-table` instances inside `.configkit-app` collapse below 768px via CSS-only `display:block` + `td[data-label]::before`. Title cells render larger / unprefixed; action cells stack with full-width buttons. Form footers + the product-binding savebar stick to the viewport bottom on mobile. data-label attributes added to td cells across modules / libraries / library items / lookup tables / cells / families / templates / products lists. |
| Helper tooltips for capabilities + technical concepts | complete | `ConfigKit.help()` shared helper renders a small (?) badge with native title-tip on desktop and tap-to-toggle popout on touch. Wired into all twelve Modules `supports_*` capabilities, the five `allowed_field_kinds`, all eight Library item capability-driven Properties fields, and the Lookup Tables match_mode + supports_price_group. |
| Collapsible form sections                             | complete | `ConfigKit.makeCollapsible()` upgrades any fieldset whose body lives in `.configkit-fieldset__body`. Each entity's `fieldset()` helper now takes `{ collapsible, collapsed }` opts. Default-collapsed: Modules → Capabilities + Allowed field kinds (in edit mode), Lookup tables → Bounding box, Library item form → Tags + Attributes. New-record forms keep advanced sections open so the form is still the configuration surface. |
| Module type preset cards                              | complete | `src/Admin/ModuleTypePresets.php` declares 5 presets (Textiles, Colors, Motors, Accessories, Custom) with capability + allowed_field_kinds seeds. `apply_to_payload()` is called by `ModulesController::create` whenever the POST body carries `module_type` — owner-supplied values always beat preset seeds. The Modules JS shows the preset grid as Step 1 of the Create flow; picking a card seeds `state.editing` then jumps to the form. Edit flow skips presets. 13 new PHPUnit tests cover every preset + the apply_to_payload paths. |
| Empty state CTAs                                      | complete | `ConfigKit.emptyState()` shared component renders a centered icon + title + 1-line message + primary button (and optional secondary link). Wired into Modules / Libraries / Lookup Tables / Families / Templates list pages, plus Product binding sections 3 / 4 / 6 (defaults / allowed sources / visibility) where the empty state pushes the owner back to the template picker via a smooth-scroll button. Libraries empty + no modules nudges the owner to Modules first. |
| Conditional fields                                    | complete | Product binding section 5: the Discount % input only renders when Sale mode = `discount_percent`. Switching off `discount_percent` clears any stored discount value to keep the saved JSON tidy. Library item form already conditional on module capabilities. |
| Soft warnings on generic keys                         | complete | `ConfigKit.softKeyWarnings()` flags test_ / tmp_ / demo_ / placeholder prefixes, dictionary-ish ≤4-char stems (foo/item/red/dar/etc.), and substring-overlap with existing keys in the same table. Renders as a yellow inline `.configkit-soft-warnings` notice below the key input on the Create form. Save is **never** blocked — warnings are advisory. Wired for module_key, family_key, library_key, lookup_table_key, template_key. Edit forms skip warnings since keys are immutable post-save. |

---

## Phase 3.6 — Owner workflow clarity

Shopify-style guidance pass on top of Phase 3.5 polish. No schema,
no REST contract changes, engines untouched.

| Item                                                  | Status   | Notes                                                                  |
|-------------------------------------------------------|----------|------------------------------------------------------------------------|
| Breadcrumbs on every admin surface                    | complete | `src/Admin/Breadcrumb.php` + AbstractPage::breadcrumb_segments() default to `ConfigKit › <menu_title>`; ModulesPage adds Settings, DashboardPage shows the root only. WooIntegration emits `WooCommerce › Products › Edit "<title>" › ConfigKit` at the top of the product-data tab. JS-side `ConfigKit.subBreadcrumb()` injects a tail under the server breadcrumb so form views announce "Modules › Edit X" without round-tripping the server. Wired across modules / libraries / lookup-tables / families / templates render functions. |
| Dashboard smart "Next step" guidance                  | complete | DashboardPage gains a SystemDiagnosticsService dependency and computes a 7-state machine from the existing CountsService snapshot + critical-issue count: modules-empty / libraries-empty / lookups-empty / templates-empty / products-empty / issues-present / ready. Big gradient hero card with primary CTA leads the page; the existing six counters move below as a smaller "Overview" block. Red gradient when issues are present, green when ready. |
| Owner-friendly visible labels                         | complete | DB columns / REST fields / JS state keys all unchanged — only HTML labels change. Modules: 12 capability checkboxes get sentence-style labels ("Item has a unique code (SKU)"); Capabilities → "What items in this module can store"; Allowed field kinds → "Where this module can be used". `*_key` inputs all renamed to "Technical key" with explanatory helper text. Lookup table match_mode dropdown values are friendly: exact → "Exact match (must equal)", round_up → "Round up to next size (recommended)", nearest → "Closest size". List table column "*_key" headers (and matching mobile data-label attrs) all renamed to "Technical key". |
| Page intro boxes + page action bars                   | complete | `src/Admin/PageHeader.php` + AbstractPage::open_wrap_with_header() render a consistent H1 + subtitle + primary CTA + "← Back to dashboard" secondary link, followed by a muted ⓘ-prefixed intro box explaining the page in 1–2 sentences. Wired across Modules / Libraries / Lookup Tables / Templates / Families / Diagnostics / Products. Intro boxes carry a "Got it, hide this" link wired through admin.js → localStorage keyed by data-intro-id (UI memory only, never business data). |
| Diagnostics owner-readable titles + suggested fixes   | complete | ProductDiagnosticsService gains TITLES + SUGGESTED_FIXES maps for all 11 product checks; SystemDiagnosticsService gains SUGGESTED_FIXES for all 8 system checks. Each check / issue now emits `title` (owner-readable label), `suggested_fix` (concrete next action, null when passed), and `fix_link` (alias of fix_url so JS can use either name). diagnostics.js + product-binding.js render the suggested fix as a blue-tinted inline panel beneath the message. |
| Product tab setup progress checklist + locked sections | complete | The Woo product ConfigKit tab opens with a 5-step checklist (Enable → Select template → Select lookup table → Run diagnostics → Save binding) with green-check / yellow-clock / gray-circle states. Each step click smooth-scrolls to its anchor. Sections 3 / 4 / 6 (defaults / allowed sources / visibility) and 8 (preview) get visually disabled (`opacity: 0.55; pointer-events: none`) until their prerequisites are met (template_key set; section 8 unlocks only when diagnostic status = "ready"). Pricing (5) + Diagnostics (7) remain always available. |

---

## Phase 4 — Excel Import + Diagnostics + Product Readiness Board

| Item                                                     | Status   | Notes                                                                  |
|----------------------------------------------------------|----------|------------------------------------------------------------------------|
| Excel import wizard for lookup-table cells               | complete | PhpSpreadsheet ^2.0 added via composer (vendor/ stays gitignored). New `src/Import/` package: FormatDetector (Format A grid / Format B long, ambiguous → unknown per `IMPORT_WIZARD_SPEC.md §5.2`), Parser (multi-sheet price groups + "Price group: X" separator rows + string-typed numeric cells in long format), Validator (numeric / >0 / ≥0 price / snake_case key / sane bounds + cross-row duplicate detection where last row wins per spec §8.2), Runner (8-state machine: received → parsing → parsed → validated → committing → applied / failed / cancelled, transactional commit with ROLLBACK on throw, idempotent insert/update via LookupCellRepository::find_by_coordinates, replace-all wipe via delete_all_in_table). New REST controller `ImportsController` with 5 routes under `configkit/v1/imports/*`; capability `configkit_manage_lookup_tables`. Multipart upload validated server-side: 10 MB cap, .xlsx-only, MIME allow-list, files moved to `wp-content/uploads/configkit-imports/` (mode 0700 + .htaccess `Require all denied`, randomized filenames). Wizard UI at ConfigKit → Imports renders 4-step flow (pick destination → drag-drop upload → preview with green/yellow/red counts + width/height/price/group stats + insert/update/skip tally + expandable per-row table → result with "Import another file"). Replace-all mode requires window.confirm before commit. Recent-imports list below the wizard. 28 new PHPUnit tests (Parser ×10, Validator ×10, Runner ×8) cover format detection, multi-sheet groups, separator rows, idempotent re-import, replace-all wiping unrelated cells, mixed red+green files only inserting green rows, parse-failure path. |
| Frontend customer configurator UI                        | complete | New `src/Frontend/` package: ProductRenderer hooks `woocommerce_before_single_product_summary` and replaces `woocommerce_template_single_add_to_cart` for products with `_configkit_enabled = 1` AND a template_key. RenderDataController (public, no cap) returns `/products/{id}/render-data` payload — full template snapshot (steps + fields + options + rules) + binding (defaults / overrides / locked values) + library items keyed by library + module capability flags + lookup table cells (cap 5000). AddToCartController re-validates submitted selections server-side (unknown_field / locked_value_mismatch / required_missing) and pushes the line into Woo cart with `_configkit_selections`, `_configkit_template_key`, `_configkit_binding_hash` meta. Storefront app at `assets/frontend/configurator.js` (vanilla JS, no framework) + `engines.js` ports a deliberate subset of the PHP RuleEngine + LookupEngine + PricingEngine (covers atomic / all / any / not / always conditions; equals / not_equals / >/< / between / in / not_in / contains / is_selected / is_empty operators; show_step / hide_step / show_field / hide_field / require_field / set_default / disable_option / filter_source / reset_value actions; lookup match modes exact / round_up / nearest with bounding-box guard; pricing modes fixed / per_unit / per_m2 / lookup_dimension / none with sale_price precedence + product_surcharge + discount_percent + minimum floor + VAT label). Server is the source of truth — JS is for live UX only. UI: top stepper (pill style, scrollable on mobile), step nav (Back / Continue / Add-to-cart), desktop price sidebar (sticky top:80px) + mobile sticky bar (display switches at 980px breakpoint). Field renderers: number with +/- pill, radio plain, radio cards (image + ✓ badge on selection), swatch grid (aspect-ratio:1/1 + color_family fallback palette for missing images), checkbox cards / list, display-only headings, text fallback. NOK currency via Intl.NumberFormat (0 fraction digits) with "kr" string fallback. ProductRenderer enqueues only on configurator-bound product pages so non-Woo / non-bound pages stay clean. |
| Server-side re-pricing via PricingEngine on cart events  | pending  | The cart line currently uses Woo's stock price; the JS port computes a live price for UX. Hooking `woocommerce_before_calculate_totals` to override the cart-line price via the PHP PricingEngine is the next chunk.                                                                       |
| Phase 4.2b.2 — Pricing Source + Bundle admin UI            | complete | Second of three Phase 4.2b sub-chunks. Library item form, bundle composition editor, and product-binding override editor — all hung off the engines + DB shipped in 4.2b.1. Six commits: (1) `WooProductsController` exposes `GET /configkit/v1/woo-products?q=&page=&per_page=` (60s transient cache) and `GET /woo-products/{id}` for picker hydration. New `ProductSearchProvider` interface + `WooProductSearchProvider` adapter (`wc_get_products` lives in src/Adapters/, never src/Engines/). Reusable `assets/admin/js/woo-product-picker.js` with debounced search, keyboard nav (↑↓ Enter Esc), thumbnail+SKU+price chips, and an ✕-clear affordance. (2) Library item form gains an "Item type" radio pair at the top (Single option / Package — owner never sees the `simple_option` / `bundle` enum), a "Pricing" radio group filtered by item type (configkit / woo for simple, bundle_sum / fixed_bundle for package), inline `bundle_fixed_price` field when fixed package is chosen, and bundle-only Cart-display + Admin-order-display toggles. The Price field is hidden whenever the source isn't ConfigKit so a stale number can't fight the chosen source. saveItem ships every Phase 4.2 field already accepted by the service. (3) Bundle composition editor: per-component rows with woo product picker + qty stepper + per-component price source dropdown + live resolved price chip + ✕ remove + optional Display-name-in-cart input + Check-stock toggle + auto-generated `component_key` from picked product name (numeric suffix on collision). Lazy `/woo-products/{id}` hydration so existing components render their selected product. (4) New PricingEngine method `resolveBundleBreakdown()` returns per-component unit/subtotal + final total honouring the bundle source — pure-PHP, accepts both decoded list and JSON blob. New `POST /configkit/v1/library-items/preview-price` endpoint feeds two admin panels: "Resolved price preview" under the Pricing radios (UI_LABELS_MAPPING §9.1) and "Package breakdown" under the components editor (§9.2). Both panels debounce input at 200 ms and use a generation counter to drop stale responses. Breakdown rows render the source as the owner-friendly label, never the enum value. Fixed-bundle items show "Fixed at X kr" with the BUNDLE_MODEL §10.2 caveat. (5) Per-item override editor on the Woo product ConfigKit tab. ProductBindingRepository persists `_configkit_item_price_overrides` post meta keyed by `library_key:item_key`; service validates the map (numeric ≥ 0 price, key shape, drop-on-empty-price), forces every saved entry to `price_source = 'product_override'` so the engine resolver knows where the value came from. New flat `GET /configkit/v1/library-items?q=&page=&per_page=` endpoint backed by `LibraryItemRepository::search_global()` feeds the inline picker. Editor table: Item / Original price / Override price / Note / Remove with a ↗ link to each library item's edit screen. (6) "Test default configuration price" panel sits at the bottom of the Pricing overrides section. New `TestDefaultPriceService` walks `binding.defaults`, resolves each library-sourced field through the PricingEngine (item overrides applied), returns subtotal + per-line breakdown + warnings. Endpoint `POST /configkit/v1/products/{id}/test-default-price`; the panel button triggers Recalculate and renders the resolved total + a per-line list with owner-friendly source labels. Lookup-table base price + rule surcharges + VAT explicitly deferred to Phase 4.2b.3 (cart-event wiring). Test stubs gained `WP_REST_Request` / `WP_REST_Response` / `WP_Error` / `register_rest_route` / `current_user_can` / `get_transient` / `set_transient` / `wpdb::esc_like` / `wpdb::get_results` so REST controllers are unit-testable without booting WordPress. New tests: WooProductsController (9), PricingEngineBreakdown (5), LibraryItemsControllerPreview (6), ProductBindingService extension (4), TestDefaultPriceService (6) = 30 net new. Suite 472 / 1206 green; engine purity grep zero matches. UI_LABELS_MAPPING.md §2 / §3 / §4 / §5 / §7 / §8 / §9.1 / §9.2 / §9.3 honoured throughout — no backend enum value appears as a primary UI label. |
| Phase 4.2b.1 — Pricing Source + Bundle engines + DB layer | complete | First of three Phase 4.2b sub-chunks. Backend / engine layer only — admin UI and cart integration are deferred to 4.2b.2 / 4.2b.3. Migration `0017_extend_library_items_pricing_bundles.php` adds the six columns spec'd in DATA_MODEL §3.3 (price_source / bundle_fixed_price / item_type / bundle_components_json / cart_behavior / admin_order_display) plus indexes `idx_item_type` and `idx_price_source`. Idempotent — each ALTER is gated by an INFORMATION_SCHEMA check so re-running `wp configkit migrate` is safe. New `src/Engines/PriceProvider.php` defines the pure adapter contract; production binding `src/Adapters/WooPriceProvider.php` lives outside `src/Engines/` so the purity grep stays at zero. PricingEngine constructor now takes an optional PriceProvider (kept optional for backwards compatibility with the Phase 2 signature; production wiring in `Plugin::build_price_provider()` always injects). New `PricingEngine::resolveLibraryItemPrice()` walks the PRICING_SOURCE_MODEL §3 ladder: binding override → item.price_source switch (configkit / woo via provider / fixed_bundle / bundle_sum recursing into components / product_override defensive fallback). Bundle component resolver honours each component's own price_source (woo / configkit / fixed_bundle) per BUNDLE_MODEL decision §10.4 — single null component → whole bundle returns null. LibraryItemRepository hydrate / dehydrate cover all six new columns; bundle-only fields are FORCED to null on simple_option items so toggling Package off cleans up the row. New private `decode_list_of_objects()` helper for components (the existing `decode_array` strips non-string entries — fine for filter tags, wrong for component objects). LibraryItemService::validate enforces every PRICING_SOURCE_MODEL §2 + BUNDLE_MODEL §3 rule (item_type allow-list, source-vs-type cross-check, ≥1 components on bundles, woo_product_id required for woo source, bundle_fixed_price required for fixed_bundle, per-component validation). Sanitize defaults `cart_behavior = 'price_inside_main'` and `admin_order_display = 'expanded'` per locked decisions §10.6 / §10.8. Forty new tests across three files: PricingEngineResolveTest (22 cases — every branch of the resolver + override priority + defensive paths), LibraryItemServiceTest extension (10 — every Phase 4.2 validation path), LibraryItemRepositoryTest (NEW, 6 — reflection-based round-trip on hydrate / dehydrate + legacy-row defaults). StubPriceProvider helper for tests. Suite 442 / 1125 green; engine purity grep zero matches. |
| Phase 4.2a.1 — UI labels mapping + locked decisions | complete (specs only) | Owner reviewed Phase 4.2a's open questions and gave 9 decisions; both pricing specs are now APPROVED for Phase 4.2b implementation. New spec: **UI_LABELS_MAPPING.md** (DRAFT v1) — owner-friendly label for every Phase 4.2 backend term (5 `price_source` values, `item_type` two-card selector replacing the raw enum, bundle composition editor replacing `bundle_components_json`, `cart_behavior` / `stock_behavior` / `admin_order_display` toggles, per-item override editor table). §9 specifies three required preview panels: library item "Resolved price" (live updates per source), bundle "Package breakdown" (component table with totals or "Fixed at X kr" for `fixed_bundle`), product binding "Test default configuration price" with Recalculate button. §11 lists forbidden patterns (raw enums, JSON blobs, DB column names, snake_case in primary labels) and mandates that future chunks add label mappings here BEFORE implementation lands. Locked decisions in PRICING_SOURCE_MODEL §9 + BUNDLE_MODEL §10: per-product override scope only, Woo price freeze at add-to-cart, no bundle recursion (bundles single-level only), bundle_sum walks each component independently, negative prices clamp at line level, `cart_behavior = 'price_inside_main'` default, `stock_behavior = 'check_components'` default but only blocks when Woo stock management is enabled, `admin_order_display = 'expanded'` default, migration backfill `price_source = 'configkit'` / `item_type = 'simple_option'` / bundle fields NULL. The `simple` enum value renamed to `simple_option` everywhere (BUNDLE_MODEL, DATA_MODEL §3.3, MODULE_LIBRARY_MODEL §17, PRICING_SOURCE_MODEL §2/§3). PRODUCT_BINDING_SPEC gains §21 per-item override UI (5-column editor table — Item / Original price / Override price / Customer sees / Reason — with searchable item picker and "Test default configuration price" preview) and §22 future bulk-apply enhancement note (out of scope for Phase 4.2b, no schema change required). No code, no DB. |
| Phase 4.2a — Pricing Source + Bundle model specs | complete (specs only) | Spec-only chunk authored under `docs/configkit/specs/`. Two new specs: **PRICING_SOURCE_MODEL.md** (DRAFT v1) — five-value `price_source` enum on library items (`configkit` / `woo` / `product_override` / `bundle_sum` / `fixed_bundle`), deterministic resolution ladder (binding override → item.price_source → sale / surcharge / discount / floor), `PriceProvider` adapter interface that keeps `PricingEngine` pure (production binding lives outside `src/Engines/`), VAT-mode interaction across sources, four worked examples (pure ConfigKit colour, ConfigKit-wins motor, Woo-wins motor, per-product binding override), migration impact + 5 owner-review questions. **BUNDLE_MODEL.md** (DRAFT v1) — `item_type = 'simple' | 'bundle'`, `bundle_components_json` shape (`component_key` + `woo_product_id` + `qty` + `price_source` + `price` + `stock_behavior` + `label_in_cart`), `cart_behavior` (`price_inside_main` default vs `add_child_lines`), per-component `stock_behavior`, `admin_order_display` (`expanded` default), two worked examples (Somfy IO Premium Pakke fixed-price + Manual crank sum-of-components), migration impact + 5 owner-review questions. Cross-spec updates: `DATA_MODEL.md §3.3` lists six new columns Phase 4.2b will land via migration `0017_extend_library_items_pricing_bundles.php`; `PRICING_CONTRACT.md` gains §18 (pricing source resolution) and §19 (bundle pricing) referencing the new specs and noting the PricingEngine constructor now takes a `PriceProvider`; `PRODUCT_BINDING_SPEC.md` gains §18 per-item price overrides (post-meta `_configkit_item_price_overrides` JSON keyed by `library_key:item_key`), §19 allowed item-level overrides via existing `allowed_sources` allow-list, and §20 explicit Phase 4.2 deliverables checklist; `MODULE_LIBRARY_MODEL.md` gains §17 documenting `item_type` validation rules and the explicit clarification that `price_source` is per-item, not per-module (capability flags only gate field visibility). All defaults preserve existing Phase 1 row behaviour. No code, no DB changes — awaiting owner review of the 10 open questions across the two new specs before Phase 4.2b implementation. |
| Phase 4.1 polish (cross-linking + breadcrumb cleanup) | complete | Owner audit after Phase 4 polish caught two friction issues. (1) Two breadcrumb lines stacked on Modules ("ConfigKit › Settings › Modules" + "Modules › New module") — `Breadcrumb::render()` now stamps a `data-cf-href` attribute on the last segment so the JS-side `subBreadcrumb()` helper can promote it back into a link in place; the second nav line is gone. AbstractPage's default last segment carries its own slug as href; ModulesPage drops the bogus Settings intermediate; each entity JS (modules / families / libraries / lookup-tables / templates) drops its redundant duplicate first segment and now passes only the tail. WooIntegration's product-tab breadcrumb tightened to `WooCommerce › Products › "<Product Name>" › ConfigKit` with the product segment linking to the Woo edit URL. New BreadcrumbTest (6 cases) covers single-nav emission + data-cf-href emission + blank-segment skipping. (2) Edit-jump links from the Woo product ConfigKit tab — `selectField()` gained an `editHref` param that renders an `Edit ↗` link next to the Template / Lookup table / Family selects when a value is chosen (target=_blank rel=noopener). Section 4 Allowed sources lists each library with a `<Library Name> ↗` direct edit link. (3) Diagnostic fix_links are now entity-specific: `template_version_published` → `configkit-templates&action=edit&id={id}#publish`; `lookup_table_has_cells` → `configkit-lookup-tables&action=edit&id={id}#cells`; `rules_targets_valid` → `configkit-templates&action=edit&id={id}&tab=rules`. Same-page checks keep their #section-X anchors. SystemDiagnosticsService gets the same hash treatment. diagnostics.js Fix button now reads the URL and renders a context-aware label ("Open cells editor →" / "Open template publish →" / "Open rules drawer →"). Two new diagnostics-test assertions lock down the precise URLs. Suite now 402 / 1039. |
| Phase 4 polish (PhpSpreadsheet + Module CRUD + import flow + icons) | complete | Owner audit caught four real issues after Phase 4 ship: (1) the Excel upload was returning 500 because the uploads dir wasn't created on activation; PhpSpreadsheet ^2.0 was already installed via composer.lock 2.4.5. New `src/Import/UploadPaths.php` is the single source of truth for `wp-content/uploads/configkit/imports/`; `Plugin::on_activation()` calls `ensure()` so fresh installs have it ready, and `register_admin_init()` calls it as a belt-and-suspenders for installs that pre-date the hook. (2) Module Create flow used to gate the form behind a preset picker; `state.view = 'presets'` is gone, `showNewForm()` lands directly on a blank form. The 4 named presets re-appear as inline "Apply preset" buttons inside the Create form that overlay capabilities on top of current state without ever clearing owner-checked boxes. ModuleService already accepted any combination including all-blank — two new PHPUnit tests now lock that down. (3) Capability icons were inconsistently coloured — fixed by wrapping each checkbox in a `.configkit-capability-row` container whose `.is-checked` class drives the icon + label colour through CSS, no longer relying on per-icon `--checked` modifiers. Smooth 0.15s transitions. (4) Excel import lived behind a separate menu detached from the data: Lookup Tables → detail page now carries a "⬆ Import from Excel" button next to "+ New cell" that links to `?page=configkit-imports&target_type=lookup_cells&target_lookup_table_key=KEY`. The wizard reads those params, sets `state.contextual = true`, and skips step 1. ConfigKit → Imports menu renamed to "Import history"; the page leads with the past-batches table when the owner hasn't started a fresh upload, and falls back to the wizard below for ad-hoc destination picking. Recent imports table grew Target + Rows columns. Suite now 395 / 1016 green. |
| Library items import                                     | pending  | Out of scope for the lookup-cells import chunk; pending its own session.                                          |
| Diagnostics catalogue expansion (warnings + info)        | pending  |                                                                        |
| Product Readiness Board                                  | pending  |                                                                        |

**Engine purity preserved.** `grep -rE "wp_\|get_option\|WP_Query\|\\$wpdb" src/Engines/`
returns zero matches. Phase 2 + 3 + 3.5 + 3.6 + 4 + 4.2b PHPUnit tests
green (472 / 1206).

---

## Phase 5 — Frontend renderer

(Nothing started.)

---

## Phase 6 — Cart + Order integration

(Nothing started.)

---

## Phase 7 — First product end-to-end

(Nothing started.)

---

## Last updated

2026-04-29 — Phase 3 feature-complete: Modules + Libraries +
Lookup Tables + Families + **Templates (B1 metadata + B2 steps +
B3 fields/wizard/three-pane + B4 rules drawer + B5 publish/version
snapshot)** + **Products binding** (Woo product-data tab + overview)
+ **System diagnostics** (8 checks per `OWNER_UX_FLOW.md §8` Flow F,
ack via `wp_configkit_log` rows, tabbed UI). 7 of 7 in-scope Phase 3
entities landed.

**Phase 3.5 polish landed on top:** mobile responsive cards across
all CRUD pages, (?) tooltips on every capability and technical
concept, collapsible advanced fieldsets, 5-card module-type preset
flow (Textiles/Colors/Motors/Accessories/Custom), empty-state CTAs
that push owners to the next sensible action, conditional Discount %
in Product binding, and soft (non-blocking) warnings on generic /
placeholder keys.

**Phase 3.6 owner workflow clarity landed on top of 3.5:**
breadcrumbs on every admin surface (server + JS sub-trail),
a 7-state "Next step" dashboard guidance card (modules-empty →
ready), owner-friendly visible labels (12 capability sentences,
"Technical key" everywhere, friendly match_mode dropdowns),
PageHeader component (H1 + subtitle + primary CTA + Back-to-
dashboard) + dismissible muted intro boxes per page,
diagnostics with `title` + `suggested_fix` + `fix_link` so owners
know what to do next, and a 5-step setup-progress checklist
on the Woo product tab with disabled (locked) sections until
prerequisites are met.

**Phase 4 Excel import wizard** for lookup-table cells
(`docs/configkit/specs/IMPORT_WIZARD_SPEC.md` DRAFT v1, implicit
approval): PhpSpreadsheet ^2.0 dependency, Format A grid +
Format B long parser with multi-sheet / separator-row price-group
support, Validator with cross-row last-wins duplicate detection,
Runner with the 8-state batch machine + transactional commit +
idempotent insert/update + replace-all wipe. ConfigKit → Imports
admin page hosts a 4-step wizard (pick destination → drag-drop
upload → preview with green/yellow/red counts and per-row table →
result). 5 REST endpoints under `configkit/v1/imports/*`.

**Phase 4 frontend customer UI** then layered on:
`ProductRenderer` swaps the Woo single-product add-to-cart for a
ConfigKit mount point on bound products; `RenderDataController`
serves the public snapshot (template + binding + libraries +
modules + lookup cells) at `/products/{id}/render-data`;
`AddToCartController` re-validates selections server-side and
pushes to the Woo cart with `_configkit_selections` meta. The
storefront app (vanilla JS, no framework) renders a stepper +
desktop price sidebar / mobile sticky bar with field renderers
for number / radio plain / radio cards / swatch grid / checkbox
cards / display fields. `engines.js` ports a deliberate subset
of the PHP RuleEngine + LookupEngine + PricingEngine for live UX
(server is still the source of truth for pricing on cart events,
which lands as a follow-up chunk).

**Phase 4 polish** then resolved four owner-reported issues:
fresh `UploadPaths::ensure()` runs at activation so the Excel
500 disappears; the Module Create form now lands blank (presets
become inline overlay buttons); capability icons use a unified
`.configkit-capability-row.is-checked` state for consistent
colouring; and the Excel import lives where the data lives —
Lookup Tables → detail page → "Import from Excel", with
ConfigKit → Imports renamed to "Import history" and hosting a
fallback ad-hoc wizard.

**Phase 4.1 cross-linking** layered on top: server +
JS breadcrumbs collapse into a single navigable line (Modules drops
its bogus "Settings" intermediate; the JS sub-breadcrumb extends
the existing nav in place rather than rendering a second one);
the Woo product ConfigKit tab now shows an `Edit ↗` link next to
each Template / Lookup table / Family select, plus per-library
edit links in Allowed sources; and diagnostic Fix buttons jump
straight to the resolution surface (`#publish`, `#cells`,
`&tab=rules`) instead of generic list pages, with context-aware
button labels ("Open cells editor →", "Open template publish →").

**Phase 4.2a** then authored two new pricing-model specs (DRAFT v1):
`PRICING_SOURCE_MODEL.md` (per-item `price_source` enum with five
values, deterministic resolution ladder, and a `PriceProvider`
adapter that keeps `PricingEngine` pure when reading Woo prices)
and `BUNDLE_MODEL.md` (`item_type = 'simple_option' | 'bundle'`,
JSON component shape with per-component `qty` / `price_source` /
`stock_behavior`, configurable `cart_behavior` and
`admin_order_display`). DATA_MODEL §3.3 now lists the six new
`wp_configkit_library_items` columns Phase 4.2b will land in
migration `0017_extend_library_items_pricing_bundles.php`. The
Pricing contract grew §18-§19, Product Binding spec grew §18-§20
(per-item price overrides on the Woo product), and
MODULE_LIBRARY_MODEL grew §17 (item_type validation + the
clarification that price_source is per-item, not per-module).

**Phase 4.2a.1** then locked the model. Owner reviewed the 10
open questions across the two pricing specs and gave 9 decisions
— both PRICING_SOURCE_MODEL.md and BUNDLE_MODEL.md are now
APPROVED. Decisions baked in (per-product override scope only,
Woo price freeze at add-to-cart, no bundle recursion, bundle_sum
walks each component independently, negative-price clamp at line
level, cart_behavior default `price_inside_main`, stock_behavior
default `check_components` only blocking when Woo stock
management is enabled, admin_order_display default `expanded`,
migration backfill `price_source = 'configkit'` / `item_type =
'simple_option'`). The `simple` enum value was renamed to
`simple_option` everywhere it appeared. New spec
**UI_LABELS_MAPPING.md** documents the owner-facing label for
every Phase 4.2 backend term plus three required preview panels
(library item Resolved price, bundle Package breakdown, binding
Test default configuration price) and the forbidden-pattern law:
raw enums / JSON / DB column names / snake_case never become
primary UI labels, and future chunks MUST add label mappings here
before implementation. PRODUCT_BINDING_SPEC gained §21 per-item
override editor layout (5-column table, searchable item picker,
"Customer sees" preview) and §22 future bulk-apply enhancement
note.

**Phase 4.2b.1** then started the implementation. Backend layer
only — admin UI and cart integration are 4.2b.2 / 4.2b.3.
Migration 0017 adds six columns to `wp_configkit_library_items`
(price_source / bundle_fixed_price / item_type /
bundle_components_json / cart_behavior / admin_order_display) +
two indexes; idempotent. `PriceProvider` interface in
`src/Engines/` (pure) plus `WooPriceProvider` adapter in
`src/Adapters/` (the only place ConfigKit calls
`wc_get_product()`). `PricingEngine::resolveLibraryItemPrice()`
walks the 5-source ladder; bundle_sum recurses into components
honouring each component's own source. Repository + Service
extended with full validation per PRICING_SOURCE_MODEL §2 +
BUNDLE_MODEL §3. Forty new tests; suite goes 402 / 1039 → 442 /
1125 green. Engine purity grep stays at zero matches.

**Phase 4.2b.2** then landed the admin UI in six commits: a
reusable Woo product picker (search endpoint + chip-style
autocomplete with keyboard nav); the library item form's two-card
Item type picker and Pricing radio group (price labels per
UI_LABELS_MAPPING §2 — backend enums never shown to the owner);
the bundle composition editor with per-component price source +
qty + live resolved chip + auto-generated component_key; the
"Resolved price preview" + "Package breakdown" panels powered by a
new pure-PHP `PricingEngine::resolveBundleBreakdown()` helper and
`POST /library-items/preview-price`; the per-item override editor
on the Woo product ConfigKit tab (`_configkit_item_price_overrides`
post meta, flat `GET /library-items` for the picker, ↗ edit-jump
links to each item); and the "Test default configuration price"
panel powered by `TestDefaultPriceService` which walks binding
defaults through the engine to show how overrides apply before
saving. Lookup-table base price + rule surcharges + VAT remain
deferred to Phase 4.2b.3 (cart-event wiring). Suite goes 442 /
1125 → 472 / 1206; engine purity grep stays at zero matches.

Awaiting Phase 4.2b.3 (cart-event wiring + bundle reconciliation
across child cart lines + full snapshot pricing for the test
panel). Other open directions: Phase 5 broader cart integration,
Phase 4 dalis 3 library items import.

---

## Patches applied — 2026-04-29

Owner review of Batch 1 v2 raised 5 corrections; this patch round applies
them plus the 5 stale cross-references previously logged here. All four
Batch 1 specs remain `wip` and DRAFT v2; nothing is approved yet.

### Owner-review corrections

1. **PHASES.md Phase 1 contradiction.** Migration logging was forbidden by
   the blanket DML ban. Phase 1 Allowed/Forbidden replaced to permit narrow
   writes (`INSERT` into `wp_configkit_migrations`, `UPDATE` of the
   `configkit_db_version` option, idempotent built-in seed inserts) while
   keeping destructive ops, product writes, and runtime/UI writes
   forbidden.

2. **WooCommerce dependency clarity.** STATUS.md note rewritten to state
   WC must be installed and active before Phase 3 entry. PHASES.md Phase 3
   gains an entry-criteria section requiring "WooCommerce installed and
   active".

3. **Phase reorder.** Excel Import + Diagnostics + Product Readiness Board
   moved from old Phase 6 to new Phase 4. Frontend renderer is now Phase 5;
   Cart/Order is Phase 6; Phase 7 unchanged. CLAUDE.md phase table updated
   to match. Reason: owner mission is fast product creation through Excel +
   reusable lookup tables/libraries + diagnostics + preview. Owner must be
   able to upload products and lookup tables before frontend polish.

4. **Greenfield clarification in TARGET_ARCHITECTURE.md §6.** Paragraph
   added stating that legacy data (Sologtak, scraped products, prior
   ConfigKit) enters via one-time import/migration only; no live dual-read
   legacy engine is supported for sol1.

5. **Stale cross-references resolved.** See list below.

### Stale cross-references resolved

- `CLAUDE.md` line 30: `docs/STATUS.md` → `STATUS.md`.
- `TARGET_ARCHITECTURE.md` §4: `DATA_MODEL.md §3.2` → `DATA_MODEL.md §3.6`
  (template_versions).
- `TARGET_ARCHITECTURE.md` §5: `DATA_MODEL.md §3.7` → `DATA_MODEL.md
  §3.15-§3.16` (import_batches and import_rows).
- `PHASES.md` Phase 4 (was Phase 6) diagnostics: `TARGET_ARCHITECTURE.md
  §11` → `AUDIT_CHECKLIST.md` (Batch 2). §11 of TARGET_ARCHITECTURE.md is
  "Open questions"; diagnostics is not defined in that document.
- `PHASES.md` Phase 7 perf measurement: `TARGET_ARCHITECTURE.md §10` →
  `TARGET_ARCHITECTURE.md §6` (Performance budgets). §10 was "What this
  document does not cover".

**Update 2026-04-29:** Owner approved Batch 1 v2. All four Batch 1 spec
rows are now `complete`. Phase 0 closed; see "Phase 0 exit" line above.
