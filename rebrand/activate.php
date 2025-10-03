<?php
// usersc/plugins/rebrand/activate.php

if (!in_array($user->data()->id, $master_account)) { return; }

require_once __DIR__ . '/plugin_info.php'; // $plugin_name, $plugin_version

$updatesJson = !empty($plugin_version) ? json_encode([$plugin_version], JSON_UNESCAPED_SLASHES) : NULL;

$db->query("UPDATE us_plugins
            SET status = 'active', updates = ?, last_check = ?
            WHERE plugin = ?",
           [$updatesJson, date('Y-m-d H:i:s'), $plugin_name]);

if (!$db->error()) {
  err($plugin_name . ' activated');
  logger($user->data()->id, 'USPlugins', $plugin_name . ' activated');
} else {
  err($plugin_name . ' activation failed');
  logger($user->data()->id, 'USPlugins', 'Activation failed: ' . $db->errorString());
}
