<?php
/**
 * ReBrand — uninstall.php
 *
 * Behavior:
 *  - Default: Disable & keep data (no destructive actions).
 *  - Optional Purge (when posted with purge=1): 
 *      1) Attempt to restore users/includes/head_tags.php from latest backup.
 *      2) Drop plugin backup tables.
 *      3) Delete plugin-created asset directories/files.
 *
 * Security: User ID 1 only. POST actions require CSRF token.
 *
 * This file may be included by the Plugin Manager during uninstall/deactivate.
 */

if (!isset($user) || (int)($user->data()->id ?? 0) !== 1) {
  die('Admin only.');
}

// Flash helpers
if (!function_exists('usSuccess')) {
  function usSuccess($msg){ $_SESSION['msg'][] = ['type'=>'success','msg'=>$msg]; }
}
if (!function_exists('usError')) {
  function usError($msg){ $_SESSION['msg'][] = ['type'=>'danger','msg'=>$msg]; }
}
if (!function_exists('usInfo')) {
  function usInfo($msg){ $_SESSION['msg'][] = ['type'=>'info','msg'=>$msg]; }
}

$db = DB::getInstance();

// Paths
$iconsDirFs       = rtrim($abs_us_root.$us_url_root, '/').'/users/images/rebrand/icons';
$pluginStorageDir = __DIR__.'/storage';
$versionDir       = $pluginStorageDir.'/versions';
$versionFile      = $versionDir.'/asset_version.json';
$headFile         = rtrim($abs_us_root.$us_url_root, '/').'/users/includes/head_tags.php';

// Utilities
function rb_atomic_write($path, $content) {
  $tmp = $path.'.tmp';
  if (@file_put_contents($tmp, $content, LOCK_EX) === false) return false;
  if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
  @chmod($path, 0644);
  return true;
}
function rb_rrmdir($dir) {
  if (!is_dir($dir)) return;
  $items = scandir($dir);
  if ($items === false) return;
  foreach ($items as $item) {
    if ($item === '.' || $item === '-') continue;
    if ($item === '..') continue;
    $path = $dir.DIRECTORY_SEPARATOR.$item;
    if (is_dir($path)) {
      rb_rrmdir($path);
    } else {
      @unlink($path);
    }
  }
  @rmdir($dir);
}
function rb_restore_head_from_backup($db, $target) {
  try {
    $bak = $db->query("SELECT * FROM us_rebrand_file_backups WHERE file_path LIKE ? ORDER BY took_at DESC, id DESC LIMIT 1", [$target])->first();
    if (!$bak) {
      // try a LIKE match if exact path not stored identically
      $like = '%/users/includes/head_tags.php';
      $bak = $db->query("SELECT * FROM us_rebrand_file_backups WHERE file_path LIKE ? ORDER BY took_at DESC, id DESC LIMIT 1", [$like])->first();
    }
    if ($bak && isset($bak->content_backup)) {
      @mkdir(dirname($target), 0755, true);
      $content = is_resource($bak->content_backup) ? stream_get_contents($bak->content_backup) : $bak->content_backup;
      if ($content === false) $content = '';
      $content = preg_replace("/\r\n?/", "\n", $content);
      if (!rb_atomic_write($target, $content)) {
        usError('Failed to restore head_tags.php from backup (write error).');
        return false;
      }
      usSuccess('Restored head_tags.php from latest backup.');
      return true;
    } else {
      usInfo('No head_tags.php backup found to restore.');
      return false;
    }
  } catch (Exception $e) {
    usError('Error restoring head_tags.php: '.$e->getMessage());
    return false;
  }
}

// If not a POST, just inform what will happen on purge; Plugin Manager may ignore output.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  usInfo('ReBrand: Uninstall initialized. By default, data is preserved. Submit with purge=1 to drop tables and delete files.');
  // Do not redirect; the Plugin Manager controls flow.
  return;
}

// POSTed — verify CSRF and decide action
if (!Token::check($_POST['csrf'] ?? '')) {
  die('CSRF token invalid.');
}

$purge = isset($_POST['purge']) && (int)$_POST['purge'] === 1;
$restoreHead = isset($_POST['restore_head']) && (int)$_POST['restore_head'] === 1;

if (!$purge) {
  // Disable-only path
  usSuccess('ReBrand: plugin disabled. All data and backups preserved.');
  // No redirects; Plugin Manager flow continues.
  return;
}

// P U R G E  mode
$hadError = false;

// 1) Restore head_tags.php if requested
if ($restoreHead) {
  $ok = rb_restore_head_from_backup($db, $headFile);
  if (!$ok) {
    // Not fatal — continue purge
  }
}

// 2) Drop plugin tables
try {
  $db->query("DROP TABLE IF EXISTS `us_rebrand_file_backups`");
  $db->query("DROP TABLE IF EXISTS `us_rebrand_menu_backups`");
  $db->query("DROP TABLE IF EXISTS `us_rebrand_site_backups`");
  usSuccess('ReBrand: Dropped plugin backup tables.');
} catch (Exception $e) {
  usError('ReBrand: Failed to drop tables — '.$e->getMessage());
  $hadError = true;
}

// 3) Delete plugin-created files/directories
//    a) icons directory
if (is_dir($iconsDirFs)) {
  rb_rrmdir($iconsDirFs);
  if (!is_dir($iconsDirFs)) {
    usSuccess('ReBrand: Removed icons directory.');
  } else {
    usError('ReBrand: Failed to remove icons directory.');
    $hadError = true;
  }
}
//    b) plugin storage (versions, etc.)
if (is_dir($pluginStorageDir)) {
  rb_rrmdir($pluginStorageDir);
  if (!is_dir($pluginStorageDir)) {
    usSuccess('ReBrand: Removed plugin storage directory.');
  } else {
    usError('ReBrand: Failed to remove plugin storage directory.');
    $hadError = true;
  }
}

// 4) Optionally remove head_tags.php if no restore requested and the file looks auto-generated
if (!$restoreHead && is_file($headFile)) {
  $body = @file_get_contents($headFile);
  if (is_string($body) && strpos($body, 'Auto-generated by ReBrand plugin') !== false) {
    if (@unlink($headFile)) {
      usSuccess('ReBrand: Removed auto-generated head_tags.php.');
    } else {
      usError('ReBrand: Could not remove head_tags.php.');
      $hadError = true;
    }
  }
}

// 5) Final messaging
if ($hadError) {
  usError('ReBrand purge completed with some errors. Review messages above.');
} else {
  usSuccess('ReBrand purge completed successfully.');
}

// No redirect — Plugin Manager controls the flow.
/*
 * If you want to drive this from a form, post to:
 *   users/admin.php?view=plugins_config&plugin=rebrand&action=uninstall
 * with fields:
 *   <input type="hidden" name="csrf" value="<?=Token::generate()?>">
 *   <input type="hidden" name="purge" value="1">
 *   <input type="hidden" name="restore_head" value="1">  // optional
 */
