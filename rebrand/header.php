<?php
// file rebrand/header.php
// error reporting on
ini_set('display_errors', 1); 
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

    // second get the site name
    $site_name = '';
    if(function_exists('getSettings')){
      $settings = getSettings();
      if(isset($settings->site_name)){
        $site_name = $settings->site_name;
      }
    }
    /*
        <a href="{{root}}"><img src="{{root}}users/images/logo.png?v=4" alt="YOUR SITE NAME" class="img-fluid" /></a><div id="rebrandsocial"></div>
    */
    $version = filemtime($_SERVER['DOCUMENT_ROOT'].'/users/images/logo.png');
    $menu_string = '<a href="{{root}}"><img src="{{root}}users/images/logo.png?v='.$version.'" alt="'.$site_name.'" class="img-fluid" /></a><div id="rebrandsocial"></div>';
    $brand_html = html_encode($menu_string, ENT_QUOTES);
    // see if this brand_html is already in the table
    $sql = 'SELECT COUNT(*) as `done`  FROM `us_menus` WHERE `id` = 1 AND `brand_html` LIKE "'.$brand_html.'" ORDER BY `brand_html` ASC ';
    $db->query($sql);
    $results = $db->results();
    foreach($results as $row){
      if($row->done > 0){
        return; // already done
      }
    }
    // update the menu
    $sql = 'UPDATE `us_menus` SET `brand_html` = "'.$brand_html.'" WHERE `id` = 1 ';
    $db->query($sql);
    return ;
  }
}

if(!function_exists('rebrand_header')){
  function rebrand_header(){
    // add javascript to the header to populate the rebrand social menu ....
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

    $sql = "SELECT * FROM `us_menu_items` WHERE `menu` = ".$menu_id." AND `disabled` = 0 ORDER BY `us_menu_items`.`display_order` ASC";
    $db->query($sql);
    $results = $db->results();
    $menu_string = '';
    foreach($results as $row){
        $menu_string .= '<a href="'.$row->link.'" title="'.$row->title.'" target="'.$row->target.'"><i class="'.$row->icon.' fa-2x"></i></a> ';
        }

    echo '<script>
    document.addEventListener("DOMContentLoaded", function(){
      var rebrandDiv = document.getElementById("rebrandsocial");
      if(rebrandDiv){
        rebrandDiv.innerHTML = \''.addslashes($menu_string).'\';
      }
    });    
    </script>';
    return ;
  }
}
rebrand_social_menu();
rebrand_header() ; 