# Bonnie — CF7 submissions add-on (WordPress plugin)

Free plugin, ships under Red Pocket. The paid Bonnie Pro add-on is developed separately.

## Free/Pro boundary
This repo ships **only** the free tier and carries **no** Pro code — there is no
`bonnie_is_pro()` gate. The paid add-on
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
4. Touched PHP behaviour → `npm run test:php` (PHPUnit inside wp-env; run
   `npm run test:php:setup` once to install `vendor/`). Add a test in `tests/`
   for retention/delete changes: a regression there silently keeps data.
5. User-facing strings changed → regenerate `languages/the-bonnie-situation.pot`
   with `wp i18n make-pot` (no npm script for this; run via wp-env cli).

## wp-env
Site: http://localhost:8888 · WP-CLI: `npx wp-env run cli wp <cmd>`
