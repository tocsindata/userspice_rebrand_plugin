
# UserSpice Rebrand Plugin (KISS Edition)

This plugin lets you quickly rebrand a UserSpice 5 site with your own name, logo, favicon, head tags, social links, and `.htaccess` rules.

## Features

- **Site Settings**  
  Update `site_name`, `site_url`, `contact_email`, and `copyright` in the `settings` table.

- **Brand Assets**  
  - Overwrite `favicon.ico` in the web root.  
  - Overwrite `users/images/logo.png` (the default UserSpice logo).  
  No resizing, no backups, just straight replacement.

- **Head Tags / Meta Manager**  
  Edit `usersc/includes/head_tags.php` in a plain textarea.  
  Pre-filled with whatever is currently there. On save, the file is overwritten.

- **Social Links Menu**  
  Auto-creates a `menus` row called `rebrand_social` if missing.  
  Lets you add, edit, or delete links (with label, URL, FontAwesome icon class, and target).

- **.htaccess Editor**  
  Plain textarea editor for the `.htaccess` file at the web root.  
  Pre-filled with the current contents.

## Requirements

- UserSpice 5
- Logged in as **User ID = 1** (only master account can access)
- File system writable for:
  - `<webroot>/favicon.ico`
  - `<webroot>/users/images/logo.png`
  - `<webroot>/usersc/includes/head_tags.php`
  - `<webroot>/.htaccess`
  - plugin form builder
  - not required, but suggested plugin getsettings

## Installation

1. Place this plugin folder under:
```

usersc/plugins/rebrand/

```
2. Make sure the folder contains:
- `plugin_info.php` (plugin metadata)
- `configure.php` (the admin page)

3. In the UserSpice Admin Panel → Plugins, activate **Rebrand**.

4. Configure under **Admin → Plugins → Rebrand → Configure**.

## Philosophy

This edition is deliberately **simple**:

- No backups
- No resizing
- No extra classes
- Just overwrite files and update DB rows

Keep it simple, stupid (KISS).

## Notes

- Editing `.htaccess` or `head_tags.php` incorrectly can break your site. Use with care.
- FontAwesome icon classes (e.g., `fab fa-facebook`) are supported in the Social Links menu.

---

Enjoy your own branding on UserSpice!
```
