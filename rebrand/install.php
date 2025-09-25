<?php
/**
 * ReBrand — install.php
 * Runs from Plugin Manager during plugin install/enable.
 *
 * - Creates backup tables (IF NOT EXISTS)
 * - Ensures filesystem directories exist
 * - Seeds storage/versions/asset_version.json with 1 (if missing)
 *
 * Security: require User ID 1 (super admin).
 */

if (!isset($user) || (int)($user->data()->id ?? 0) !== 1) {
  die('Admin only.');
}

// Flash helpers (Plugin Manager usually reads $_SESSION["msg"])
if (!function_exists('usSuccess')) {
  function usSuccess($msg){ $_SESSION['msg'][] = ['type'=>'success','msg'=>$msg]; }
}
if (!function_exists('usError')) {
  function usError($msg){ $_SESSION['msg'][] = ['type'=>'danger','msg'=>$msg]; }
}

$db = DB::getInstance();

// ---------- 1) Create Tables ----------
try {
  // Stores full previous contents of modified files (e.g., head_tags.php, icons)
  $db->query("
    CREATE TABLE IF NOT EXISTS `us_rebrand_file_backups` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `took_at` DATETIME NOT NULL,
      `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
      `file_path` VARCHAR(255) NOT NULL,
      `content_backup` LONGBLOB NULL,
      `notes` VARCHAR(255) NULL,
      PRIMARY KEY (`id`),
      KEY `took_at` (`took_at`),
      KEY `user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // Stores menu structure snapshots (JSON dump or serialized data)
  $db->query("
    CREATE TABLE IF NOT EXISTS `us_rebrand_menu_backups` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `took_at` DATETIME NOT NULL,
      `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
      `menu_json` MEDIUMTEXT NULL,
      `notes` VARCHAR(255) NULL,
      PRIMARY KEY (`id`),
      KEY `took_at` (`took_at`),
      KEY `user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // Stores snapshots of the main settings table
  $db->query("
    CREATE TABLE IF NOT EXISTS `us_rebrand_site_backups` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `took_at` DATETIME NOT NULL,
      `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
      `site_name` VARCHAR(150) NULL,
      `site_url` VARCHAR(255) NULL,
      `copyright` VARCHAR(255) NULL,
      `contact_email` VARCHAR(150) NULL,
      `notes` VARCHAR(255) NULL,
      PRIMARY KEY (`id`),
      KEY `took_at` (`took_at`),
      KEY `user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  usSuccess('ReBrand: database tables verified/created.');
} catch (Exception $e) {
  usError('ReBrand: failed creating tables — '.$e->getMessage());
}

// ---------- 2) Ensure Directories ----------
$iconsDirFs = rtrim($abs_us_root.$us_url_root, '/').'/users/images/rebrand/icons';
$pluginStorageDir = __DIR__.'/storage';
$versionDir = $pluginStorageDir.'/versions';

foreach ([$iconsDirFs, $pluginStorageDir, $versionDir] as $dir) {
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
}
if (is_dir($iconsDirFs)) {
  usSuccess('ReBrand: icons directory ready: '.$iconsDirFs);
} else {
  usError('ReBrand: unable to create icons directory: '.$iconsDirFs);
}

// Optional: write a minimal .htaccess to the plugin storage to reduce exposure (non-fatal)
$htaccess = $pluginStorageDir.'/.htaccess';
if (!is_file($htaccess)) {
  @file_put_contents($htaccess, "Options -Indexes\n", LOCK_EX);
}

// ---------- 3) Seed Asset Version ----------
$versionFile = $versionDir.'/asset_version.json';
if (!is_file($versionFile)) {
  @file_put_contents($versionFile, json_encode(1, JSON_UNESCAPED_SLASHES), LOCK_EX);
  if (is_file($versionFile)) {
    usSuccess('ReBrand: initialized asset_version.json to 1.');
  } else {
    usError('ReBrand: failed to initialize asset_version.json.');
  }
} else {
  // Validate it’s an integer; if not, reset to 1
  $raw = @file_get_contents($versionFile);
  $val = json_decode($raw ?: '1', true);
  if (!is_int($val)) {
    @file_put_contents($versionFile, json_encode(1, JSON_UNESCAPED_SLASHES), LOCK_EX);
    usSuccess('ReBrand: repaired invalid asset_version.json (reset to 1).');
  }
}

// Done — Plugin Manager will continue flow.
// No redirect here; this file is included by the installer context.
