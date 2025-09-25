<?php
// usersc/plugins/rebrand/configure.php

// ⚠️ No header/footer includes here. The Plugin Manager provides the chrome.

// Load UserSpice if not already loaded (keeps plugin usable both inside and outside the manager)
if (!class_exists('DB')) {
  // From usersc/plugins/rebrand/ → ../../users/init.php
  $init = realpath(__DIR__ . '/../../users/init.php');
  if ($init && file_exists($init)) {
    require_once $init;
    // include the db class the userspice way
    $db = DB::getInstance();
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
 
// For clean navigation back to the Plugin Manager
$returnUrl = $us_url_root . 'users/admin.php?view=plugins_config&plugin=rebrand';


  // Do any lightweight reads via $db as needed (example: settings header/title)
  // Keeping it optional and safe: if table/row structure changes, UI still loads.
  $siteSettings = array();
  $cont = 0 ;
  $sql = "SELECT * FROM settings";
    $db->query( $sql); 
    $results = $db->results();
    foreach($results as $row){
      $siteSettings[$row->id]['site_name'] = $row->site_name;
      $siteSettings[$row->id]['copyright'] = $row->copyright;
      $siteSettings[$row->id]['site_url'] = $row->site_url;
      $cont++;
    }
  

  // Provide a simple page title variable if your admin/settings.php expects/uses it.
  $title = 'ReBrand Settings';

  // Render ONLY the plugin UI content; no header/footer here.
  require __DIR__ . '/admin/settings.php';
