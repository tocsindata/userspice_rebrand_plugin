<?php
/**
 * UserSpice ReBrand Plugin — Uninstaller
 *
 * Actions (POST only, CSRF-protected):
 *  - disable: Soft uninstall (turn off runtime integration) but keep all data/backups/assets.
 *  - purge:   Attempt to restore usersc/includes/head_tags.php from last backup (or strip markers),
 *             then drop plugin tables and remove plugin-created asset directories.
 *
 * Rules:
 *  - Use $abs_us_root / $us_url_root as-is for real paths and URLs.
 *  - DB access ONLY via DB::getInstance() (no $db params).
 *  - Only User ID 1 may run actions.
 *  - No header/footer includes; Plugin Manager supplies chrome.
 *  - Clean error handling (no white screens).
 */

///////////////////////////////////////////////////////////////
// Bootstrap UserSpice using real paths
///////////////////////////////////////////////////////////////
if (!isset($abs_us_root) || !isset($us_url_root)) {
  $us_root_guess = realpath(__DIR__ . '/../../..'); // usersc/plugins/rebrand -> usersc
  $init = $us_root_guess . '/users/init.php';
  if (!file_exists($init)) {
    echo '<div class="alert alert-danger">ReBrand uninstaller error: users/init.php not found.</div>';
    return;
  }
  require_once $init;
}

global $user, $abs_us_root, $us_url_root;

///////////////////////////////////////////////////////////////
// Guard: Only logged-in User ID 1
///////////////////////////////////////////////////////////////
if (!isset($user) || !$user->isLoggedIn()) {
  echo '<div class="alert alert-danger">ReBrand uninstaller: You must be logged in as User ID 1.</div>';
  return;
}
$userId = (int)($user->data()->id ?? 0);
if ($userId !== 1) {
  echo '<div class="alert alert-danger">ReBrand uninstaller: Only User ID 1 may perform uninstall actions.</div>';
  return;
}

///////////////////////////////////////////////////////////////
// DB handle (UserSpice way)
///////////////////////////////////////////////////////////////
try {
  $db = DB::getInstance();
} catch (Exception $e) {
  echo '<div class="alert alert-danger">ReBrand uninstaller DB error: '
     . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
  return;
}

///////////////////////////////////////////////////////////////
// Paths (filesystem) and tables
// NOTE: Per project rule, build paths from $abs_us_root.$us_url_root.'...'
///////////////////////////////////////////////////////////////
$fsRootBase  = rtrim($abs_us_root, '/\\') . rtrim($us_url_root, '/'); // filesystem base corresponding to URL root
$userscPath  = $fsRootBase . '/usersc';
$imagesPath  = $fsRootBase . '/users/images';
$rebrandPath = $imagesPath . '/rebrand';
$iconsPath   = $rebrandPath . '/icons';
$headTags    = $userscPath . '/includes/head_tags.php';

$tableSettings    = 'us_rebrand_settings';
$tableMenuBackups = 'us_rebrand_menu_backups';
$tableFileBackups = 'us_rebrand_file_backups';
$tableSiteBackups = 'us_rebrand_site_backups';

///////////////////////////////////////////////////////////////
// CSRF helper
///////////////////////////////////////////////////////////////
function rebrand_csrf_is_valid(): bool {
  if (class_exists('Token') && method_exists('Token', 'check')) {
    $token = $_POST['csrf'] ?? '';
    return Token::check($token);
  }
  return false;
}

///////////////////////////////////////////////////////////////
// Atomic file write helper
///////////////////////////////////////////////////////////////
function rebrand_atomic_write(string $path, string $content): bool {
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

///////////////////////////////////////////////////////////////
// Restore head_tags.php from latest backup (if available)
// (DB fetched internally; do NOT pass $db)
///////////////////////////////////////////////////////////////
function rebrand_restore_head_from_backup(string $tableFileBackups, string $headTagsPath): bool {
  try {
    $db = DB::getInstance();
    $row = $db->query(
      "SELECT `content_backup` FROM `{$tableFileBackups}` WHERE `path` = ? ORDER BY `id` DESC LIMIT 1",
      [$headTagsPath]
    )->first();
    if ($row && isset($row->content_backup)) {
      rebrand_atomic_write($headTagsPath, (string)$row->content_backup);
      return true;
    }
  } catch (Exception $e) {
    // swallow; caller handles messaging
  }
  return false;
}

///////////////////////////////////////////////////////////////
// Remove ONLY our injected block from head_tags.php if no backup
///////////////////////////////////////////////////////////////
function rebrand_strip_markers_from_head(string $headTagsPath): bool {
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

///////////////////////////////////////////////////////////////
// Recursively delete a directory (defensive)
///////////////////////////////////////////////////////////////
function rebrand_rrmdir(string $dir): void {
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

///////////////////////////////////////////////////////////////
// Handle actions
///////////////////////////////////////////////////////////////
$action   = $_POST['action'] ?? '';
$messages = [];
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!rebrand_csrf_is_valid()) {
    $errors[] = 'Invalid CSRF token. Please try again.';
  } else {
    if ($action === 'disable') {
      // Soft uninstall: disable features but keep data intact
      try {
        $exists = $db->query("SELECT `id` FROM `{$tableSettings}` WHERE `id` = 1 LIMIT 1")->first();
        if ($exists) {
          $db->update($tableSettings, 1, ['header_override_enabled' => 0]);
        }
        $messages[] = 'ReBrand disabled. Data and backups were kept.';
      } catch (Exception $e) {
        $errors[] = 'Failed to disable plugin state: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
      }
    } elseif ($action === 'purge') {
      // Destructive cleanup with best-effort restore
      try {
        // 1) Attempt to restore head_tags.php from backup; else strip our block
        $restored = rebrand_restore_head_from_backup($tableFileBackups, $headTags);
        if (!$restored) {
          rebrand_strip_markers_from_head($headTags);
        }

        // 2) Drop plugin tables (ignore errors if they don't exist)
        try { $db->query("DROP TABLE IF EXISTS `{$tableMenuBackups}`"); } catch (Exception $e) {}
        try { $db->query("DROP TABLE IF EXISTS `{$tableFileBackups}`"); } catch (Exception $e) {}
        try { $db->query("DROP TABLE IF EXISTS `{$tableSiteBackups}`"); } catch (Exception $e) {}
        try { $db->query("DROP TABLE IF EXISTS `{$tableSettings}`");    } catch (Exception $e) {}

        // 3) Delete asset directories
        rebrand_rrmdir($iconsPath);
        rebrand_rrmdir($rebrandPath);

        $messages[] = 'ReBrand purged: attempted restore/cleanup of head tags, tables dropped, and asset directories removed.';
      } catch (Exception $e) {
        $errors[] = 'Failed to purge plugin data: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
      }
    } else {
      $errors[] = 'Unknown action.';
    }
  }
}

///////////////////////////////////////////////////////////////
// Minimal UI for Plugin Manager context (no headers/footers)
///////////////////////////////////////////////////////////////
?>
<div class="card">
  <div class="card-header"><strong>ReBrand — Uninstall</strong></div>
  <div class="card-body">
    <?php if (!empty($messages)) : ?>
      <div class="alert alert-success">
        <?php foreach ($messages as $m): ?>
          <div><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($errors)) : ?>
      <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?>
          <div><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
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

    <p class="mt-3">
      Return to Plugin Manager:
      <a href="<?= $us_url_root ?>users/admin.php?view=plugins_config&plugin=rebrand">
        <?= $us_url_root ?>users/admin.php?view=plugins_config&plugin=rebrand
      </a>
    </p>
  </div>
</div>
