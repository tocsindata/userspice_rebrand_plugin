<?php
/**
 * ReBrand — admin/process.php
 * Invoked by users/admin.php?view=plugins_config&plugin=rebrand&do=...
 *
 * Routes:
 *   - do=save_settings
 *   - do=upload_assets
 *   - do=patch_head
 *   - do=restore_file_backup
 *   - do=restore_site_backup
 *   - do=restore_menu_backup
 *   - do=menu_apply
 *
 * Security:
 *   - Admin only (User ID 1)
 *   - POST + CSRF required for all actions
 */

use Rebrand\BackupService;
use Rebrand\MenuPatcher;

if (!isset($user) || (int)($user->data()->id ?? 0) !== 1) {
  die('Admin only.');
}

if (!isset($_GET['do'])) {
  die('No action.');
}

// Flash helpers (guarded)
if (!function_exists('usSuccess')) {
  function usSuccess($msg){ $_SESSION['msg'][] = ['type'=>'success','msg'=>$msg]; }
}
if (!function_exists('usError')) {
  function usError($msg){ $_SESSION['msg'][] = ['type'=>'danger','msg'=>$msg]; }
}

// Common paths
$iconsDirFs = rtrim($abs_us_root.$us_url_root, '/').'/users/images/rebrand/icons';
@mkdir($iconsDirFs, 0755, true);

$versionFile = __DIR__.'/../storage/versions/asset_version.json';
if (!is_dir(dirname($versionFile))) {
  @mkdir(dirname($versionFile), 0755, true);
}

// Version helpers (guarded)
if (!function_exists('rb_get_asset_version')) {
  function rb_get_asset_version($versionFile) {
    $v = 1;
    if (is_file($versionFile)) {
      $raw = @file_get_contents($versionFile);
      $dec = json_decode($raw ?: '1', true);
      if (is_int($dec)) $v = $dec;
    }
    return max(1, (int)$v);
  }
}
if (!function_exists('rb_bump_asset_version')) {
  function rb_bump_asset_version($versionFile) {
    $next = rb_get_asset_version($versionFile) + 1;
    @file_put_contents($versionFile, json_encode($next, JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $next;
  }
}

// Backups (guarded)
if (!function_exists('rb_backup_file')) {
  function rb_backup_file($path, $note = 'rebrand backup') {
    if (!is_file($path)) return;
    $db = DB::getInstance();
    $content = @file_get_contents($path);
    try {
      $db->insert('us_rebrand_file_backups', [
        'took_at'        => date('Y-m-d H:i:s'),
        'user_id'        => (int)($GLOBALS['user']->data()->id ?? 0),
        'file_path'      => $path,
        'content_backup' => $content,
        'notes'          => $note,
      ]);
    } catch (Exception $e) {
      usError('Backup failed for '.basename($path).': '.$e->getMessage());
    }
  }
}
if (!function_exists('rb_backup_settings')) {
  function rb_backup_settings($snapshot, $note = 'settings snapshot') {
    $db = DB::getInstance();
    try {
      $db->insert('us_rebrand_site_backups', [
        'took_at'       => date('Y-m-d H:i:s'),
        'user_id'       => (int)($GLOBALS['user']->data()->id ?? 0),
        'site_name'     => (string)($snapshot['site_name']     ?? ''),
        'site_url'      => (string)($snapshot['site_url']      ?? ''),
        'copyright'     => (string)($snapshot['copyright']     ?? ''),
        'contact_email' => (string)($snapshot['contact_email'] ?? ''),
        'notes'         => $note,
      ]);
    } catch (Exception $e) {
      usError('Settings backup failed: '.$e->getMessage());
    }
  }
}

$do = $_GET['do'];

// ===================================================
// 1) Save Settings / Revert / Export
// ===================================================
if ($do === 'save_settings') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { die('Invalid request.'); }
  if (!Token::check($_POST['csrf'] ?? '')) { die('CSRF token invalid.'); }

  $db = DB::getInstance();

  // Fetch current snapshot
  $current = [
    'site_name'     => '',
    'site_url'      => '',
    'copyright'     => '',
    'contact_email' => '',
  ];
  try {
    $row = $db->query("SELECT * FROM settings LIMIT 1")->first();
    if ($row) {
      $current = [
        'site_name'     => (string)($row->site_name ?? ''),
        'site_url'      => (string)($row->site_url ?? ''),
        'copyright'     => (string)($row->copyright ?? ''),
        'contact_email' => (string)($row->contact_email ?? ''),
      ];
    }
  } catch (Exception $e) { /* continue */ }

  // Revert from latest backup
  if (isset($_POST['revert']) && $_POST['revert'] == '1') {
    try {
      $bak = $db->query("SELECT * FROM us_rebrand_site_backups ORDER BY took_at DESC, id DESC LIMIT 1")->first();
      if ($bak) {
        $db->update('settings', 1, [
          'site_name'     => (string)$bak->site_name,
          'site_url'      => (string)$bak->site_url,
          'copyright'     => (string)$bak->copyright,
          'contact_email' => (string)$bak->contact_email,
        ]);
        usSuccess('Settings restored from last backup.');
      } else {
        usError('No settings backup found to restore.');
      }
    } catch (Exception $e) {
      usError('Failed to restore settings: '.$e->getMessage());
    }
    Redirect::to($us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand'); exit;
  }

  // Export JSON
  if (isset($_POST['export']) && $_POST['export'] == '1') {
    $json = json_encode($current, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="rebrand_settings_export.json"');
    header('Content-Length: '.strlen($json));
    echo $json;
    exit;
  }

  // Save: backup then update
  rb_backup_settings($current, 'pre-update');

  $site_name     = trim((string)($_POST['site_name'] ?? ''));
  $site_url      = trim((string)($_POST['site_url'] ?? ''));
  $copyright     = trim((string)($_POST['copyright'] ?? ''));
  $contact_email = trim((string)($_POST['contact_email'] ?? ''));

  if ($site_name === '') { usError('Site Name cannot be empty.'); }
  if ($site_url !== '' && !preg_match('#^https?://#i', $site_url)) {
    usError('Site URL should start with http:// or https://');
  }
  if ($contact_email !== '' && !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
    usError('Contact Email is not a valid email address.');
  }

  try {
    $db->update('settings', 1, [
      'site_name'     => $site_name,
      'site_url'      => $site_url,
      'copyright'     => $copyright,
      'contact_email' => $contact_email,
    ]);
    usSuccess('Settings saved.');
  } catch (Exception $e) {
    usError('Failed to save settings: '.$e->getMessage());
  }

  Redirect::to($us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand'); exit;
}

// ===================================================
// 2) Upload Brand Assets (manual)
// ===================================================
if ($do === 'upload_assets') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { die('Invalid request.'); }
  if (!Token::check($_POST['csrf'] ?? '')) { die('CSRF token invalid.'); }

  $db = DB::getInstance();
  $changed = false;

  $map = [
    'favicon_ico'  => fn($f) => [$GLOBALS['iconsDirFs'].'/favicon.ico', 'favicon.ico'],
    'logo'         => function($f){
      $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
      if ($ext === 'svg') return [$GLOBALS['iconsDirFs'].'/logo.svg', 'logo.svg'];
      return [$GLOBALS['iconsDirFs'].'/logo.png', 'logo.png'];
    },
    'apple_touch'   => fn($f) => [$GLOBALS['iconsDirFs'].'/apple-touch-icon.png', 'apple-touch-icon.png'],
    'android_192'   => fn($f) => [$GLOBALS['iconsDirFs'].'/android-chrome-192x192.png', 'android-chrome-192x192.png'],
    'android_512'   => fn($f) => [$GLOBALS['iconsDirFs'].'/android-chrome-512x512.png', 'android-chrome-512x512.png'],
    'maskable_512'  => fn($f) => [$GLOBALS['iconsDirFs'].'/maskable-512x512.png', 'maskable-512x512.png'],
    'favicon_16'    => fn($f) => [$GLOBALS['iconsDirFs'].'/favicon-16x16.png', 'favicon-16x16.png'],
    'favicon_32'    => fn($f) => [$GLOBALS['iconsDirFs'].'/favicon-32x32.png', 'favicon-32x32.png'],
    'og_image'      => fn($f) => [$GLOBALS['iconsDirFs'].'/og-image.png', 'og-image.png'],
    'safari_pinned' => fn($f) => [$GLOBALS['iconsDirFs'].'/safari-pinned-tab.svg', 'safari-pinned-tab.svg'],
    'manifest'      => fn($f) => [$GLOBALS['iconsDirFs'].'/site.webmanifest', 'site.webmanifest'],
  ];

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $allow = [
    'ico' => ['image/vnd.microsoft.icon', 'image/x-icon', 'application/octet-stream'],
    'png' => ['image/png'],
    'jpg' => ['image/jpeg'],
    'jpeg'=> ['image/jpeg'],
    'svg' => ['image/svg+xml', 'text/plain', 'application/octet-stream'],
    'webmanifest' => ['application/manifest+json', 'application/json', 'text/plain'],
    'json' => ['application/json', 'text/plain'],
  ];

  $errors = [];
  foreach ($map as $field => $resolver) {
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;

    $f = $_FILES[$field];
    if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
      $errors[] = "$field: upload error ".$f['error'];
      continue;
    }
    $tmp = $f['tmp_name'];
    $origName = $f['name'] ?? '';
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === '' && $field === 'manifest') $ext = 'webmanifest';

    $mime = $finfo->file($tmp) ?: '';
    $ok = false;
    foreach (($allow[$ext] ?? []) as $m) {
      if (stripos($mime, $m) === 0) { $ok = true; break; }
    }
    if (!$ok) {
      $errors[] = "$field: invalid file type ($mime)";
      continue;
    }

    [$dest, $finalName] = $resolver($f);
    if (!$dest) continue;

    // Backup existing file
    if (is_file($dest)) rb_backup_file($dest, 'brand asset upload');

    // Atomic write
    $tmpDest = $dest.'.tmp';
    if (!@move_uploaded_file($tmp, $tmpDest)) { $errors[] = "$finalName: failed to move uploaded file."; continue; }
    if (in_array($ext, ['svg','webmanifest','json'])) {
      $data = @file_get_contents($tmpDest);
      if ($data !== false) {
        $data = preg_replace("/\r\n?/", "\n", $data);
        @file_put_contents($tmpDest, $data, LOCK_EX);
      }
    }
    if (!@rename($tmpDest, $dest)) { @unlink($tmpDest); $errors[] = "$finalName: failed to finalize write."; continue; }
    @chmod($dest, 0644);
    $changed = true;
  }

  if ($changed || isset($_POST['bump_version'])) {
    rb_bump_asset_version($versionFile);
    usSuccess('Cache version bumped.');
  }
  if ($changed) usSuccess('Brand assets saved.');
  foreach ($errors as $e) usError($e);

  Redirect::to($us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand'); exit;
}

// ===================================================
// 3) Patch Head (write users/includes/head_tags.php)
// ===================================================
if ($do === 'patch_head') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { die('Invalid request.'); }
  if (!Token::check($_POST['csrf'] ?? '')) { die('CSRF token invalid.'); }

  $desc    = preg_replace("/\r\n?/", "\n", (string)($_POST['meta_description'] ?? ''));
  $author  = trim((string)($_POST['meta_author'] ?? ''));
  $robots  = trim((string)($_POST['meta_robots'] ?? ''));
  $theme   = trim((string)($_POST['theme_color'] ?? ''));
  $twCard  = trim((string)($_POST['twitter_card'] ?? ''));
  $ogTitle = trim((string)($_POST['og_title'] ?? ''));
  $ogSite  = trim((string)($_POST['og_site_name'] ?? ''));
  $extraIn = preg_replace("/\r\n?/", "\n", (string)($_POST['extra_head_html'] ?? ''));
  $extra   = preg_replace('#<\s*script\b[^>]*>.*?<\s*/\s*script>#is', '', $extraIn);

  $assetVersion = rb_get_asset_version($versionFile);

  // Detect assets
  $have = [
    'favicon_ico'   => is_file($iconsDirFs.'/favicon.ico'),
    'fav16'         => is_file($iconsDirFs.'/favicon-16x16.png'),
    'fav32'         => is_file($iconsDirFs.'/favicon-32x32.png'),
    'apple_touch'   => is_file($iconsDirFs.'/apple-touch-icon.png'),
    'android_192'   => is_file($iconsDirFs.'/android-chrome-192x192.png'),
    'android_512'   => is_file($iconsDirFs.'/android-chrome-512x512.png'),
    'maskable_512'  => is_file($iconsDirFs.'/maskable-512x512.png'),
    'og_image'      => is_file($iconsDirFs.'/og-image.png'),
    'safari_pinned' => is_file($iconsDirFs.'/safari-pinned-tab.svg'),
    'manifest'      => is_file($iconsDirFs.'/site.webmanifest'),
  ];

  $e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  $lines = [];
  $lines[] = '<?php /* Auto-generated by ReBrand plugin. Edit via plugin UI. */ ?>';
  $lines[] = '<?php $asset_version = '.(int)$assetVersion.'; ?>';
  $lines[] = '';

  if ($desc !== '')   { $lines[] = '<meta name="description" content="'.$e($desc).'">'; }
  if ($author !== '') { $lines[] = '<meta name="author" content="'.$e($author).'">'; }
  if ($robots !== '') { $lines[] = '<meta name="robots" content="'.$e($robots).'">'; }
  if ($theme  !== '') { $lines[] = '<meta name="theme-color" content="'.$e($theme).'">'; }

  if ($have['fav32'])        { $lines[] = '<link rel="icon" type="image/png" sizes="32x32" href="<?=$us_url_root?>users/images/rebrand/icons/favicon-32x32.png?v=<?=$asset_version?>">'; }
  if ($have['fav16'])        { $lines[] = '<link rel="icon" type="image/png" sizes="16x16" href="<?=$us_url_root?>users/images/rebrand/icons/favicon-16x16.png?v=<?=$asset_version?>">'; }
  if ($have['favicon_ico'])  { $lines[] = '<link rel="icon" href="<?=$us_url_root?>users/images/rebrand/icons/favicon.ico?v=<?=$asset_version?>">'; }
  if ($have['apple_touch'])  { $lines[] = '<link rel="apple-touch-icon" href="<?=$us_url_root?>users/images/rebrand/icons/apple-touch-icon.png?v=<?=$asset_version?>">'; }
  if ($have['manifest'])     { $lines[] = '<link rel="manifest" href="<?=$us_url_root?>users/images/rebrand/icons/site.webmanifest?v=<?=$asset_version?>">'; }
  if ($have['safari_pinned']){ $lines[] = '<link rel="mask-icon" href="<?=$us_url_root?>users/images/rebrand/icons/safari-pinned-tab.svg?v=<?=$asset_version?>" color="#000000">'; }
  if ($have['android_192'])  { $lines[] = '<link rel="icon" type="image/png" sizes="192x192" href="<?=$us_url_root?>users/images/rebrand/icons/android-chrome-192x192.png?v=<?=$asset_version?>">'; }
  if ($have['android_512'])  { $lines[] = '<link rel="icon" type="image/png" sizes="512x512" href="<?=$us_url_root?>users/images/rebrand/icons/android-chrome-512x512.png?v=<?=$asset_version?>">'; }
  if ($have['maskable_512']) { $lines[] = '<link rel="icon" type="image/png" sizes="512x512" href="<?=$us_url_root?>users/images/rebrand/icons/maskable-512x512.png?v=<?=$asset_version?>">'; }

  if ($ogTitle !== '') { $lines[] = '<meta property="og:title" content="'.$e($ogTitle).'">'; }
  if ($ogSite  !== '') { $lines[] = '<meta property="og:site_name" content="'.$e($ogSite).'">'; }
  if ($desc    !== '') { $lines[] = '<meta property="og:description" content="'.$e($desc).'">'; }
  if ($have['og_image']) {
    $lines[] = '<meta property="og:image" content="<?=$us_url_root?>users/images/rebrand/icons/og-image.png?v=<?=$asset_version?>">';
  }
  if ($twCard !== '') {
    $lines[] = '<meta name="twitter:card" content="'.$e($twCard).'">';
  }

  if ($extra !== '') { $lines[] = $extra; }

  $content = preg_replace("/\r\n?/", "\n", implode("\n", $lines)."\n");

  $targetHead = rtrim($abs_us_root.$us_url_root, '/').'/users/includes/head_tags.php';
  @mkdir(dirname($targetHead), 0755, true);

  // Backup and write
  rb_backup_file($targetHead, 'head_tags.php patch');

  $tmp = $targetHead.'.tmp';
  if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
    usError('Failed to write temporary head_tags.php');
  } elseif (!@rename($tmp, $targetHead)) {
    @unlink($tmp);
    usError('Failed to finalize head_tags.php write');
  } else {
    @chmod($targetHead, 0644);
    usSuccess('Head tags written successfully.');
  }

  Redirect::to($us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand'); exit;
}

// ===================================================
// 4) Restore: FILE backup
// ===================================================
if ($do === 'restore_file_backup') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { die('Invalid request.'); }
  if (!Token::check($_POST['csrf'] ?? '')) { die('CSRF token invalid.'); }

  $backupId = (int)($_POST['backup_id'] ?? 0);
  if ($backupId <= 0) {
    usError('Invalid file backup id.');
    Redirect::to($us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand&panel=backups'); exit;
  }

  require_once __DIR__.'/../classes/BackupService.php';

  try {
    $svc = new BackupService();
    $svc->restoreFileByBackupId($backupId);
    usSuccess('File restored from backup.');
  } catch (Throwable $e) {
    usError('Restore failed: '.$e->getMessage());
  }

  Redirect::to($us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand&panel=backups'); exit;
}

// ===================================================
// 5) Restore: SITE SETTINGS backup
// ===================================================
if ($do === 'restore_site_backup') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { die('Invalid request.'); }
  if (!Token::check($_POST['csrf'] ?? '')) { die('CSRF token invalid.'); }

  $backupId = (int)($_POST['backup_id'] ?? 0);
  if ($backupId <= 0) {
    usError('Invalid site settings backup id.');
    Redirect::to($us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand&panel=backups'); exit;
  }

  require_once __DIR__.'/../classes/BackupService.php';

  try {
    $svc = new BackupService();
    $svc->restoreSiteFromBackupId($backupId);
    usSuccess('Site settings restored from backup.');
  } catch (Throwable $e) {
    usError('Restore failed: '.$e->getMessage());
  }

  Redirect::to($us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand&panel=backups'); exit;
}

// ===================================================
// 6) Restore: MENU backup
// ===================================================
if ($do === 'restore_menu_backup') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { die('Invalid request.'); }
  if (!Token::check($_POST['csrf'] ?? '')) { die('CSRF token invalid.'); }

  $backupId = (int)($_POST['backup_id'] ?? 0);
  if ($backupId <= 0) {
    usError('Invalid menu backup id.');
    Redirect::to($us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand&panel=backups'); exit;
  }

  require_once __DIR__.'/../classes/BackupService.php';

  try {
    $svc = new BackupService();
    $svc->restoreMenusFromBackupId($backupId);
    usSuccess('Menus restored from backup (existing rows updated).');
  } catch (Throwable $e) {
    usError('Restore failed: '.$e->getMessage());
  }

  Redirect::to($us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand&panel=backups'); exit;
}

// ===================================================
// 7) Menu Apply (writer)
// ===================================================
if ($do === 'menu_apply') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { die('Invalid request.'); }
  if (!Token::check($_POST['csrf'] ?? '')) { die('CSRF token invalid.'); }

  $raw = (string)($_POST['rules_json'] ?? '');
  $rules = json_decode($raw, true);
  if (!is_array($rules)) {
    usError('Rules JSON is invalid.');
    Redirect::to($us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand&panel=menus'); exit;
  }

  require_once __DIR__.'/../classes/MenuPatcher.php';

  try {
    $mp = new MenuPatcher();
    $result = $mp->apply($rules);
    $updated = (int)($result['updated'] ?? 0);
    $backupId = (int)($result['backup_id'] ?? 0);

    if (!empty($result['errors'])) {
      foreach ($result['errors'] as $err) usError($err);
    }
    usSuccess("Menu changes applied. Updated rows: {$updated}".($backupId ? " (backup #{$backupId})" : ''));
  } catch (Throwable $e) {
    usError('Menu apply failed: '.$e->getMessage());
  }

  Redirect::to($us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand&panel=menus'); exit;
}

// Unknown action
die('Unknown action.');
