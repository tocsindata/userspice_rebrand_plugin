<?php
// usersc/plugins/rebrand/deactivate.php

if (!in_array($user->data()->id, $master_account)) { return; }

require_once __DIR__ . '/plugin_info.php';

$db->query("UPDATE us_plugins
            SET status = 'installed', last_check = ?
            WHERE plugin = ?",
           [date('Y-m-d H:i:s'), $plugin_name]);

if (!$db->error()) {
  err($plugin_name . ' deactivated');
  logger($user->data()->id, 'USPlugins', $plugin_name . ' deactivated');
} else {
  err($plugin_name . ' deactivation failed');
  logger($user->data()->id, 'USPlugins', 'Deactivation failed: ' . $db->errorString());
}
