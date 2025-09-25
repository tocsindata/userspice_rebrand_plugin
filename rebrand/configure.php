<?php
// usersc/plugins/rebrand/configure.php
// No header/footer includes here — the Plugin Manager provides the chrome.

// Load UserSpice if not already loaded (keeps plugin usable both inside and outside the manager)
if (!class_exists('DB')) {
  // From usersc/plugins/rebrand/ → ../../users/init.php
  $init = realpath(__DIR__ . '/../../users/init.php');
  if ($init && file_exists($init)) {
    require_once $init;
    $db = DB::getInstance();
  } else {
    die('ReBrand plugin: unable to locate users/init.php');
  }
}

// Access control: only user ID 1
global $user, $abs_us_root, $us_url_root;
if (!isset($user) || !$user->isLoggedIn() || (int)$user->data()->id !== 1) {
  Redirect::to($us_url_root . 'users/admin.php');
  exit;
}

// Optional: quick settings read (safe if schema changes)
$siteSettings = [];
try {
  $qry = $db->query("SELECT * FROM settings");
  foreach ($qry->results() as $row) {
    $siteSettings[(int)$row->id] = [
      'site_name'     => (string)$row->site_name,
      'site_url'      => (string)$row->site_url,
      'copyright'     => (string)$row->copyright,
      'contact_email' => (string)($row->contact_email ?? ''),
    ];
  }
} catch (Exception $e) {
  // non-fatal
}

// Provide a simple page title if needed by included UIs
$title = 'ReBrand';

// Panel router
$panel = isset($_GET['panel']) ? strtolower((string)$_GET['panel']) : 'settings';
$base  = $us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand';

// Lightweight tabs (Bootstrap-friendly; manager already loads BS in admin)
?>
<div class="mb-3">
  <ul class="nav nav-pills">
    <li class="nav-item">
      <a class="nav-link <?= $panel === 'settings' ? 'active' : '' ?>" href="<?=$base?>">Settings</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $panel === 'menus' ? 'active' : '' ?>" href="<?=$base?>&panel=menus">Menus</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $panel === 'backups' ? 'active' : '' ?>" href="<?=$base?>&panel=backups">Backups</a>
    </li>
  </ul>
</div>
<?php
// Include the requested admin panel
$root = __DIR__ . '/admin/';
switch ($panel) {
  case 'menus':
    require $root . 'menus.php';
    break;
  case 'backups':
    require $root . 'backups.php';
    break;
  case 'settings':
  default:
    require $root . 'settings.php';
    break;
}
