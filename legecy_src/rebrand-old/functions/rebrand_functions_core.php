<?php
/**
 * ReBrand — Core helper functions
 * Location: usersc/plugins/rebrand/functions/rebrand_functions_core.php
 *
 * Include from admin/settings.php, admin/process.php, install/uninstall, etc.
 * Follows US5 rules: use DB::getInstance() inside functions; $abs_us_root/$us_url_root for FS/URLs.
 */

/* -------------------------------
   Admin / Flash helpers
-------------------------------- */
if (!function_exists('rb_is_admin1')) {
  function rb_is_admin1(): bool {
    global $user;
    return isset($user) && (int)($user->data()->id ?? 0) === 1;
  }
}

if (!function_exists('rb_flash_success')) {
  function rb_flash_success(string $msg): void {
    $_SESSION['msg'][] = ['type' => 'success', 'msg' => $msg];
  }
}
if (!function_exists('rb_flash_error')) {
  function rb_flash_error(string $msg): void {
    $_SESSION['msg'][] = ['type' => 'danger', 'msg' => $msg];
  }
}
if (!function_exists('rb_flash_info')) {
  function rb_flash_info(string $msg): void {
    $_SESSION['msg'][] = ['type' => 'info', 'msg' => $msg];
  }
}

/* -------------------------------
   Paths
-------------------------------- */
if (!function_exists('rb_path_icons_fs')) {
  function rb_path_icons_fs(bool $ensure = false): string {
    global $abs_us_root, $us_url_root;
    $path = rtrim($abs_us_root.$us_url_root, '/').'/users/images/rebrand/icons';
    if ($ensure && !is_dir($path)) {
      @mkdir($path, 0755, true);
    }
    return $path;
  }
}
if (!function_exists('rb_path_icons_url')) {
  function rb_path_icons_url(): string {
    global $us_url_root;
    return $us_url_root.'users/images/rebrand/icons/';
  }
}
if (!function_exists('rb_path_version_file')) {
  function rb_path_version_file(bool $ensureDir = false): string {
    $dir = __DIR__.'/../storage/versions';
    if ($ensureDir && !is_dir($dir)) {
      @mkdir($dir, 0755, true);
    }
    return $dir.'/asset_version.json';
  }
}
if (!function_exists('rb_path_head_file')) {
  function rb_path_head_file(bool $ensureDir = false): string {
    global $abs_us_root, $us_url_root;
    $path = rtrim($abs_us_root.$us_url_root, '/').'/users/includes/head_tags.php';
    if ($ensureDir) {
      @mkdir(dirname($path), 0755, true);
    }
    return $path;
  }
}

/* -------------------------------
   Version helpers
-------------------------------- */
if (!function_exists('rb_get_asset_version')) {
  function rb_get_asset_version(): int {
    $vf = rb_path_version_file(false);
    $v  = 1;
    if (is_file($vf)) {
      $raw = @file_get_contents($vf);
      $dec = json_decode($raw ?: '1', true);
      if (is_int($dec)) $v = $dec;
    }
    return max(1, (int)$v);
  }
}
if (!function_exists('rb_bump_asset_version')) {
  function rb_bump_asset_version(): int {
    $vf   = rb_path_version_file(true);
    $next = rb_get_asset_version() + 1;
    @file_put_contents($vf, json_encode($next, JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $next;
  }
}

/* -------------------------------
   File utilities
-------------------------------- */
if (!function_exists('rb_atomic_write')) {
  function rb_atomic_write(string $path, string $content): bool {
    $tmp = $path.'.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    @chmod($path, 0644);
    return true;
  }
}
if (!function_exists('rb_dos2unix')) {
  function rb_dos2unix(string $s): string {
    return preg_replace("/\r\n?/", "\n", $s);
  }
}

/* -------------------------------
   Backups
-------------------------------- */
if (!function_exists('rb_backup_file')) {
  function rb_backup_file(string $path, string $note = 'rebrand backup'): void {
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
      rb_flash_error('Backup failed for '.basename($path).': '.$e->getMessage());
    }
  }
}
if (!function_exists('rb_backup_settings')) {
  function rb_backup_settings(array $snapshot, string $note = 'settings snapshot'): void {
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
      rb_flash_error('Settings backup failed: '.$e->getMessage());
    }
  }
}

/* -------------------------------
   Asset detection
-------------------------------- */
if (!function_exists('rb_detect_assets')) {
  /**
   * Returns map of existing assets in the icons dir.
   * Keys:
   *  favicon_ico, favicon_16, favicon_32, apple_touch, android_192,
   *  android_512, maskable_512, og_image, safari_pinned, manifest, logo
   */
  function rb_detect_assets(?string $iconsDirFs = null): array {
    $dir = $iconsDirFs ?: rb_path_icons_fs(false);
    $logo = null;
    if (is_file($dir.'/logo.svg')) {
      $logo = 'logo.svg';
    } elseif (is_file($dir.'/logo.png')) {
      $logo = 'logo.png';
    }
    return [
      'favicon_ico'   => is_file($dir.'/favicon.ico'),
      'favicon_16'    => is_file($dir.'/favicon-16x16.png'),
      'favicon_32'    => is_file($dir.'/favicon-32x32.png'),
      'apple_touch'   => is_file($dir.'/apple-touch-icon.png'),
      'android_192'   => is_file($dir.'/android-chrome-192x192.png'),
      'android_512'   => is_file($dir.'/android-chrome-512x512.png'),
      'maskable_512'  => is_file($dir.'/maskable-512x512.png'),
      'og_image'      => is_file($dir.'/og-image.png'),
      'safari_pinned' => is_file($dir.'/safari-pinned-tab.svg'),
      'manifest'      => is_file($dir.'/site.webmanifest'),
      'logo'          => $logo,
    ];
  }
}

/* -------------------------------
   Small view helpers (optional)
-------------------------------- */
if (!function_exists('rb_yes_no')) {
  function rb_yes_no(bool $b): string {
    return $b ? '<span class="text-success">yes</span>' : '<span class="text-muted">no</span>';
  }
}
