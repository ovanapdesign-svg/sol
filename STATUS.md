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
| Products list + binding edit                          | pending  | Cross-references families / templates / lookup tables.                 |
| Families CRUD                                         | pending  |                                                                        |
| Templates list                                        | pending  |                                                                        |
| Template builder (3-pane + field wizard + rules)      | pending  | Per `TEMPLATE_BUILDER_UX.md §14` — multi-session by itself.            |
| Rules basic CRUD                                      | pending  |                                                                        |
| Diagnostics (critical issues only)                    | pending  |                                                                        |
| Optimistic locking via `version_hash` everywhere      | partial  | Wired for Modules, Libraries, Library Items, Lookup Tables (returns 409 on stale hash). Cells intentionally have no version_hash per schema. Other entities wire on landing. |
| Capability auto-assign on activation + safety net     | complete | `Capabilities\Registrar::register / deregister / ensure_registered`. `register_deactivation_hook` clears caps + version flag; `admin_init` re-runs registration once when option `configkit_caps_version` ≠ current. |

**Engine purity preserved.** `grep -rE "wp_\|get_option\|WP_Query\|\\$wpdb" src/Engines/`
returns zero matches. Phase 2 + 3 PHPUnit tests green (155 / 327).

**Honest scope note.** This phase has 14 suggested commits and ten
admin pages. Two are landed; the remaining twelve are each a focused
chunk and the template builder is multi-session in itself. Continuing
in subsequent sessions per owner direction.

---

## Phase 4 — Excel Import + Diagnostics + Product Readiness Board

(Nothing started.)

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

2026-04-29 — Phase 3 progress: Foundation + Dashboard + Settings →
General + Modules CRUD + Libraries CRUD (incl. items) + Lookup
Tables CRUD (incl. cells with bulk-upsert endpoint). Pattern (repo →
service → REST → admin page → JS) applied three times and stable.
Engines remain pure; 155 PHPUnit tests / 327 assertions green.
Awaiting owner direction on next chunk: Families, Templates, Products,
or Diagnostics.

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
