<?php
/**
 * UserSpice ReBrand Plugin — Uninstaller
 *
 * Provides two actions:
 * 1) Disable & keep data (default): sets flags so nothing is injected; leaves DB/file backups intact.
 * 2) Purge everything (optional): attempts to restore head_tags.php from last backup, then drops plugin tables
 *    and deletes plugin-created asset directories.
 *
 * NOTE: Actions require User ID 1 and CSRF token (via Form Builder).
 */

if (!defined('ABS_US_ROOT') && !defined('US_URL_ROOT') && !isset($abs_us_root)) {
  $root = realpath(__DIR__ . '/../../..'); // usersc/plugins/rebrand -> usersc
  $init = $root . '/users/init.php';
  if (file_exists($init)) {
    require_once $init;
  }
}

// Expect UserSpice globals
if (!isset($db)) {
  die('ReBrand uninstaller: UserSpice DB context not available.');
}

$userId = $user->data()->id ?? null;
if ((int)$userId !== 1) {
  die('ReBrand uninstaller: Only User ID 1 may perform uninstall actions.');
}

// Paths & tables
$usRoot        = isset($abs_us_root) ? rtrim($abs_us_root, '/\\') . '/' : rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/';
$usersc        = $usRoot . 'usersc/';
$imagesDir     = $usRoot . 'users/images/';
$rebrandDir    = $imagesDir . 'rebrand/';
$iconsDir      = $rebrandDir . 'icons/';
$headTagsPath  = $usersc . 'includes/head_tags.php';

$tableSettings     = 'us_rebrand_settings';
$tableMenuBackups  = 'us_rebrand_menu_backups';
$tableFileBackups  = 'us_rebrand_file_backups';

// CSRF helper (Form Builder plugin expected)
function rebrand_csrf_is_valid() {
  // Form Builder typically exposes Token::check or similar; fall back to a conservative deny if missing.
  if (class_exists('Token') && method_exists('Token', 'check')) {
    $token = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    return Token::check($token);
  }
  return false;
}

// Atomic write helper
function rebrand_atomic_write($path, $content) {
  $dir = dirname($path);
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
      throw new Exception("Failed to create directory: {$dir}");
    }
  }
  $tmp = tempnam($dir, '.rebrand.tmp.');
  if ($tmp === false) {
    throw new Exception("Failed to create temp file in {$dir}");
  }
  $bytes = file_put_contents($tmp, $content);
  if ($bytes === false) {
    @unlink($tmp);
    throw new Exception("Failed to write to temp file: {$tmp}");
  }
  if (!@rename($tmp, $path)) {
    @unlink($tmp);
    throw new Exception("Failed to move temp file into place: {$path}");
  }
  return true;
}

// Restore head_tags.php from latest backup (if available)
function rebrand_restore_head_from_backup($db, $tableFileBackups, $headTagsPath) {
  try {
    $row = $db->query("SELECT * FROM `{$tableFileBackups}` WHERE `path` = ? ORDER BY `id` DESC LIMIT 1", [$headTagsPath])->first();
    if ($row && isset($row->content_backup)) {
      rebrand_atomic_write($headTagsPath, $row->content_backup);
      return true;
    }
  } catch (Exception $e) {
    // ignore; caller can decide what to do
  }
  return false;
}

// Remove ONLY our injected block from head_tags.php if no backup exists
function rebrand_strip_markers_from_head($headTagsPath) {
  if (!file_exists($headTagsPath)) return false;
  $contents = file_get_contents($headTagsPath);
  if ($contents === false) return false;

  $pattern = '/<!--\s*ReBrand START\s*-->.*?<!--\s*ReBrand END\s*-->/is';
  $stripped = preg_replace($pattern, "<!-- ReBrand START -->\n<!-- ReBrand END -->", $contents);
  if ($stripped === null) return false;

  if ($stripped !== $contents) {
    rebrand_atomic_write($headTagsPath, $stripped);
    return true;
  }
  return false;
}

// Recursively delete a directory (defensive)
function rebrand_rrmdir($dir) {
  if (!is_dir($dir)) return;
  $items = scandir($dir);
  if ($items === false) return;
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $dir . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path)) {
      rebrand_rrmdir($path);
    } else {
      @unlink($path);
    }
  }
  @rmdir($dir);
}

// Handle actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!rebrand_csrf_is_valid()) {
    $errors[] = 'Invalid CSRF token. Please try again.';
  } else {
    if ($action === 'disable') {
      // Soft uninstall: disable features but keep data intact
      try {
        // Flip header override off to ensure we no longer patch on runtime
        $exists = $db->query("SELECT id FROM `{$tableSettings}` WHERE id = 1")->first();
        if ($exists) {
          $db->update($tableSettings, 1, ['header_override_enabled' => 0]);
        }
        $messages[] = 'ReBrand disabled. Data and backups were kept.';
      } catch (Exception $e) {
        $errors[] = 'Failed to disable plugin state: ' . htmlspecialchars($e->getMessage());
      }
    } elseif ($action === 'purge') {
      // Destructive cleanup with best-effort restore
      try {
        // 1) Attempt to restore head_tags.php from backup; else strip our block
        $restored = rebrand_restore_head_from_backup($db, $tableFileBackups, $headTagsPath);
        if (!$restored) {
          rebrand_strip_markers_from_head($headTagsPath);
        }

        // 2) Drop plugin tables (ignoring errors if they don't exist)
        $db->query("DROP TABLE IF EXISTS `{$tableMenuBackups}`");
        $db->query("DROP TABLE IF EXISTS `{$tableFileBackups}`");
        $db->query("DROP TABLE IF EXISTS `{$tableSettings}`");

        // 3) Delete asset directories
        rebrand_rrmdir($iconsDir);
        rebrand_rrmdir($rebrandDir);

        $messages[] = 'ReBrand purged: backups restored/cleaned, tables dropped, and asset directories removed.';
      } catch (Exception $e) {
        $errors[] = 'Failed to purge plugin data: ' . htmlspecialchars($e->getMessage());
      }
    }
  }
}

// Simple UI (rendered by plugin manager page)
?>
<div class="card">
  <div class="card-header"><strong>ReBrand Uninstall</strong></div>
  <div class="card-body">
    <?php if (!empty($messages)): ?>
      <div class="alert alert-success">
        <?php foreach ($messages as $m): ?>
          <div><?= $m ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?>
          <div><?= $e ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p>Choose how you want to remove <strong>ReBrand</strong>:</p>

    <form method="post" class="mb-3">
      <input type="hidden" name="action" value="disable">
      <?php if (class_exists('Token') && method_exists('Token', 'generate')): ?>
        <input type="hidden" name="csrf" value="<?= Token::generate(); ?>">
      <?php endif; ?>
      <button type="submit" class="btn btn-warning">
        Disable &amp; Keep Data
      </button>
      <small class="text-muted d-block mt-1">
        Turns off header/menu integration but leaves settings, backups, and assets on disk.
      </small>
    </form>

    <form method="post" onsubmit="return confirm('This will drop plugin tables and remove generated files. Continue?');">
      <input type="hidden" name="action" value="purge">
      <?php if (class_exists('Token') && method_exists('Token', 'generate')): ?>
        <input type="hidden" name="csrf" value="<?= Token::generate(); ?>">
      <?php endif; ?>
      <button type="submit" class="btn btn-danger">
        Purge Everything (Restore &amp; Drop)
      </button>
      <small class="text-muted d-block mt-1">
        Attempts to restore <code>usersc/includes/head_tags.php</code> from the last backup, then drops plugin tables and deletes <code>users/images/rebrand/</code>.
      </small>
    </form>
  </div>
</div>
