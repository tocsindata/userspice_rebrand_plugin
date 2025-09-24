<?php
// usersc/plugins/rebrand/configure.php

// ⚠️ No header/footer includes here. The Plugin Manager provides the chrome.

// Load UserSpice if not already loaded (keeps plugin usable both inside and outside the manager)
if (!class_exists('DB')) {
  // From usersc/plugins/rebrand/ → ../../users/init.php
  $init = realpath(__DIR__ . '/../../users/init.php');
  if ($init && file_exists($init)) {
    require_once $init;
  } else {
    // Fail loudly but cleanly
    die('Rebrand plugin: unable to locate users/init.php');
  }
}

// Access control: only user ID 1
global $user, $abs_us_root, $us_url_root;
if (!isset($user) || !$user->isLoggedIn() || (int)$user->data()->id !== 1) {
  Redirect::to($us_url_root . 'users/admin.php');
  exit;
}

// Always acquire DB via DB::getInstance() when needed (no $db param or reliance on global)
$db = DB::getInstance();

// For clean navigation back to the Plugin Manager
$returnUrl = $us_url_root . 'users/admin.php?view=plugins_config&plugin=rebrand';

try {
  // Do any lightweight reads via $db as needed (example: settings header/title)
  // Keeping it optional and safe: if table/row structure changes, UI still loads.
  $siteSettings = null;
  try {
    $q = $db->query("SELECT * FROM settings LIMIT 1");
    if ($q && $q->count() > 0) {
      $siteSettings = $q->first();
    }
  } catch (Exception $ignored) {
    // Non-fatal; continue rendering UI
  }

  // Provide a simple page title variable if your admin/settings.php expects/uses it.
  $title = 'ReBrand Settings';

  // Render ONLY the plugin UI content; no header/footer here.
  require __DIR__ . '/admin/settings.php';

} catch (Throwable $e) {
  // Log and bounce back to the Plugin Manager with a safe message
  try {
    logger((int)$user->data()->id, 'Rebrand', 'configure.php failed: ' . $e->getMessage());
  } catch (Throwable $ignored) {
    // If logger() fails, still redirect.
  }
  Redirect::to($returnUrl . '&msg=Rebrand%20configure%20error');
  exit;
}
