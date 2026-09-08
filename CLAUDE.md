# Bonnie — CF7 submissions add-on (WordPress plugin)

Free plugin, ships under Red Pocket. Pro companion: `~/repos/bonnie-pro` —
wp-env mounts it alongside this repo (see `.wp-env.json`, which also sets
`BONNIE_PRO_DEV_UNLOCK` so Pro features unlock without a licence locally).

## Free/Pro boundary
This repo ships **only** the free tier and carries **no** Pro code — there is no
`bonnie_is_pro()` gate. The paid add-on (`~/repos/bonnie-pro`, private)
implements every Pro capability by hooking the extension API documented in
`docs/HOOKS.md`. When adding a feature, pick the tier first: free behaviour lives
here; Pro-only behaviour goes in the add-on. Add a hook to core only when the
add-on needs a new seam, and document it in `docs/HOOKS.md`.

## Dev loop
1. Edit PHP → `php -l` every touched file.
2. Touched `src/`? → `npm run build` (`@wordpress/scripts`, entries
   `src/index.js` + `src/settings.js`).
3. **`build/*.js` and `build/*.asset.php` are committed** — rebuild and stage
   them with any `src/` change, or the shipped admin goes stale.
4. User-facing strings changed → regenerate `languages/the-bonnie-situation.pot`
   with `wp i18n make-pot` (no npm script for this; run via wp-env cli).

## wp-env gotcha (this machine)
Docker Desktop buildx is broken. Before ANY `wp-env` command:
`export DOCKER_BUILDKIT=0 COMPOSE_DOCKER_CLI_BUILD=0 COMPOSE_BAKE=false`
Site: http://localhost:8888 · WP-CLI: `npx wp-env run cli wp <cmd>`
