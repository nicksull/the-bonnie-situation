# The Bonnie Situation

Contact Form 7 submissions add-on for WordPress. The public plugin listing lives in
[`readme.txt`](readme.txt); extension hooks for add-ons are documented in
[`docs/HOOKS.md`](docs/HOOKS.md).

## Development

```sh
npm install
npm run build   # compiles src/ → build/ (build/ is committed)
```

## Releasing to WordPress.org

1. Bump the version: `npm run bump -- 1.2.0`
   Updates the plugin header `Version:`, `BONNIE_VERSION`, `Stable tag` and
   `package.json`. It does **not** touch `BONNIE_DB_VERSION` — bump that by hand
   only when the schema changes (it triggers the upgrade routine).
2. Add a `= 1.2.0 =` entry under `== Changelog ==` (and `== Upgrade Notice ==`)
   in `readme.txt`. The script warns if the changelog entry is missing.
3. Commit and push to `main`.
4. Publish a GitHub Release (e.g. tag `1.2.0`).

Publishing the release runs `.github/workflows/deploy.yml`, which builds the
admin bundles and deploys to WordPress.org SVN (`trunk` + `tags/<version>`,
plus `.wordpress-org/` → `assets/`), then attaches the zip to the release. The
SVN version comes from the plugin header, not the release name. Files are
filtered by `.distignore`.
