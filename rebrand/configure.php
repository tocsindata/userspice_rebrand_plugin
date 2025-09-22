<?php
// usersc/plugins/rebrand/configure.php
// Minimal shim so the v5 Plugin Manager's "Configure" button lands on our UI.

if (!defined('ABS_US_ROOT') && !defined('US_URL_ROOT') && !isset($abs_us_root)) {
  $root = realpath(__DIR__ . '/../../..'); // usersc/plugins/rebrand -> usersc
  $init = $root . '/users/init.php';
  if (file_exists($init)) {
    require_once $init;
  }
}

if (!isset($db)) {
  die('ReBrand: UserSpice context not available.');
}

// Hard requirement: only User ID 1 may access the admin UI
$userId = $user->data()->id ?? null;
if ((int)$userId !== 1) {
  die('ReBrand: Only User ID 1 may access these settings.');
}

// Hand off to our full admin UI
require_once __DIR__ . '/admin/settings.php';
