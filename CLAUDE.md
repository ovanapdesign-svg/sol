# CLAUDE.md — ConfigKit plugin operating protocol

## Status

**Phase 0 — Specs in place. Implementation not started.**

## Environment

- Site: https://demo.ovanap.dev/sol1/
- Server: oho-ovanap (Hetzner CX53, Ubuntu)
- Plugin path: `/home/ovanap/demos/sol1/wp-content/plugins/configkit/`
- Repo: `git@github.com:ovanapdesign-svg/sol.git`
- Branch: `main`
- WP admin user: `tomas`
- Database: `ovanap1_sol1`, prefix `wp_`, user `sol1`
- PHP: 8.3, FPM socket `/run/php/php8.3-fpm.sock`
- DB: MariaDB 10.11

## Authoritative documents

These are the source of truth. Read before any task that touches
architecture, data, fields, or libraries:

- `docs/configkit/specs/TARGET_ARCHITECTURE.md`
- `docs/configkit/specs/DATA_MODEL.md`
- `docs/configkit/specs/FIELD_MODEL.md`
- `docs/configkit/specs/MODULE_LIBRARY_MODEL.md`

Status: DRAFT v2. Awaiting owner approval. Do not assume final until
`docs/STATUS.md` confirms.

Pending (not yet authored):

- `docs/configkit/specs/RULE_ENGINE_CONTRACT.md`
- `docs/configkit/specs/PRICING_CONTRACT.md`
- `docs/configkit/specs/TEMPLATE_VERSIONING.md`
- `docs/configkit/specs/MIGRATION_STRATEGY.md`
- `docs/configkit/specs/MULTILANGUAGE_MODEL.md`
- `docs/configkit/specs/AUDIT_CHECKLIST.md`
- `docs/configkit/ux/*` (8 UX documents)

## Working protocol

- **One task per session.** Owner gives the task. Do not chain to next phase.
- **Read specs first** for any task affecting architecture or data.
- **Read PHASES.md** to know which phase is current and what is allowed.
- **Commit per task** with clear messages: `phase N: <what>`.
- **Push to main** after each green commit.
- **Stop and ask** when unsure. Do not guess permissions or scope.

## Allowed in Phase 0

- Read any file in this plugin directory.
- Write/edit `.md` files in `docs/configkit/`.
- Run `wp` CLI **read** commands (`wp option get`, `wp post list`, `wp db query "SELECT..."`).
- Run `git status`, `git log`, `git diff`.

## Forbidden in Phase 0

- Edit any `.php`, `.js`, `.css`, `.json` file outside `docs/`.
- Database writes: `CREATE`, `ALTER`, `INSERT`, `UPDATE`, `DELETE`.
- `wp option update`, `wp post update`, `wp user update`.
- Touch `/sologtak` (separate Docker site, not our scope).
- Touch any other site under `/home/ovanap/demos/`.
- Run any migration script.

## Phase progression

See `PHASES.md` for the full plan. Summary:

| Phase | Name                              | Status   |
|-------|-----------------------------------|----------|
| 0     | Scaffold + specs                  | active   |
| 1     | Schema migrations (additive)      | pending  |
| 2     | Engines (rule, pricing, lookup)   | pending  |
| 3     | Admin UI                          | pending  |
| 4     | Frontend renderer                 | pending  |
| 5     | Cart + order integration          | pending  |
| 6     | Excel import + diagnostics        | pending  |
| 7     | First product end-to-end test     | pending  |

Each phase has its own entry/exit criteria in `PHASES.md`. Do not enter
Phase N+1 until Phase N is marked `complete` in `STATUS.md`.

## Key/label discipline (the architectural law)

- Technical IDs (`field_key`, `template_key`, `item_key`, `module_key`,
  `library_key`, `family_key`, `lookup_table_key`, `option_key`,
  `rule_key`, `step_key`, `price_group_key`) are language-neutral,
  snake_case, immutable.
- Display labels are separate. Changing a label MUST NOT break rules,
  pricing, lookups, cart, orders, or templates.
- Excel imports/exports use keys.
- Rules reference keys.
- Cart and order meta use keys.
- Numeric IDs are NOT canonical references.

## Commit message format

```
phase <N>: <imperative summary>

<optional body explaining why, not what>

<optional refs to specs: e.g. "per DATA_MODEL.md §3.4">
```

Examples:

```
phase 1: add wp_configkit_modules schema migration
phase 2: implement RuleEngine condition operators
phase 3: build library admin list screen
```

## When in doubt

- Stop.
- Ask the owner via the chat session.
- Do not infer permission.
- Do not "improve" code that wasn't asked about.
- Do not refactor adjacent files without explicit scope.
