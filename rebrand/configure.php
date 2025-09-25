<?php
/**
 * ReBrand — configure.php
 * Entry point for the Plugin Manager "Configure" button/tile.
 *
 * URL (from admin): users/admin.php?view=plugins_config&plugin=rebrand
 * Optional panel:   users/admin.php?view=plugins_config&plugin=rebrand&panel=menus|backups
 *
 * Security: Admin only (User ID 1). No header/footer includes here—the
 *           Plugin Manager wraps this file for you.
 */

if (!isset($user) || (int)($user->data()->id ?? 0) !== 1) {
  die('Admin only.');
}

// panel router (defaults to settings)
$panel = isset($_GET['panel']) ? (string)$_GET['panel'] : 'settings';
$panel = strtolower($panel);

// small, self-contained nav (Bootstrap-friendly)
$base = $us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand';
?>
<div class="mb-3">
  <ul class="nav nav-pills">
    <li class="nav-item">
      <a class="nav-link <?= $panel === 'settings' ? 'active' : '' ?>" href="<?=$base?>">Settings</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $panel === 'menus' ? 'active' : '' ?>" href="<?=$base?>&panel=menus">Menus</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $panel === 'backups' ? 'active' : '' ?>" href="<?=$base?>&panel=backups">Backups</a>
    </li>
  </ul>
</div>
<?php

// include the requested admin panel
$root = __DIR__.'/admin/';
switch ($panel) {
  case 'menus':
    require_once $root.'menus.php';
    break;
  case 'backups':
    require_once $root.'backups.php';
    break;
  case 'settings':
  default:
    require_once $root.'settings.php';
    break;
}
