<?php
// usersc/plugins/rebrand/configure.php
// Minimal shim so the v5 Plugin Manager's "Configure" button lands on our UI.

$init = null;
for ($i = 0; $i < 6; $i++) {
  $try = realpath(__DIR__ . str_repeat(DIRECTORY_SEPARATOR . '..', $i) . '/users/init.php');
  if ($try && file_exists($try)) { $init = $try; break; }
}
if ($init) {
  require_once $init;
} else {
  die('ReBrand: could not locate users/init.php');
}

// Ensure we have a DB instance even if $db isn't global in this scope
if (!isset($db) || !($db instanceof DB)) {
  $db = DB::getInstance();
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
