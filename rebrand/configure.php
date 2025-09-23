<?php
// usersc/plugins/rebrand/configure.php

// Robustly locate /users/init.php from anywhere under the plugin folder
$init = null;
for ($i = 0; $i < 6; $i++) {
  $try = realpath(__DIR__ . str_repeat(DIRECTORY_SEPARATOR . '..', $i) . '/users/init.php');
  if ($try && file_exists($try)) { $init = $try; break; }
}
if ($init) { require_once $init; } else { die('ReBrand: could not locate users/init.php'); }

// Ensure DB handle even if $db isn't global in this scope
if (!isset($db) || !($db instanceof DB)) { $db = DB::getInstance(); }

// Access control: only logged-in user id 1
if (!isset($user) || !$user->isLoggedIn() || (int)$user->data()->id !== 1) {
  // bounce to admin dashboard if someone wanders in
  Redirect::to($us_url_root.'users/admin.php');
  exit;
}

// Page title (optional)
if (!isset($settings)) { $settings = $db->query("SELECT * FROM settings LIMIT 1")->first(); }
$title = 'ReBrand Settings';

// Standard admin header
require_once $abs_us_root.$us_url_root.'users/includes/template/header.php';
// Optional: left nav
require_once $abs_us_root.$us_url_root.'users/includes/navigation.php';

// Include our UI partial (no header/footer inside)
require __DIR__ . '/admin/settings.php';

// Standard admin footer
require_once $abs_us_root.$us_url_root.'users/includes/template/footer.php';
