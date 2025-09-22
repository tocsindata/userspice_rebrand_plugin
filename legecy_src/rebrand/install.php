<?php
// file : public_html/usersc/plugins/rebrand/install.php
require_once "init.php";
if (!in_array($user->data()->id, $master_account)) {
  die("You do not have permission to run this script.");
}

include "plugin_info.php";

$db = DB::getInstance();

// Always create required plugin tables, even if plugin is already installed

// Main branding table
$db->query("CREATE TABLE IF NOT EXISTS plg_tocsinrebrand (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  domain VARCHAR(255) NOT NULL,
  path VARCHAR(255) NOT NULL,
  alt_text VARCHAR(255),
  width VARCHAR(10),
  height VARCHAR(10),
  icon_placement VARCHAR(50),
  social_links TEXT,
  logo_css TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if ($db->error()) echo "Error creating plg_tocsinrebrand: " . $db->errorString() . "<br>";

// FontAwesome icons table
$db->query("CREATE TABLE IF NOT EXISTS plg_tocsinrebrand_icons (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  url TEXT,
  fa_class VARCHAR(255),
  label VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if ($db->error()) echo "Error creating plg_tocsinrebrand_icons: " . $db->errorString() . "<br>";

// Settings table
$db->query("CREATE TABLE IF NOT EXISTS plg_tocsinrebrand_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  alt_text VARCHAR(255),
  icon_position VARCHAR(20) DEFAULT 'none',
  icon_size VARCHAR(20),
  icon_color VARCHAR(20),
  menu_id INT UNSIGNED DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if ($db->error()) echo "Error creating plg_tocsinrebrand_settings: " . $db->errorString() . "<br>";

// Register plugin (only if not already present)
$pluginCheck = $db->query("SELECT * FROM us_plugins WHERE plugin = ?", [$plugin_name])->count();
if ($pluginCheck == 0) {
  $fields = [
    'plugin' => $plugin_name,
    'status' => 'installed',
  ];
  $db->insert('us_plugins', $fields);
  if (!$db->error()) {
    err($plugin_name . ' installed');
    logger($user->data()->id, "USPlugins", $plugin_name . " installed");
  } else {
    err($plugin_name . ' was not installed');
    logger($user->data()->id, "USPlugins", "Failed to install plugin, Error: " . $db->errorString());
  }
} else {
  echo "Plugin already registered in us_plugins.<br>";
}

// Register hooks (if needed, currently empty array)
$hooks = [];
registerHooks($hooks, $plugin_name);
