# UserSpice ReBrand Plugin

> Seamlessly manage your site branding in UserSpice: logo preview & updates, offline favicon/app icon generation, cache-busting, and safe header/menu integration — all from one admin screen.

## Key Features
- **User ID 1 only**: Only the primary owner (user id = 1) can change branding.
- **Logo manager with preview**: Upload (PNG/JPG), optional resize, instant preview, and cache-busted delivery.
- **Offline favicon/app icons generator**: No external calls. Generates:
  - `.ico` with 16/32/48 (optionally 64) layers,
  - PNG set (180/192/256/384/512) for modern browsers/PWAs,
  - Pre-baked HTML snippet for `<head>` (PWA-related tags **commented by default**).
- **Head tags integration**: Safely injects favicon `<link>`s (and commented PWA tags) into `usersc/includes/head_tags.php` between plugin markers. Automatic timestamped backups and one-click revert.
- **Menu DB integration**: Injects/updates the **logo block** and **social links** inside the UserSpice **menu tables** only within plugin markers; keeps row-level backups and supports restore.
- **Cache-busting**: Every asset update increments `asset_version`, appended like `?v=<n>` to defeat stale caches.

## Requirements
- **UserSpice v5** (tested against current master).
- **Form Builder plugin** (for CSRF tokens and secure form handling):  
  https://github.com/mudmin/usplugins/tree/master/src/forms  
  > Install & enable **Form Builder** before using ReBrand. This plugin depends on its CSRF token helpers.
- **PHP GD** (preferred) or Imagick for image processing.  
  If neither is available, you can still upload a favicon/logo, but offline generation/resizing is disabled.

## Installation (via UI)
1. Copy this plugin folder to:  
   `usersc/plugins/rebrand/`
2. In the UserSpice admin, go to **Plugins → ReBrand → Install**.
3. Ensure **Form Builder** is installed and enabled.
4. Navigate to **Plugins → ReBrand → Settings**. You must be **User ID 1** to access it.

## Quick install via CLI (AWS/Linux)
> Use this if you have shell/terminal access (e.g., EC2). Adjust the web root path and web user/group for your stack.

**Option A: clone then move**
```bash
cd /var/www/html
git clone https://github.com/tocsindata/userspice_rebrand_plugin.git
mkdir -p usersc/plugins
mv userspice_rebrand_plugin usersc/plugins/rebrand
# Set permissions/ownership for your web server user (examples below)
sudo chown -R www-data:www-data usersc/plugins/rebrand    # Debian/Ubuntu
# or
sudo chown -R apache:apache usersc/plugins/rebrand        # Amazon Linux/AlmaLinux/RHEL
````

**Option B: clone directly into the target folder (no mv)**

```bash
cd /var/www/html
mkdir -p usersc/plugins
git clone https://github.com/tocsindata/userspice_rebrand_plugin.git usersc/plugins/rebrand
sudo chown -R www-data:www-data usersc/plugins/rebrand    # or apache:apache
```

**Don’t forget:** install the **Form Builder** plugin too (required for CSRF):

```bash
# Example: place Form Builder under usplugins if needed, or follow its README
# https://github.com/mudmin/usplugins/tree/master/src/forms
```

Then log into UserSpice admin:

* Go to **Plugins → ReBrand → Install**
* Verify **Form Builder** is enabled
* Open **Plugins → ReBrand → Settings** to configure

## What this plugin edits / creates

* **File:** `usersc/includes/head_tags.php`

  * Injects favicon `<link>`s and (commented) PWA tags **between**:

    ```html
    <!-- ReBrand START -->
    ... plugin-managed content ...
    <!-- ReBrand END -->
    ```
  * Makes a **timestamped backup** before every write.
* **Directory:** `users/images/rebrand/`

  * Stores `logo.png` (and optional dark variant).
* **Directory:** `users/images/rebrand/icons/`

  * Stores generated `.ico` and PNG icon sizes for favicons/PWAs.
* **Database:** UserSpice **menu tables**

  * Patches only the content **inside our markers** in the specific menu rows/items that render the header logo/social links.
  * Keeps **row-level backups** in a plugin-managed backup table for one-click **revert**.

## Access Control & Security

* **User ID 1 only** can view and apply changes.
* **CSRF** protection via **Form Builder** tokens on all POSTs.
* **Strict file handling**:

  * MIME validation (magic bytes), extension whitelist,
  * max file size & dimension caps,
  * atomic writes to avoid partial files,
  * no user-controlled filesystem paths.
* **XSS-safe** display of admin-entered fields (URLs, alt text, etc.).
* **URL validation** for social links (http/https only).

## Admin Workflow (high level)

1. **Logo**: Upload → optional resize → Save. Preview updates immediately; `asset_version` increments.
2. **Favicons**: Choose single-file mode or **Generate offline icons**. Generated snippet (with PWA lines commented) is saved and ready for insertion.
3. **Apply Head Tags**: Toggle to update `usersc/includes/head_tags.php`. You can view a diff and **revert**.
4. **Menu Integration**: Select target **menu items** to patch. The plugin injects only within markers; you can **revert** to the pre-patch snapshot.
5. **Social Links**: Toggle per-platform and enter URLs. The plugin regenerates the icons list inside the markers.

## Uninstall / Cleanup

* Disables menu patches (optional restore) and removes plugin settings.
* Leaves file backups unless you explicitly choose to remove them.
* Leaves `usersc/includes/head_tags.php` in its last good state unless you revert first (recommended).

## Troubleshooting

* **No changes visible?** Clear your browser cache. The plugin uses cache-busting, but CDNs/proxies may still hold old assets.
* **Missing CSRF token errors?** Ensure the **Form Builder** plugin is installed and enabled.
* **Image upload fails?** Check PHP GD availability and file size limits; verify write permissions on `users/images/rebrand/`.

## License

MIT (see `LICENSE`).

## Changelog (highlights)

* `v1.0.0` — Initial release with:

  * User ID 1 gating, Form Builder CSRF,
  * Logo preview & cache-busting,
  * Offline favicon/app icons generator,
  * Head tags patching with backups & markers,
  * Menu DB patching (logo + socials) with backups.
 