<?php
/**
 * UserSpice ReBrand Plugin — Admin Action Router
 *
 * Handles all POST/GET actions from settings.php:
 *  - bump_version
 *  - upload_logo
 *  - upload_favicon_single
 *  - generate_icons_offline
 *  - apply_head_tags / diff_head_tags / revert_head_tags
 *  - save_menu_targets / discover_menu_candidates
 *  - apply_menu_patch / diff_menu_patch / revert_menu_patch
 *  - save_social_links
 *  - site_settings_save / site_settings_revert   <-- NEW
 *
 * Security:
 *  - User ID 1 only
 *  - CSRF via Form Builder (Token::check)
 *  - MIME/size validations for uploads
 */

$init = null;
for ($i = 0; $i < 6; $i++) {
  $try = realpath(__DIR__ . str_repeat(DIRECTORY_SEPARATOR . '..', $i) . '/users/init.php');
  if ($try && file_exists($try)) { $init = $try; break; }
}
if ($init) {
  require_once $init;
} else {
  die('ReBrand: could not locate users/init.php');
}

// Ensure we have a DB instance even if $db isn't global in this scope
if (!isset($db) || !($db instanceof DB)) {
  $db = DB::getInstance();
}

if (!isset($db)) {
  die('ReBrand: UserSpice DB context not available.');
}

$userId = $user->data()->id ?? null;
if ((int)$userId !== 1) {
  die('ReBrand: Only User ID 1 may perform these actions.');
}

$usRoot     = isset($abs_us_root) ? rtrim($abs_us_root, '/\\') . '/' : rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/';
$usUrlRoot  = isset($us_url_root) ? $us_url_root : '/';
$usersc     = $usRoot . 'usersc/';
$imagesDir  = $usRoot . 'users/images/';
$rebrandDir = $imagesDir . 'rebrand/';
$iconsDir   = $rebrandDir . 'icons/';
$headTagsPath = $usersc . 'includes/head_tags.php';

$tableSettings     = 'us_rebrand_settings';
$tableMenuBackups  = 'us_rebrand_menu_backups';
$tableFileBackups  = 'us_rebrand_file_backups';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$_SESSION['rebrand_flash'] = []; // simple flash mechanism

// ---- helpers ---------------------------------------------------------------

function rebrand_redirect_back($usUrlRoot) {
  $dest = rtrim($usUrlRoot, '/') . '/users/admin.php?view=plugins_config&plugin=rebrand';
  header('Location: ' . $dest);
  exit;
}


function rebrand_flash_success($msg) {
  $_SESSION['rebrand_flash']['success'] = $msg;
}
function rebrand_flash_error($msg) {
  $_SESSION['rebrand_flash']['error'] = $msg;
}

function rebrand_csrf_ok(): bool {
  if (class_exists('Token') && method_exists('Token', 'check')) {
    $token = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    return Token::check($token);
  }
  // If Token is unavailable, treat as failure.
  return false;
}

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
}

function rebrand_finfo_mime($tmpfile) {
  $f = finfo_open(FILEINFO_MIME_TYPE);
  if (!$f) return null;
  $mime = finfo_file($f, $tmpfile);
  finfo_close($f);
  return $mime ?: null;
}

function rebrand_load_settings($db, $tableSettings) {
  $row = $db->query("SELECT * FROM `{$tableSettings}` WHERE id = 1")->first();
  if ($row) return $row;
  // fallback defaults
  return (object)[
    'asset_version' => 1,
    'logo_path' => 'users/images/rebrand/logo.png',
    'logo_dark_path' => null,
    'favicon_mode' => 'single',
    'favicon_root' => 'users/images/rebrand/icons',
    'favicon_html' => null,
    'social_links' => json_encode(new stdClass()),
    'menu_target_ids' => json_encode([]),
    'header_override_enabled' => 1,
    'id1_only' => 1,
  ];
}

function rebrand_update_settings($db, $tableSettings, array $patch) {
  $exists = $db->query("SELECT id FROM `{$tableSettings}` WHERE id = 1")->first();
  if ($exists) {
    $db->update($tableSettings, 1, $patch);
  } else {
    $patch['id'] = 1;
    $db->insert($tableSettings, $patch);
  }
}

function rebrand_bump_version($db, $tableSettings) {
  $s = rebrand_load_settings($db, $tableSettings);
  $new = max(1, (int)$s->asset_version) + 1;
  rebrand_update_settings($db, $tableSettings, ['asset_version' => $new]);
  return $new;
}

// libs
require_once __DIR__ . '/../lib/RebrandService.php';
require_once __DIR__ . '/../lib/IconGenerator.php';
require_once __DIR__ . '/../lib/HeadTagsPatcher.php';
require_once __DIR__ . '/../lib/MenuPatcher.php';
require_once __DIR__ . '/../lib/SiteSettings.php'; // NEW

$service    = new \Rebrand\RebrandService($db, $tableSettings);
$icons      = new \Rebrand\IconGenerator();
$headPatch  = new \Rebrand\HeadTagsPatcher($db, $tableFileBackups, $headTagsPath);
$menuPatch  = new \Rebrand\MenuPatcher($db, $tableMenuBackups);
$siteSvc    = new \Rebrand\SiteSettings($db);

// ---- action handlers -------------------------------------------------------

try {
  switch ($action) {
    case 'bump_version': {
      if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }
      $new = rebrand_bump_version($db, $tableSettings);
      rebrand_flash_success("Asset version bumped to {$new}.");
      break;
    }

    case 'upload_logo': {
    if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }

    if (empty($_FILES['logo_file'])) {
        rebrand_flash_error('No logo file uploaded.');
        break;
    }

    // Handle PHP upload errors up front
    $err = (int)($_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $map = [
        UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds upload_max_filesize.',
        UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds MAX_FILE_SIZE.',
        UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on server.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
        ];
        rebrand_flash_error('Upload error: ' . ($map[$err] ?? ('code '.$err)));
        break;
    }

    $tmp  = $_FILES['logo_file']['tmp_name'] ?? '';
    $name = $_FILES['logo_file']['name'] ?? '';
    $size = (int)($_FILES['logo_file']['size'] ?? 0);

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        rebrand_flash_error('No temporary upload found (blocked by server?).');
        break;
    }
    if ($size <= 0 || $size > 5 * 1024 * 1024) { // allow up to 5MB
        rebrand_flash_error('Logo file too large (max 5MB).');
        break;
    }

    $mime = rebrand_finfo_mime($tmp);
    $okMimes = ['image/png','image/x-png','image/jpeg','image/pjpeg','image/jpg'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $extOk = in_array($ext, ['png','jpg','jpeg'], true);
    if (!($mime && in_array(strtolower($mime), $okMimes, true)) && !$extOk) {
        rebrand_flash_error('Logo must be PNG or JPG. (Detected: ' . htmlspecialchars((string)$mime) . ')');
        break;
    }
    $isPng = ($ext === 'png') || (strtolower((string)$mime) === 'image/png') || (strtolower((string)$mime) === 'image/x-png');

    $isDark = !empty($_POST['is_dark']);
    $destRel = $isDark
        ? 'users/images/rebrand/logo-dark.' . ($isPng ? 'png' : 'jpg')
        : 'users/images/rebrand/logo.'      . ($isPng ? 'png' : 'jpg');
    $destAbs = $usRoot . ltrim($destRel, '/');

    // Ensure destination directory exists
    $destDir = dirname($destAbs);
    if (!is_dir($destDir)) {
        if (!mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        rebrand_flash_error('Cannot create destination directory: ' . htmlspecialchars($destDir));
        break;
        }
    }

    // Optional resize
    $doResize = !empty($_POST['resize_logo']);
    $maxW = isset($_POST['logo_max_w']) ? (int)$_POST['logo_max_w'] : 0;
    $maxH = isset($_POST['logo_max_h']) ? (int)$_POST['logo_max_h'] : 0;

    try {
        if ($doResize && ($maxW > 0 || $maxH > 0)) {
        // Use service to resize; it reads from the tmp file and writes atomically
        $service->saveResizedImage($tmp, $mime ?: ($isPng ? 'image/png' : 'image/jpeg'), $destAbs, $maxW, $maxH);
        } else {
        // Move the uploaded file safely, then atomically place it
        $tmpDest = $destAbs . '.upload';
        if (!move_uploaded_file($tmp, $tmpDest)) {
            rebrand_flash_error('Failed to move uploaded file.');
            break;
        }
        // Now replace atomically
        if (!@rename($tmpDest, $destAbs)) {
            @unlink($tmpDest);
            rebrand_flash_error('Failed to finalize uploaded file.');
            break;
        }
        @chmod($destAbs, 0644);
        }
    } catch (Exception $e) {
        rebrand_flash_error('Logo processing error: ' . htmlspecialchars($e->getMessage()));
        break;
    }

    // Update settings + bump version
    $patch = $isDark ? ['logo_dark_path' => $destRel] : ['logo_path' => $destRel];
    rebrand_update_settings($db, $tableSettings, $patch);
    $newVer = rebrand_bump_version($db, $tableSettings);

    rebrand_flash_success('Logo updated successfully. Cache-busting applied (v' . (int)$newVer . ').');
    break;
    }


case 'upload_favicon_single': {
  if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }

  if (empty($_FILES['favicon_ico'])) {
    rebrand_flash_error('No favicon.ico uploaded.');
    break;
  }

  // Map PHP upload errors
  $err = (int)($_FILES['favicon_ico']['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($err !== UPLOAD_ERR_OK) {
    $map = [
      UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds upload_max_filesize.',
      UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds MAX_FILE_SIZE.',
      UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
      UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
      UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on server.',
      UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
      UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
    ];
    rebrand_flash_error('Upload error: ' . ($map[$err] ?? ('code '.$err)));
    break;
  }

  $tmp  = $_FILES['favicon_ico']['tmp_name'] ?? '';
  $name = $_FILES['favicon_ico']['name'] ?? '';
  $size = (int)($_FILES['favicon_ico']['size'] ?? 0);

  if ($tmp === '' || !is_uploaded_file($tmp)) {
    rebrand_flash_error('No temporary upload found (blocked by server?).');
    break;
  }
  if ($size <= 0 || $size > 512 * 1024) { // <=512KB
    rebrand_flash_error('favicon.ico too large (max 512KB).');
    break;
  }

  // Light validation: ICO is commonly reported as image/x-icon or image/vnd.microsoft.icon
  $mime = rebrand_finfo_mime($tmp);
  $okMimes = ['image/x-icon','image/vnd.microsoft.icon','image/ico','application/octet-stream'];
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if ($ext !== 'ico' && ($mime && !in_array(strtolower($mime), $okMimes, true))) {
    rebrand_flash_error('Invalid .ico file. (Detected: ' . htmlspecialchars((string)$mime) . ')');
    break;
  }

  // Destination: site root
  $destAbs = $usRoot . 'favicon.ico';
  $destDir = dirname($destAbs);
  if (!is_dir($destDir)) {
    if (!mkdir($destDir, 0755, true) && !is_dir($destDir)) {
      rebrand_flash_error('Cannot create destination directory: ' . htmlspecialchars($destDir));
      break;
    }
  }

  // Move safely then atomically place
  $tmpDest = $destAbs . '.upload';
  if (!@move_uploaded_file($tmp, $tmpDest)) {
    rebrand_flash_error('Failed to move uploaded file into place.');
    break;
  }
  if (!@rename($tmpDest, $destAbs)) {
    @unlink($tmpDest);
    rebrand_flash_error('Failed to finalize uploaded favicon at site root.');
    break;
  }
  @chmod($destAbs, 0644);

  rebrand_bump_version($db, $tableSettings);
  rebrand_flash_success('favicon.ico replaced at site root. Cache-busting applied.');
  break;
}

    case 'save_head_snippet': {
    if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }
    $snippet = isset($_POST['head_snippet']) ? trim((string)$_POST['head_snippet']) : '';
    rebrand_update_settings($db, $tableSettings, ['favicon_html' => $snippet !== '' ? $snippet : null]);
    rebrand_flash_success($snippet === '' ? 'Head snippet cleared.' : 'Head snippet saved.');
    break;
    }

    case 'save_head_meta': {
    if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }

    require_once __DIR__ . '/../lib/HeadTagsPatcher.php';
    $headPatch = new \Rebrand\HeadTagsPatcher($db, $tableFileBackups, $usRoot, $usUrlRoot);

    $s = rebrand_load_settings($db, $tableSettings);
    $ver = (int)$s->asset_version;

    $fields = [
        'charset'        => (string)($_POST['charset'] ?? ''),
        'x_ua'           => (string)($_POST['x_ua'] ?? ''),
        'description'    => (string)($_POST['description'] ?? ''),
        'author'         => (string)($_POST['author'] ?? ''),
        'og_url'         => (string)($_POST['og_url'] ?? ''),
        'og_type'        => (string)($_POST['og_type'] ?? 'website'),
        'og_title'       => (string)($_POST['og_title'] ?? ''),
        'og_desc'        => (string)($_POST['description'] ?? ''), // reuse desc if separate not provided
        'og_image'       => (string)($_POST['og_image'] ?? ''),
        'shortcut_icon'  => (string)($_POST['shortcut_icon'] ?? ''),
    ];

    try {
        $headPatch->applyMeta($fields, $ver);
    } catch (\Exception $e) {
        rebrand_flash_error('Head meta update failed: ' . htmlspecialchars($e->getMessage()));
        break;
    }

    rebrand_flash_success("Head meta updated in usersc/includes/head_tags.php (backup created).");
    break;
}


    case 'generate_icons_offline': {
      if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }
      if (empty($_FILES['master_png']['tmp_name'])) {
        rebrand_flash_error('No master PNG uploaded.');
        break;
      }
      $tmp  = $_FILES['master_png']['tmp_name'];
      $size = (int)($_FILES['master_png']['size'] ?? 0);
      if ($size <= 0 || $size > 5 * 1024 * 1024) {
        rebrand_flash_error('Master image too large (max 5MB).');
        break;
      }
      $mime = rebrand_finfo_mime($tmp);
      if ($mime !== 'image/png') {
        rebrand_flash_error('Master image must be PNG.');
        break;
      }
      $includeMaskable = !empty($_POST['include_maskable']);
      $themeColor = trim($_POST['theme_color'] ?? '');

      // Ensure output dirs exist
      if (!is_dir($iconsDir)) {
        if (!mkdir($iconsDir, 0755, true) && !is_dir($iconsDir)) {
          throw new Exception("Failed to create icons directory: {$iconsDir}");
        }
      }

      // Generate files & snippet (PWA lines commented by default)
      $result = $icons->generateFromMaster($tmp, $iconsDir, [
        'include_maskable' => (bool)$includeMaskable,
        'theme_color' => $themeColor,
      ]);

      if (empty($result['snippet'])) {
        throw new Exception('Icon generator did not return a snippet.');
      }

      // Save snippet into settings
      rebrand_update_settings($db, $tableSettings, [
        'favicon_mode' => 'multi',
        'favicon_root' => 'users/images/rebrand/icons',
        'favicon_html' => $result['snippet'],
      ]);

      $newVer = rebrand_bump_version($db, $tableSettings);
      rebrand_flash_success('Offline icons generated and head snippet saved. Cache-busting applied (v' . (int)$newVer . ').');
      break;
    }

    case 'apply_head_tags': {
      if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }
      $s = rebrand_load_settings($db, $tableSettings);
      $snippet = (string)($s->favicon_html ?? '');
      $ver = (int)$s->asset_version;

      $headPatch->apply($snippet, $ver);
      rebrand_flash_success('Head tags applied to usersc/includes/head_tags.php (backup created).');
      break;
    }

    case 'diff_head_tags': {
      if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }
      // optional candidate/dynamic diff not needed right now
      $diff = $headPatch->diff();
      if ($diff) {
        rebrand_flash_success("Head tags diff:\n" . $diff);
      } else {
        rebrand_flash_success('No differences or file missing markers.');
      }
      break;
    }

    case 'revert_head_tags': {
      if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }
      $ok = $headPatch->revertLastBackup();
      if ($ok) rebrand_flash_success('Reverted head_tags.php from last backup.');
      else rebrand_flash_error('No backup found to revert.');
      break;
    }

    case 'save_menu_targets': {
      if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }
      $json = trim($_POST['menu_target_ids'] ?? '[]');
      $arr = json_decode($json, true);
      if (!is_array($arr)) {
        rebrand_flash_error('Invalid JSON for menu targets.');
        break;
      }
      rebrand_update_settings($db, $tableSettings, ['menu_target_ids' => json_encode(array_values($arr))]);
      rebrand_flash_success('Menu targets saved.');
      break;
    }

    case 'discover_menu_candidates': {
      if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }
      $cands = $menuPatch->discoverCandidates();
      if (!$cands) {
        rebrand_flash_success('No likely menu candidates discovered.');
      } else {
        rebrand_flash_success('Discovered candidates: ' . implode(', ', array_map('strval', $cands)));
      }
      break;
    }


case 'menu_search_replace': {
  if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }

  // Inputs
  $ids = $_POST['menu_ids'] ?? [];
  if (!is_array($ids) || empty($ids)) { rebrand_flash_error('Select at least one menu.'); break; }
  $ids = array_values(array_unique(array_map('intval', $ids)));

  $findRaw = trim((string)($_POST['find'] ?? ''));
  $replRaw = trim((string)($_POST['replace'] ?? ''));
  $dryRun  = !empty($_POST['dry_run']);
  $appendV = !empty($_POST['append_version']);

  if ($findRaw === '') { rebrand_flash_error('Find value is required.'); break; }
  if ($replRaw === '') { rebrand_flash_error('Replace value is required.'); break; }

  // Load current asset version if needed
  $s = rebrand_load_settings($db, $tableSettings);
  $ver = (int)$s->asset_version;

  // Helper: ensure ?v= appended once
  $ensureVer = function(string $url) use ($appendV, $ver): string {
    if (!$appendV) return $url;
    // if already has ?v=, leave it
    if (preg_match('/[?&]v=\d+$/', $url)) return $url;
    return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $ver;
  };

  $findCandidates = [];
  $replCandidates = [];

  // We’ll match raw and encoded variants in brand_html
  $findCandidates[] = $findRaw;
  $findCandidates[] = htmlspecialchars($findRaw, ENT_QUOTES, 'UTF-8');

  $replTarget = $ensureVer($replRaw);
  $replCandidates[] = $replTarget;
  $replCandidates[] = htmlspecialchars($replTarget, ENT_QUOTES, 'UTF-8');

  $report = [];
  $totalChanges = 0;

  foreach ($ids as $mid) {
    $row = $db->query("SELECT id, brand_html, menu_name FROM us_menus WHERE id = ? LIMIT 1", [$mid])->first();
    if (!$row) { $report[] = "ID {$mid}: not found"; continue; }

    $orig = (string)$row->brand_html;
    $new  = $orig;
    $countBefore = 0;
    $countAfter  = 0;

    // Try encoded first (most common), then raw
    foreach ($findCandidates as $i => $needle) {
      $replacement = $replCandidates[$i] ?? $replCandidates[0];
      $countBefore += substr_count($new, $needle);
      if ($countBefore > 0) {
        $new = str_replace($needle, $replacement, $new, $replCount);
        $countAfter += (int)$replCount;
      }
    }

    if ($countAfter === 0) {
      $report[] = "ID {$mid} (“" . ($row->menu_name ?? '') . "”): no matches";
      continue;
    }

    if ($dryRun) {
      $report[] = "ID {$mid}: would replace {$countAfter} occurrence(s).";
      $totalChanges += $countAfter;
      continue;
    }

    // Backup the original row
    try {
      $db->insert($tableMenuBackups, [
        'menu_id'        => (int)$mid,
        'menu_item_id'   => null,
        'menu_name'      => (string)($row->menu_name ?? ''),
        'content_backup' => $orig,
        'notes'          => 'SearchReplace path update',
      ]);
    } catch (\Exception $e) {
      rebrand_flash_error('Backup failed for menu id ' . (int)$mid . ': ' . htmlspecialchars($e->getMessage()));
      break 2;
    }

    // Write new content
    $db->query("UPDATE us_menus SET brand_html = ? WHERE id = ? LIMIT 1", [$new, $mid]);
    $report[] = "ID {$mid}: replaced {$countAfter} occurrence(s).";
    $totalChanges += $countAfter;
  }

  if (!$dryRun && $totalChanges > 0) {
    // Bump asset version to be safe (in case you added ?v=)
    rebrand_bump_version($db, $tableSettings);
  }

  $prefix = $dryRun ? "Dry-run result:\n" : "Search & replace complete:\n";
  rebrand_flash_success($prefix . implode("\n", $report));
  break;
}


    case 'apply_menu_patch': {
      if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }
      $s = rebrand_load_settings($db, $tableSettings);
      $menuIds = json_decode($s->menu_target_ids ?? '[]', true) ?: [];

      if (empty($menuIds)) {
        rebrand_flash_error('No menu targets configured.');
        break;
      }

      $service->ensureAssetPaths($usRoot); // sanity check directories
      $menuPatch->apply($menuIds, [
        'logo_path'   => (string)$s->logo_path,
        'logo_dark'   => (string)($s->logo_dark_path ?? ''),
        'asset_ver'   => (int)$s->asset_version,
        'social_links'=> json_decode($s->social_links ?? '{}', true) ?: [],
        'favicon_root'=> (string)($s->favicon_root ?? 'users/images/rebrand/icons'),
      ]);

      rebrand_flash_success('Menu content updated (backups created).');
      break;
    }

    case 'diff_menu_patch': {
      if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }
      $s = rebrand_load_settings($db, $tableSettings);
      $menuIds = json_decode($s->menu_target_ids ?? '[]', true) ?: [];
      if (empty($menuIds)) {
        rebrand_flash_error('No menu targets configured.');
        break;
      }
      $diff = $menuPatch->diff($menuIds);
      rebrand_flash_success($diff ? "Menu diff:\n" . $diff : 'No differences or markers not found in targets.');
      break;
    }

    case 'revert_menu_patch': {
      if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }
      $ok = $menuPatch->revertLastBackups();
      if ($ok) rebrand_flash_success('Reverted menu rows from last backup set.');
      else rebrand_flash_error('No menu backups found to revert.');
      break;
    }

    case 'save_social_links': {
      if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }
      $in = $_POST['social'] ?? [];
      $clean = [];
      foreach ($in as $key => $cfg) {
        $enabled = !empty($cfg['enabled']);
        $url = trim($cfg['url'] ?? '');
        $order = (int)($cfg['order'] ?? 0);

        // Basic URL validation (http/https only)
        if ($url !== '') {
          $parsed = parse_url($url);
          if (!$parsed || empty($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http','https'])) {
            rebrand_flash_error("Invalid URL for {$key}. Only http/https allowed.");
            rebrand_redirect_back($usUrlRoot);
          }
        }

        $clean[$key] = [
          'enabled' => $enabled,
          'url'     => $url,
          'order'   => $order,
        ];
      }
      rebrand_update_settings($db, $tableSettings, ['social_links' => json_encode($clean)]);
      rebrand_flash_success('Social links saved.');
      break;
    }

    /* ---------------------- NEW: Site Settings edits ---------------------- */

    case 'site_settings_save': {
      if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }

      $siteId    = isset($_POST['site_id']) && ctype_digit((string)$_POST['site_id']) ? (int)$_POST['site_id'] : 0;
      $siteName  = (string)($_POST['site_name'] ?? '');
      $siteUrl   = isset($_POST['site_url']) ? (string)$_POST['site_url'] : null;
      $copyright = isset($_POST['copyright']) ? (string)$_POST['copyright'] : null;

      if ($siteId <= 0) {
        rebrand_flash_error('Invalid settings row (site_id).');
        break;
      }

      try {
        $siteSvc->updateSite($siteId, $siteName, $siteUrl, $copyright);
        rebrand_flash_success("Updated Site Settings (id {$siteId}): site_name / site_url  / copyright saved with backup.");
      } catch (Exception $e) {
        rebrand_flash_error('Site Settings error: ' . htmlspecialchars($e->getMessage()));
      }
      break;
    }

    case 'site_settings_revert': {
      if (!rebrand_csrf_ok()) { rebrand_flash_error('Invalid CSRF token.'); break; }

      $siteId = isset($_POST['site_id']) && ctype_digit((string)$_POST['site_id']) ? (int)$_POST['site_id'] : 0;
      if ($siteId <= 0) {
        rebrand_flash_error('Invalid settings row (site_id).');
        break;
      }

      $ok = false;
      try {
        $ok = $siteSvc->revertLastBackup($siteId);
      } catch (Exception $e) {
        rebrand_flash_error('Revert failed: ' . htmlspecialchars($e->getMessage()));
        break;
      }

      if ($ok) rebrand_flash_success("Reverted Site Settings (id {$siteId}) to last backup.");
      else rebrand_flash_error('No backup found to revert.');
      break;
    }

    /* --------------------------------------------------------------------- */

    default:
      rebrand_flash_error('Unknown action.');
  }

} catch (Exception $e) {
  rebrand_flash_error('Error: ' . htmlspecialchars($e->getMessage()));
}

// Always redirect back to the settings page
rebrand_redirect_back($usUrlRoot);
