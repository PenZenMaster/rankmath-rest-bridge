# Auto-Update Staging Verification Checklist

> Run this checklist on a staging WordPress site after each release push.
> Requires WP-CLI and SSH access to the staging server.

---

## Prerequisites

- Plugin installed and active: `rankmath-rest-bridge/rankmath-rest-bridge.php`
- `update-manifest.json` pushed to GitHub main branch
- `releases/vX.Y.Z/rankmath-rest-bridge.zip` pushed to GitHub main branch
- Staging site has outbound HTTPS access to `raw.githubusercontent.com`

---

## Step 1 — Confirm manifest is live and parseable

```bash
curl -s https://raw.githubusercontent.com/PenZenMaster/rankmath-rest-bridge/main/update-manifest.json | python -m json.tool
```

Expected: valid JSON with `name`, `version`, `download_url`, `slug`, `sections`.

---

## Step 2 — Confirm zip is reachable

```bash
# Replace X.Y.Z with the release version
curl -o /dev/null -w "%{http_code}" \
  https://raw.githubusercontent.com/PenZenMaster/rankmath-rest-bridge/main/releases/vX.Y.Z/rankmath-rest-bridge.zip
```

Expected: `200`

---

## Step 3 — Force WP to recheck for updates

```bash
wp transient delete update_plugins --path=/path/to/wp
wp transient delete puc_api_response_rankmath-rest-bridge --path=/path/to/wp
```

---

## Step 4 — Bump manifest version temporarily for update simulation

On your local machine, edit `update-manifest.json` — set `version` to one higher
than what is installed (e.g., `99.0.0`). Push to GitHub. Wait 30 seconds for CDN
propagation, then on staging:

```bash
wp transient delete update_plugins --path=/path/to/wp
wp transient delete puc_api_response_rankmath-rest-bridge --path=/path/to/wp
```

Then in the WP Admin > Dashboard > Updates — confirm the plugin appears with an
update notification.

---

## Step 5 — Trigger update via REST endpoint

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
  "from_version": "2.4.0",
  "to_version": "99.0.0",
  "zip_url": "https://raw.githubusercontent.com/...",
  "message": "Updated from 2.4.0 to 99.0.0. Plugin re-activated."
}
```

---

## Step 6 — Restore real version in manifest

Revert `update-manifest.json` to the real version. Push to GitHub.

---

## Step 7 — Verify plugin is still active after update

```bash
wp plugin status rankmath-rest-bridge --path=/path/to/wp
wp eval "echo (new WP_REST_Request())->get_headers();" --path=/path/to/wp
# Or:
curl -s -u admin:APP_PASS https://staging.example.com/wp-json/rankrocket-seo/v1/status
```

Expected: `version` field in the JSON response matches the restored real version.

---

## Pass Criteria

- [x] Manifest is valid JSON with all required PUC fields
- [x] Zip URL returns HTTP 200
- [x] WP Dashboard shows update notification when manifest version > installed version
- [x] `POST /self-update` returns `success: true`
- [x] Plugin remains active after the upgrade
- [x] `GET /status` returns the correct version post-upgrade
