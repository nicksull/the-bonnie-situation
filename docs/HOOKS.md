# Bonnie extension API

The seam between the free core (`the-bonnie-situation`) and the paid add-on
(`bonnie-pro`). Core carries **no dormant paid code**: every Pro capability is
implemented in the add-on and layered onto core through the hooks below. In the
free build each filter is a pass-through, each action has no listener, and each
JS filter returns its input unchanged — so behaviour is exactly the base tier.

Add-ons should register on `bonnie_loaded` (PHP) rather than reaching into core
classes.

## Bootstrap

### `bonnie_loaded` (action) — `since 1.0.0`
Fires once core has registered all its hooks.

```php
add_action( 'bonnie_loaded', function ( Bonnie_Services $services ) {
    $store  = $services->store();   // Bonnie_Store
    $logger = $services->logger();  // Bonnie_Logger
    $loader = $services->loader();  // Bonnie_Loader
    $core   = $services->version(); // string
} );
```

The same registry is available on demand via the global `bonnie()` accessor
(returns `Bonnie_Services|null` — null before the plugin has booted).

### `bonnie_upgrade_url` (filter) — `since 1.0.0`
`string $url` — the URL the informational "Bonnie Pro" link points at.

## Settings schema

| Hook | Type | Signature | Purpose |
|---|---|---|---|
| `bonnie_default_settings` | filter | `array $defaults` | Register add-on setting keys so `get_settings()` merges and preserves them. |
| `bonnie_sanitize_settings` | filter | `array $out, mixed $input` | Sanitise and merge add-on keys (and any extra retention policies) from the raw REST payload. Core keys are already handled in `$out`. |

## Capture (write path)

| Hook | Type | Signature | Purpose |
|---|---|---|---|
| `bonnie_should_capture` | filter | `bool $capture, WPCF7_ContactForm $form, array $result` | Override the capture decision. |
| `bonnie_capture_meta_keys` | filter | `array\|null $keys, array $posted` | Whitelist which posted keys persist as meta (`null` = default). |
| `bonnie_capture_remote_ip` | filter | `string $ip, array $settings, WPCF7_ContactForm $form` | Transform the raw IP before it is packed (e.g. **anonymise** it). No-op in free. |
| `bonnie_effective_capture` | filter | `array $resolved, int $form_id` | Effective capture settings (`store_ip`, `anonymize_ip`, mapping) for a form. Pro layers per-form overrides here. |
| `bonnie_before_store` | filter | `array $row, array $posted, WPCF7_ContactForm $form` | Transform the submission row before write (e.g. **encrypt columns**). |
| `bonnie_before_store_meta` | filter | `array $meta, array $posted, WPCF7_ContactForm $form` | Transform meta before write (e.g. **encrypt meta values**). |
| `bonnie_after_store` | action | `int $submission_id, array $row, array $posted` | React to a stored submission (e.g. upsert an **address-book contact**). |

## Read path (decrypt seam)

Every path that surfaces a submission — list, detail, privacy exporter — routes
through these. Pair them with the write-path filters for **encryption-at-rest**.

| Hook | Type | Signature | Purpose |
|---|---|---|---|
| `bonnie_read_submission` | filter | `object $row, string $context` | Transform a submission row on the way out. `$context`: `list` \| `single`. |
| `bonnie_read_submission_meta` | filter | `object[] $rows, int $id` | Transform meta rows (`meta_key`, `meta_value`) on the way out. |

## Delete path

Fired around every permanent delete (admin action, retention sweep, privacy
erasure). Add-ons that derive data from submissions (e.g. an address book)
snapshot on `before` and reconcile on `after`.

| Hook | Type | Signature | Purpose |
|---|---|---|---|
| `bonnie_before_delete_submissions` | action | `int[] $ids` | Submissions about to be permanently deleted. |
| `bonnie_after_delete_submissions` | action | `int[] $ids` | Submissions that were permanently deleted. |

## Retention

Core ships **delete-only** retention against the global policy. Pro layers
per-form overrides and the trash + hard-cap strategy on `bonnie_retention_after_run`.

| Hook | Type | Signature | Purpose |
|---|---|---|---|
| `bonnie_overriding_form_ids` | filter | `int[] $ids` | Form ids that override the global policy (empty in free). Core excludes them from the global sweep so the add-on can enforce them itself. |
| `bonnie_effective_retention` | filter | `array $resolved, int $form_id` | `{ policy, days, hardcap }` for a scope (`$form_id` 0 = global). |
| `bonnie_purge_cutoff` | filter | `string $cutoff, string $scope` | MySQL datetime; rows older than this are acted on. |
| `bonnie_retention_after_run` | action | `int $now_ts, string $now` | Fires after the free delete-only sweep. Add-ons run the trash workflow, the hard-cap sweep and per-form scopes here. |

## REST batch endpoint

`POST bonnie/v1/submissions/batch` handles permanent `delete` in core. Add-ons
register further actions (e.g. the Pro trash workflow / bulk delete):

| Hook | Type | Signature | Purpose |
|---|---|---|---|
| `bonnie_rest_batch_actions` | filter | `string[] $actions` | Whitelist of accepted action keys (core: `['delete']`). |
| `bonnie_rest_batch` | filter | `array\|WP_Error\|null $result, string $action, int[] $ids` | Handle an add-on action; return `['updated' => N]`, a `WP_Error`, or `null` (unhandled). |

## Admin UI — server-rendered injection

| Hook | Type | Signature | Purpose |
|---|---|---|---|
| `bonnie_submissions_list_header` | action | — | After the submissions page title. Add page-title actions (e.g. Pro's Export buttons). |
| `bonnie_submission_detail_actions` | action | `object $sub` | In the detail-view action bar. Add buttons (e.g. Pro's Move to Trash / Restore). Build nonced links with `Bonnie_Submissions_Page::detail_action_url()`. |
| `bonnie_admin_notices` | filter | `array $messages, int $count` | Register post-action admin notices keyed by the `bonnie_notice` query var. |

## Admin UI — React (`wp.hooks` JS filters)

The React apps read these at render, after any add-on script's top-level
`addFilter()` calls (the apps mount on `wp.domReady`). An add-on enqueues a
script that depends on `wp-hooks` on the relevant Bonnie admin screen.

| Filter | Signature | Purpose |
|---|---|---|
| `bonnie.submissions.statuses` | `Array statuses` | Status views on the submissions list (free: Active, Spam). |
| `bonnie.submissions.actions` | `Array actions, { runBatch, reload }` | DataViews row/bulk actions. `runBatch(action, items)` posts to the batch endpoint. |
| `bonnie.settings.privacyControls` | `Array controls, api` | Controls appended to the Privacy card. `api` = `{ settings, set, on, str, toggle, number, policy }`. |
| `bonnie.settings.retentionPolicyOptions` | `Array options` | Retention policy radio options (free: retain, delete). |
| `bonnie.settings.retentionControls` | `Array controls, api` | Controls appended to the Retention card. |
| `bonnie.settings.showUpsell` | `boolean show` | Whether the free build shows the "Bonnie Pro" info card. Pro filters this off. |

---

### Free / Pro split (reference)

- **Free:** capture · list · search · detail · single permanent delete · one
  global delete-only auto-purge · IP-off default · WP Tools Export/Erase
  (subject-access).
- **Pro (in `bonnie-pro`):** bulk delete · CSV/JSON export · trash + hard-cap ·
  per-form overrides · address book · IP anonymisation · encryption-at-rest ·
  WP-CLI.
