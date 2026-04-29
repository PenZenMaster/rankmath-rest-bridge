# Auto-Update Staging Verification Checklist

> Run this checklist on a staging WordPress site after each release push.
> Current target version: **2.9.1**
> Requires WP-CLI and SSH access to the staging server.

---

## Why the update may not appear immediately

PUC caches the update-check result for 12 hours (configurable via the
`rrseo_puc_check_period_hours` filter). WordPress also caches plugin update data in
the `update_plugins` site transient. Both must be cleared before an update notification
appears. As of v2.9.1, the admin panel Overview page has a **Force Update Check**
button that clears both caches in one click.

---

## Prerequisites

- Plugin installed and active: `rankmath-rest-bridge/rankmath-rest-bridge.php`
- `update-manifest.json` pushed to GitHub main branch
- `releases/vX.Y.Z/rankmath-rest-bridge.zip` pushed to GitHub main branch
- Staging site has outbound HTTPS access to `raw.githubusercontent.com`

---

## Step 1 — Confirm manifest is live and parseable

```bash
curl -s https://raw.githubusercontent.com/PenZenMaster/rankmath-rest-bridge/main/update-manifest.json \
  | python -m json.tool
```

Expected: valid JSON with `name`, `version`, `download_url`, `slug`, `sections`.

---

## Step 2 — Confirm the release zip is reachable

```bash
# Replace X.Y.Z with the release version
curl -o /dev/null -w "%{http_code}" \
  https://raw.githubusercontent.com/PenZenMaster/rankmath-rest-bridge/main/releases/vX.Y.Z/rankmath-rest-bridge.zip
```

Expected: `200`

---

## Step 3 — Force an immediate update check (two options)

### Option A — Admin panel button (recommended)

1. Log in to the WordPress admin.
2. Navigate to **RankRocket SEO → Overview**.
3. Click **Force Update Check**.
4. Wait for the success message, then navigate to **Dashboard → Updates**.

### Option B — WP-CLI

```bash
# Clear WordPress plugin update transient
wp transient delete update_plugins --path=/path/to/wp

# Clear PUC's own state for this plugin
wp option delete external_updates-rankmath-rest-bridge --path=/path/to/wp

# Force an immediate synchronous check
wp plugin update-check --path=/path/to/wp
```

---

## Step 4 — Confirm update notification appears

Navigate to **WordPress Admin → Dashboard → Updates**.

Expected: RankRocket SEO Control Layer should appear in the available updates list
showing the new version number.

---

## Step 5 — Simulate update via REST endpoint

```bash
curl -s -X POST \
  -u admin:YOUR_APPLICATION_PASSWORD \
  https://staging.example.com/wp-json/rankrocket-seo/v1/self-update \
  | python -m json.tool
```

Expected response:
```json
{
  "success": true,
  "from_version": "2.9.0",
  "to_version": "2.9.1",
  "zip_url": "https://raw.githubusercontent.com/...",
  "message": "Updated from 2.9.0 to 2.9.1. Plugin re-activated."
}
```

---

## Step 6 — Verify plugin is active and correct version post-update

```bash
wp plugin status rankmath-rest-bridge --path=/path/to/wp
```

Or via the REST API:
```bash
curl -s -u admin:APP_PASS \
  https://staging.example.com/wp-json/rankrocket-seo/v1/status \
  | python -m json.tool | grep version
```

Expected: `"version": "2.9.1"` (or current target version).

---

## Step 7 — Reduce check period for faster future testing (optional)

Add this to `wp-config.php` or a site-specific plugin on staging only:

```php
add_filter( 'rrseo_puc_check_period_hours', function() { return 1; } );
```

This reduces the PUC check interval to 1 hour on the staging site without
affecting production.

---

## Pass Criteria

- [x] Manifest is valid JSON with all required PUC fields
- [x] Zip URL returns HTTP 200
- [ ] Force Update Check button (or WP-CLI) successfully clears caches
- [ ] WordPress Dashboard shows update notification with correct version
- [ ] `POST /self-update` returns `success: true`
- [ ] Plugin remains active post-upgrade
- [ ] `GET /status` returns correct version post-upgrade
