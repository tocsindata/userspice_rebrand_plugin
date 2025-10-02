<?php

if(!function_exists('rebrand_social_menu')){
  function rebrand_social_menu(){
    $db = DB::getInstance(); 
    // get rebrand social menu id
    $menu_id = 0 ; // default to 0
    $sql = "SELECT `id` FROM `us_menus` WHERE `menu_name` LIKE 'rebrand_social'";
    $db->query($sql);
    $results = $db->results();
    foreach($results as $row){
      $menu_id = $row->id;
    }
    if($menu_id == 0){
      return; // just do nothing if no menu found
    }

    
  }
}