# CLAUDE.md — ConfigKit plugin

## Status
Phase 0 — scaffold only. No implementation work yet.

## Environment
- Site: https://demo.ovanap.dev/sol1/
- Server: oho-ovanap (Hetzner CX53, Ubuntu)
- Plugin path: /home/ovanap/demos/sol1/wp-content/plugins/configkit/
- Repo: git@github.com:ovanapdesign-svg/sol.git
- Branch: main
- WP user: tomas

## Allowed in Phase 0
- Read any file in this plugin directory
- Write documentation files (.md only)
- Run `wp` CLI read commands (wp option get, wp db query SELECT, wp post list)
- Run `git status`, `git log`, `git diff`

## Forbidden in Phase 0
- Edit any .php / .js / .css / .json file
- Run database writes (CREATE, ALTER, INSERT, UPDATE, DELETE)
- Run `wp option update`, `wp post update`, etc.
- Touch /sologtak (separate Docker site)
- Touch any other site under /home/ovanap/demos/

## Working protocol
- Owner gives one task per session
- After task: `git add`, commit with clear message, `git push`
- Stop when task is done. Do not chain to next phase autonomously.
- If unsure: stop and ask the owner.

## Pending docs (will arrive in next sessions)
- docs/configkit/specs/TARGET_ARCHITECTURE.md
- docs/configkit/specs/DATA_MODEL.md
- docs/configkit/specs/FIELD_MODEL.md
- docs/configkit/specs/MODULE_LIBRARY_MODEL.md
- docs/configkit/ux/ (UX roadmap)

Do not generate these on your own. Owner provides them.
