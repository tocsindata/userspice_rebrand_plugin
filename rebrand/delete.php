<?php
require_once("init.php");
 
if (in_array($user->data()->id, $master_account)){
$db = DB::getInstance();
include "plugin_info.php"; 

} //do not perform actions outside of this statement