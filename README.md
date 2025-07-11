# UserSpice ReBrand Plugin

**Version:** 1.0.0  
**Author:** Daniel Foscarini  
**Tested With:** UserSpice 5.8.4  
**Website:** [https://tocsindata.com](https://tocsindata.com)

---

## 📌 Description

**UserSpice ReBrand** allows you to customize the front-end branding of your UserSpice site without modifying core files. It supports dynamic logo replacement, custom alt text, icon placement, responsive CSS injection, and social media branding with FontAwesome icons — all scoped to your selected navigation menu.

This plugin was designed for User ID 1 (Super Admin) to streamline rebranding during deployments and white-label installations.

---

## 🚀 Features

- ✅ Upload and replace `logo.png` (used in site navigation)
- ✅ Inject scoped CSS directly into `<head>` (logo-specific)
- ✅ Set custom image `alt` text and dimensions
- ✅ Assign branding to a specific `us_menus` row
- ✅ Manage FontAwesome social icons (size, color, URL, label)
- ✅ Auto-creates all required plugin tables on install
- ✅ Compatible with both old and new UserSpice hook systems

---

## 🔧 Installation

1. **Copy the plugin folder** to:  
   `usersc/plugins/rebrand`

2. **Run the installation script** by clicking "Activate" in the Plugin Manager.  
   This will:
   - Register the plugin in `us_plugins`
   - Create all required tables
   - Inject branding hook into `<head>` if supported

3. **Configure the plugin** via:  
   `Admin Dashboard → Plugins → Configure → Tocsin ReBrand`

---

## 🖼️ Usage

- Replace the site logo by uploading a new `logo.png` via the plugin interface.
- Add optional custom CSS that applies **only** to the logo using the “Logo CSS” textarea.
- Assign the updated logo and icons to any `us_menus` menu by selecting it from the dropdown.
- Social media icons can be added with FontAwesome classes, URLs, and optional labels.

---

## ⚠️ Notes

- The plugin is restricted to `User ID 1` for security and control.
- All logo uploads overwrite `/users/images/logo.png`.
- Existing hook conflicts from prior versions (e.g. `TocsinReBrand`) may prevent functionality. These must be removed manually if lingering in `us_plugin_hooks`.

---

## 📂 Database Tables Created

- `plg_tocsinrebrand` — stores logo path, alt text, CSS, and domain
- `plg_tocsinrebrand_settings` — menu assignment and icon preferences
- `plg_tocsinrebrand_icons` — list of FontAwesome icons and links

---

## 🔄 Uninstallation

Click "Uninstall" from the Plugin Manager to:

- Drop all related tables
- Unregister hooks
- Remove plugin entry from `us_plugins`

---

## 📜 License

This plugin is released under the MIT License.  
© 2025 Daniel Foscarini
