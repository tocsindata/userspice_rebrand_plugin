<?php
// file : public_html/usersc/plugins/rebrand/uninstall.php
// For security purposes, it is MANDATORY that this page be wrapped in the following
// if statement. This prevents remote execution of this code.

include "plugin_info.php";
if (in_array($user->data()->id, $master_account) && pluginActive($plugin_name, true)) {
  // all actions should be performed here.

  $db = DB::getInstance();

  // Remove plugin from us_plugins
  $db->delete("us_plugins", ["plugin", "=", $plugin_name]);

  // Drop plugin-specific tables
    $db->query("DROP TABLE IF EXISTS plg_tocsinrebrand_settings");
    $db->query("DROP TABLE IF EXISTS plg_tocsinrebrand_icons");
    $db->query("DROP TABLE IF EXISTS plg_tocsinrebrand"); 
    $db->query("DROP TABLE IF EXISTS plg_tocsinrebrand;");
  // Unregister any hooks
  unregisterHooks($plugin_name);

  if (!$db->error()) {
    logger($user->data()->id, "USPlugins", "$plugin_name uninstalled");
    Redirect::to($us_url_root . "users/admin.php?msg=" . urlencode("$plugin_name successfully uninstalled"));
  } else {
    logger($user->data()->id, "USPlugins", "Uninstall failed for $plugin_name. Error: " . $db->errorString());
    Redirect::to($us_url_root . "users/admin.php?msg=" . urlencode("Failed to uninstall $plugin_name")); 
  }
}
