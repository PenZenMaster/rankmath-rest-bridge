# White-Label Configuration

The RankRocket SEO Control Layer supports two levels of white-labelling. All
configuration is done via constants in `wp-config.php`. No database settings
are involved and no admin UI option can override a defined constant.

---

## Tier 1 — Rename

Replaces the plugin's visible identity on the WordPress Plugins screen and in
the admin menu. Define any subset of the constants below; omit any you do not
need to change.

```php
// wp-config.php

define( 'RRSEO_WL_NAME',        'Acme SEO' );           // Plugins list name + admin menu label
define( 'RRSEO_WL_DESCRIPTION', 'SEO control layer.' ); // Plugins list description
define( 'RRSEO_WL_AUTHOR',      'Acme Agency' );        // Author name in Plugins list
define( 'RRSEO_WL_AUTHOR_URL',  'https://acme.com' );   // Author link in Plugins list
define( 'RRSEO_WL_SUPPORT_URL', 'https://acme.com/support' ); // Adds a Support row link
```

**What changes:**

| Location | Before | After |
|---|---|---|
| Plugins screen — Name | RankRocket SEO | Acme SEO |
| Plugins screen — Description | (original) | (your text) |
| Plugins screen — Author | Rank Rocket Co | Acme Agency |
| Admin menu item | RankRocket SEO | Acme SEO |
| Browser tab / page `<h1>` | RankRocket SEO | Acme SEO |

---

## Tier 2 — Hide

Removes the plugin entry from the Plugins screen entirely. The plugin remains
active and fully functional; it simply becomes invisible to site admins.

```php
// wp-config.php

define( 'RRSEO_WL_HIDE_PLUGIN', true );
```

> **Note:** Tier 2 takes precedence over all Tier 1 constants. When
> `RRSEO_WL_HIDE_PLUGIN` is `true`, the rename constants have no visible
> effect because there is no row to rename.

---

## Updates when the plugin is hidden (Tier 2)

Even when the plugin is invisible on the Plugins screen, it still receives
updates via the background PUC update checker. The update row is suppressed on
Dashboard > Updates as well, so the plugin name never surfaces to site admins.

Updates can be applied through any of these methods:

| Method | Command / steps |
|---|---|
| WordPress auto-updates | Enable auto-updates in `wp-config.php` (see below) |
| WP-CLI | `wp plugin update rankmath-rest-bridge` |
| Manual zip upload | Plugins > Add New > Upload Plugin, overwrite slug |

**Enable auto-updates via `wp-config.php`:**

```php
// Auto-update this plugin only.
add_filter(
    'auto_update_plugins',
    function ( $plugins ) {
        $plugins[] = 'rankmath-rest-bridge/rankmath-rest-bridge.php';
        return $plugins;
    }
);
```

---

## Notes

- Constants must be defined **before** WordPress loads the plugin (i.e., at
  the top of `wp-config.php`, above the `/* That's all, stop editing! */` line).
- There is no fallback UI to change these values at runtime — a code deploy or
  `wp-config.php` edit is required.
- The plugin's internal REST namespace (`rankrocket-seo/v1`) and option keys
  are unaffected by white-label constants.
