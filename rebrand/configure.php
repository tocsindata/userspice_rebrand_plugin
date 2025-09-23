<?php
// usersc/plugins/rebrand/configure.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Robust init
$init = null;
for ($i = 0; $i < 6; $i++) {
  $try = realpath(__DIR__ . str_repeat(DIRECTORY_SEPARATOR . '..', $i) . '/users/init.php');
  if ($try && file_exists($try)) { $init = $try; break; }
}
if ($init) { require_once $init; } else { die('ReBrand: could not locate users/init.php'); }

// Ensure DB instance
if (!isset($db) || !($db instanceof DB)) { $db = DB::getInstance(); }

// Access control
if (!isset($user) || !$user->isLoggedIn() || (int)$user->data()->id !== 1) {
  Redirect::to($us_url_root.'users/admin.php');
  exit;
}

// Output only the plugin content (Plugin Manager wraps this with admin chrome)
require __DIR__ . '/admin/settings.php';

// Page title (optional)
if (!isset($settings)) { $settings = $db->query("SELECT * FROM settings LIMIT 1")->first(); }
$title = 'ReBrand Settings';


// Include our UI partial (no header/footer inside)
require __DIR__ . '/admin/settings.php';

// Standard admin footer
require_once $abs_us_root.$us_url_root.'users/includes/template/footer.php';
