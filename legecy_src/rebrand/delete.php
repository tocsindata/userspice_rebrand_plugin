<?php
// file : public_html/usersc/plugins/rebrand/delete.php
require_once("init.php");
 
if (in_array($user->data()->id, $master_account)){
$db = DB::getInstance();
include "plugin_info.php";
 
    $db->query("DROP TABLE IF EXISTS plg_tocsinrebrand_settings");
    $db->query("DROP TABLE IF EXISTS plg_tocsinrebrand_icons");
    $db->query("DROP TABLE IF EXISTS plg_tocsinrebrand"); 
    $db->query("DROP TABLE IF EXISTS plg_tocsinrebrand;");


} //do not perform actions outside of this statement 
