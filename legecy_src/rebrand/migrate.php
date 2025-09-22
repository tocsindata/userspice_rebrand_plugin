<?php
// file : public_html/usersc/plugins/rebrand/migrate.php
// For security purposes, it is MANDATORY that this page be wrapped in the following
// if statement. This prevents remote execution of this code.

include "plugin_info.php";
if (in_array($user->data()->id, $master_account) && pluginActive($plugin_name, true)) {
  // all actions should be performed here.

  $count = 0;
  $db = DB::getInstance();

  // Make sure the plugin is installed and get the existing updates
  $checkQ = $db->query("SELECT id,updates FROM us_plugins WHERE plugin = ?", array($plugin_name));
  $checkC = $checkQ->count();
  if ($checkC > 0) {
    $check = $checkQ->first();
    $existing = ($check->updates == '') ? [] : json_decode($check->updates);
 
    $update = '00009';
    if (!in_array($update, $existing)) {
      logger($user->data()->id, "Migrations", "$update migration triggered for $plugin_name");

      // Insert a default icon row scoped to the current domain
      $domain = defined('__SITE_DOMAIN__') ? __SITE_DOMAIN__ : $_SERVER['HTTP_HOST'];
      $db->insert('plg_tocsinrebrand_icons', [
        'url' => 'https://' . $domain,
        'fa_class' => 'fa-brands fa-twitter',
        'label' => 'twitter'
      ]);

      $existing[] = $update;
      $count++;
    }

    // Finalize
    $new = json_encode($existing);
    $db->update('us_plugins', $check->id, ['updates' => $new, 'last_check' => date("Y-m-d H:i:s")]);
    if (!$db->error()) {
      logger($user->data()->id, "Migrations", "$count migration(s) successfully triggered for $plugin_name");
    } else {
      logger($user->data()->id, "USPlugins", "Failed to save updates, Error: " . $db->errorString());
    }
  }
} // do not perform actions outside of this statement
