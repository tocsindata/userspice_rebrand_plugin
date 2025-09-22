<?php
/**
 * UserSpice ReBrand Plugin — Installer (tidy)
 *
 * Creates/ensures:
 *   - us_rebrand_settings (singleton row id=1)
 *   - us_rebrand_menu_backups
 *   - us_rebrand_file_backups
 *   - us_rebrand_site_backups   <-- NEW (backs up settings.site_name & site_url)
 *
 * Notes
 * - Safe to re-run; DDL uses IF NOT EXISTS, and settings row is upserted.
 * - Restricts execution to User ID 1.
 */

if (!defined('ABS_US_ROOT') && !defined('US_URL_ROOT') && !isset($abs_us_root)) {
  $root = realpath(__DIR__ . '/../../..'); // usersc/plugins/rebrand -> usersc
  $init = $root . '/users/init.php';
  if (file_exists($init)) {
    require_once $init;
  }
}

if (!isset($db)) {
  die('ReBrand installer: UserSpice DB context not available.');
}

$userId = $user->data()->id ?? null;
if ((int)$userId !== 1) {
  die('ReBrand installer: Only User ID 1 may run the installer.');
}

$tableSettings        = 'us_rebrand_settings';
$tableMenuBackups     = 'us_rebrand_menu_backups';
$tableFileBackups     = 'us_rebrand_file_backups';
$tableSiteBackups     = 'us_rebrand_site_backups';

$messages = [];
$errors   = [];

try {
  // 1) Settings table (singleton)
  $db->query("
    CREATE TABLE IF NOT EXISTS `{$tableSettings}` (
      `id` INT UNSIGNED NOT NULL,
      `asset_version` INT UNSIGNED NOT NULL DEFAULT 1,
      `logo_path` VARCHAR(255) DEFAULT 'users/images/rebrand/logo.png',
      `logo_dark_path` VARCHAR(255) DEFAULT NULL,
      `favicon_mode` ENUM('single','multi') DEFAULT 'single',
      `favicon_root` VARCHAR(255) DEFAULT 'users/images/rebrand/icons',
      `favicon_html` MEDIUMTEXT DEFAULT NULL,
      `social_links` MEDIUMTEXT DEFAULT NULL,
      `menu_target_ids` MEDIUMTEXT DEFAULT NULL,
      `header_override_enabled` TINYINT(1) NOT NULL DEFAULT 1,
      `id1_only` TINYINT(1) NOT NULL DEFAULT 1,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  ");
  $messages[] = "Ensured table {$tableSettings}";

  // Seed/Upsert singleton row id=1
  $exists = $db->query("SELECT id FROM `{$tableSettings}` WHERE id = 1 LIMIT 1")->first();
  if (!$exists) {
    $db->insert($tableSettings, [
      'id'                      => 1,
      'asset_version'           => 1,
      'logo_path'               => 'users/images/rebrand/logo.png',
      'logo_dark_path'          => null,
      'favicon_mode'            => 'single',
      'favicon_root'            => 'users/images/rebrand/icons',
      'favicon_html'            => null,
      'social_links'            => json_encode(new stdClass()),
      'menu_target_ids'         => json_encode([]),
      'header_override_enabled' => 1,
      'id1_only'                => 1,
    ]);
    $messages[] = "Seeded {$tableSettings} row id=1";
  } else {
    $messages[] = "{$tableSettings} row id=1 already present";
  }

  // 2) Menu backups
  $db->query("
    CREATE TABLE IF NOT EXISTS `{$tableMenuBackups}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `menu_id` INT NULL,
      `menu_item_id` INT NULL,
      `menu_name` VARCHAR(255) NULL,
      `content_backup` MEDIUMTEXT NOT NULL,
      `notes` VARCHAR(255) NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_menu_item_id` (`menu_item_id`),
      KEY `idx_menu_id` (`menu_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  ");
  $messages[] = "Ensured table {$tableMenuBackups}";

  // 3) File backups (e.g., usersc/includes/head_tags.php)
  $db->query("
    CREATE TABLE IF NOT EXISTS `{$tableFileBackups}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `path` VARCHAR(512) NOT NULL,
      `content_backup` MEDIUMTEXT NOT NULL,
      `notes` VARCHAR(255) NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_path` (`path`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  ");
  $messages[] = "Ensured table {$tableFileBackups}";

  // 4) Site settings backups (NEW)
  $db->query("
    CREATE TABLE IF NOT EXISTS `{$tableSiteBackups}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `settings_id` INT NOT NULL,
      `site_name_backup` VARCHAR(100) NOT NULL,
      `site_url_backup` VARCHAR(255) NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_settings_id` (`settings_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  ");
  $messages[] = "Ensured table {$tableSiteBackups}";

} catch (Exception $e) {
  $errors[] = 'Install error: ' . htmlspecialchars($e->getMessage());
}

// Minimal output for plugin manager context
?>
<div class="card">
  <div class="card-header"><strong>ReBrand — Install</strong></div>
  <div class="card-body">
    <?php if (!empty($messages)): ?>
      <div class="alert alert-success" style="white-space: pre-wrap;">
        <?= htmlspecialchars(implode("\n", $messages), ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger" style="white-space: pre-wrap;">
        <?= htmlspecialchars(implode("\n", $errors), ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>
    <p>Installer finished. You can proceed to the <a href="<?= isset($us_url_root) ? $us_url_root : '/' ?>usersc/plugins/rebrand/admin/settings.php">ReBrand Settings</a>.</p>
  </div>
</div>
