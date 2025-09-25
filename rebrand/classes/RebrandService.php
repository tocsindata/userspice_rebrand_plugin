<?php
declare(strict_types=1);

namespace Rebrand;

/**
 * ReBrand — RebrandService
 * Location: usersc/plugins/rebrand/classes/RebrandService.php
 *
 * Responsibilities (static helpers):
 *  - Paths: icons dir, head file, version file
 *  - Versioning: get/bump asset_version
 *  - Files: mkdirp, atomic write, newline normalization, safe deletion
 *  - Backups: file backups (to us_rebrand_file_backups) with timestamp/user
 *
 * Notes:
 *  - All methods are static for easy reuse without DI.
 *  - Callers enforce auth/CSRF; this class focuses on mechanics.
 */
class RebrandService
{
    /* ---------------------------
       PATH HELPERS
    ----------------------------*/
    /** Absolute FS path to icons directory. */
    public static function iconsDirFs(bool $ensure = false): string
    {
        /** @var string $abs_us_root */
        /** @var string $us_url_root */
        global $abs_us_root, $us_url_root;
        $path = rtrim($abs_us_root.$us_url_root, '/').'/users/images/rebrand/icons';
        if ($ensure && !is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        return $path;
    }

    /** Public URL base of icons dir. */
    public static function iconsDirUrl(): string
    {
        global $us_url_root;
        return $us_url_root.'users/images/rebrand/icons/';
    }

    /** Absolute FS path to users/includes/head_tags.php. */
    public static function headFilePath(bool $ensureDir = false): string
    {
        global $abs_us_root, $us_url_root;
        $path = rtrim($abs_us_root.$us_url_root, '/').'/users/includes/head_tags.php';
        if ($ensureDir) {
            @mkdir(\dirname($path), 0755, true);
        }
        return $path;
    }

    /** Absolute FS path to asset_version.json. */
    public static function versionFilePath(bool $ensureDir = false): string
    {
        $dir = __DIR__.'/../storage/versions';
        if ($ensureDir && !is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir.'/asset_version.json';
    }

    /* ---------------------------
       VERSIONING
    ----------------------------*/
    /** Read current asset version (>=1). */
    public static function getAssetVersion(): int
    {
        $vf = self::versionFilePath(false);
        $v  = 1;
        if (is_file($vf)) {
            $raw = @file_get_contents($vf);
            $dec = json_decode($raw ?: '1', true);
            if (is_int($dec)) $v = $dec;
        }
        return max(1, (int)$v);
    }

    /** Increment and persist asset version; returns new value. */
    public static function bumpAssetVersion(): int
    {
        $vf   = self::versionFilePath(true);
        $next = self::getAssetVersion() + 1;
        @file_put_contents($vf, json_encode($next, JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $next;
    }

    /* ---------------------------
       FILE UTILITIES
    ----------------------------*/
    /** Make directory path recursively (0755). */
    public static function mkdirp(string $dir): bool
    {
        return is_dir($dir) ?: @mkdir($dir, 0755, true);
    }

    /** Normalize CRLF/CR → LF. */
    public static function dos2unix(string $s): string
    {
        return \preg_replace("/\r\n?/", "\n", $s);
    }

    /** Atomic write with chmod 0644. */
    public static function atomicWrite(string $path, string $content): bool
    {
        $tmp = $path.'.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0644);
        return true;
    }

    /** Safe unlink (ignore errors). */
    public static function safeUnlink(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Recursive remove directory (best-effort). */
    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = @scandir($dir);
        if ($items === false) return;
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $p = $dir.DIRECTORY_SEPARATOR.$it;
            if (is_dir($p)) self::rrmdir($p);
            else @unlink($p);
        }
        @rmdir($dir);
    }

    /* ---------------------------
       BACKUPS (DB)
    ----------------------------*/
    /**
     * Backup a file’s current content to `us_rebrand_file_backups`.
     * Non-fatal by design; throws only if DB insert explodes.
     */
    public static function backupFile(string $path, string $note = 'rebrand backup'): void
    {
        if (!is_file($path)) return;
        $db  = \DB::getInstance();
        $now = date('Y-m-d H:i:s');
        $uid = (int)($GLOBALS['user']->data()->id ?? 0);
        $body = @file_get_contents($path);

        try {
            $db->insert('us_rebrand_file_backups', [
                'took_at'        => $now,
                'user_id'        => $uid,
                'file_path'      => $path,
                'content_backup' => $body,
                'notes'          => $note,
            ]);
        } catch (\Throwable $e) {
            // Let caller decide whether to treat as fatal; keep as exception for visibility.
            throw new \RuntimeException('Backup failed for '.basename($path).': '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Convenience: backup then atomic write (LF-normalized if $normalize).
     */
    public static function backupAndWrite(string $path, string $content, string $note = 'rebrand write', bool $normalize = true): void
    {
        if ($normalize) {
            $content = self::dos2unix($content);
        }
        if (is_file($path)) {
            self::backupFile($path, $note);
        }
        if (!self::atomicWrite($path, $content)) {
            throw new \RuntimeException('Failed to write file: '.basename($path));
        }
    }

    /* ---------------------------
       DETECTION
    ----------------------------*/
    /**
     * Detect existing brand assets in the icons directory.
     * Returns: map of booleans and current logo filename (logo.svg|logo.png|null).
     */
    public static function detectAssets(?string $dir = null): array
    {
        $dir = $dir ?: self::iconsDirFs(true);
        $logo = null;
        if (is_file($dir.'/logo.svg')) $logo = 'logo.svg';
        elseif (is_file($dir.'/logo.png')) $logo = 'logo.png';

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
