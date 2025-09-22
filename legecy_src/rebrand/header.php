<?php
// file : public_html/usersc/plugins/rebrand/header.php
if (!defined('IN_ADMIN')) {
  echo "<!-- header.php reached -->";

  global $abs_us_root, $us_url_root;

  $plugin_name = 'rebrand';
  $functions_path = $abs_us_root . $us_url_root . 'usersc/plugins/' . $plugin_name . '/functions.php';

  if (!file_exists($functions_path)) {
    echo "<!-- functions.php missing at: $functions_path -->";
    return;
  }

  require_once $functions_path;

  if (!function_exists('getRebrandData')) {
    echo "<!-- getRebrandData() not found -->";
    return;
  }

  $branding = getRebrandData();

  if (!empty($branding['logo_css'])) {
    ob_start();
    echo "<!-- branding css loaded -->\n";
    echo "<style>\n" . $branding['logo_css'] . "\n</style>\n";
    echo ob_get_clean();
  } else {
    echo "<!-- branding loaded, but logo_css empty -->";
  }

  // ✅ Only render on non-admin pages
  renderBrandingHeader();
}
?>
