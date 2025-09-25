<?php
namespace Rebrand;

/**
 * RebrandService (UserSpice v5)
 *
 * Central helpers for:
 *  - Settings I/O (read/update/bump version)
 *  - Filesystem prep (ensure asset paths exist & writable)
 *  - Image processing (safe resize with GD or Imagick)
 *  - Backups (files table) + simple restore helpers
 *  - Basic validation utilities
 *
 * STRICT RULES FOLLOWED:
 *  - DB access ONLY via \DB::getInstance() inside methods
 *  - Uses global $abs_us_root / $us_url_root as-is
 *  - Access guard for user ID 1
 *  - File backups -> table us_rebrand_file_backups BEFORE any write
 *  - Cache-busting via asset_version (stored in us_rebrand_settings)
 */
class RebrandService
{
    /** @var string Settings table name (id=1 row) */
    protected $tableSettings = 'us_rebrand_settings';

    /** @var string File backups table */
    protected $tableFileBackups = 'us_rebrand_file_backups';

    /** @var string Menu backups table (exposed as helper; not used here) */
    protected $tableMenuBackups = 'us_rebrand_menu_backups';

    /** @var string Site backups table (exposed as helper; not used here) */
    protected $tableSiteBackups = 'us_rebrand_site_backups';

    public function __construct(string $tableSettings = 'us_rebrand_settings')
    {
        $this->tableSettings = $tableSettings;
    }

    /* ---------------------------------------------------------------------
     * Access control
     * ------------------------------------------------------------------- */

    /**
     * Ensure only User ID 1 can proceed.
     * Throws \Exception on failure.
     */
    public function requireId1(): void
    {
        global $user;
        if (!isset($user) || !is_object($user) || !method_exists($user, 'data')) {
            throw new \Exception('Auth error: User object unavailable.');
        }
        $u = $user->data();
        if (!isset($u->id) || (int)$u->id !== 1) {
            throw new \Exception('Permission denied: Only user ID 1 may perform this action.');
        }
    }

    /* ---------------------------------------------------------------------
     * DB helper
     * ------------------------------------------------------------------- */

    /**
     * Obtain the DB instance (UserSpice standard).
     */
    protected function db(): \DB
    {
        return \DB::getInstance();
    }

    /* ---------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------- */

    /**
     * Return settings row (id=1) with safe defaults if not present.
     *
     * @return object
     */
    public function getSettings(): object
    {
        $row = $this->db()->query("SELECT * FROM `{$this->tableSettings}` WHERE id = 1")->first();
        if ($row) {
            return $row;
        }
        return (object)[
            'asset_version'           => 1,
            'logo_path'               => 'users/images/rebrand/logo.png',
            'logo_dark_path'          => null,
            'favicon_mode'            => 'single',
            'favicon_root'            => 'users/images/rebrand/icons',
            'favicon_html'            => null,
            'social_links'            => json_encode(new \stdClass()),
            'menu_target_ids'         => json_encode([]),
            'header_override_enabled' => 1,
            'id1_only'                => 1,
        ];
    }

    /**
     * Update settings (id=1). Creates the row if missing.
     * Uses DB::getInstance() internally.
     */
    public function updateSettings(array $patch): void
    {
        $exists = $this->db()->query("SELECT id FROM `{$this->tableSettings}` WHERE id = 1")->first();
        if ($exists) {
            $this->db()->update($this->tableSettings, 1, $patch);
        } else {
            $patch['id'] = 1;
            $this->db()->insert($this->tableSettings, $patch);
        }
    }

    /**
     * Increment asset_version and return the new value.
     */
    public function bumpAssetVersion(): int
    {
        $s = $this->getSettings();
        $new = max(1, (int)$s->asset_version) + 1;
        $this->updateSettings(['asset_version' => $new]);
        return $new;
    }

    /**
     * Return a URL path with ?v=<asset_version> appended.
     * Pass a path relative to the web root (e.g., 'users/images/rebrand/logo.png').
     */
    public function withCacheBust(string $urlPath): string
    {
        $v = (int)$this->getSettings()->asset_version;
        $sep = (strpos($urlPath, '?') === false) ? '?' : '&';
        return $urlPath . $sep . 'v=' . $v;
    }

    /* ---------------------------------------------------------------------
     * Filesystem helpers
     * ------------------------------------------------------------------- */

    /**
     * Ensure core asset directories exist (users/images/rebrand, icons).
     *
     * Uses global $abs_us_root with no assumptions.
     *
     * @throws \Exception when directories cannot be created
     */
    public function ensureAssetPaths(): void
    {
        global $abs_us_root;
        $root = rtrim((string)$abs_us_root, '/\\');

        $imgDir     = $root . '/users/images';
        $rebrandDir = $imgDir . '/rebrand';
        $iconsDir   = $rebrandDir . '/icons';

        $this->mkdirp($imgDir);
        $this->mkdirp($rebrandDir);
        $this->mkdirp($iconsDir);

        // Optional: add .htaccess to disallow PHP execution in icons dir (Apache)
        $htPath = $iconsDir . '/.htaccess';
        if (!file_exists($htPath)) {
            $this->atomicWrite($htPath, <<<HT
# Auto-generated by Rebrand plugin
<FilesMatch "\\.(php|php\\d*|phtml)$">
  Deny from all
</FilesMatch>
HT
            , 'Create .htaccess in icons');
        }
    }

    /**
     * Create directory recursively if missing.
     */
    public function mkdirp(string $dir, int $mode = 0755): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
                throw new \Exception("Failed to create directory: {$dir}");
            }
        }
    }

    /**
     * Atomic write to a file (tmp + rename) with REQUIRED backup
     * to table us_rebrand_file_backups BEFORE modifying the target.
     *
     * @param string $path    Absolute target path
     * @param string $content New content to write
     * @param string $notes   Optional human note for backup row
     */
    public function atomicWrite(string $path, string $content, string $notes = ''): void
    {
        $dir = dirname($path);
        $this->mkdirp($dir);

        // Backup existing content (if any) BEFORE we write
        $existing = file_exists($path) ? @file_get_contents($path) : '';
        $this->backupFile($path, $existing, $notes);

        $tmp = @tempnam($dir, '.rebrand.tmp.');
        if ($tmp === false) {
            throw new \Exception("Failed to create temp file in {$dir}");
        }
        $bytes = @file_put_contents($tmp, $content);
        if ($bytes === false) {
            @unlink($tmp);
            throw new \Exception("Failed to write temp content for {$path}");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \Exception("Failed to move temp file into place: {$path}");
        }
    }

    /**
     * Insert a backup row into us_rebrand_file_backups.
     * NOTE: Schema per spec (file_path, content_backup, notes).
     */
    public function backupFile(string $absPath, string $contentBackup, string $notes = ''): void
    {
        $stamp = date('Y-m-d H:i:s');
        $safeNotes = trim($notes) === '' ? "Auto-backup {$stamp}" : "{$notes} ({$stamp})";

        $this->db()->insert($this->tableFileBackups, [
            'file_path'      => $absPath,
            'content_backup' => $contentBackup,
            'notes'          => $safeNotes,
        ]);
    }

    /**
     * Restore the most recent backup row for a given absolute file path.
     * Returns true if a backup was found and restored; false if none found.
     */
    public function restoreLatestFileBackup(string $absPath): bool
    {
        $row = $this->db()
            ->query("SELECT * FROM `{$this->tableFileBackups}` WHERE file_path = ? ORDER BY id DESC LIMIT 1", [$absPath])
            ->first();

        if (!$row || !isset($row->content_backup)) {
            return false;
        }

        // Write the backup back to disk (also makes a new backup of current)
        $this->atomicWrite($absPath, (string)$row->content_backup, 'Restore latest file backup');
        return true;
        }

    /* ---------------------------------------------------------------------
     * Image processing
     * ------------------------------------------------------------------- */

    /**
     * Save a resized image to destination path, preserving aspect ratio.
     * If both $maxW and $maxH are zero/empty, acts like a normal copy.
     *
     * @param string $srcTmp  Path to uploaded temp file
     * @param string $mime    image/png or image/jpeg
     * @param string $destAbs Absolute destination path (including filename)
     * @param int    $maxW    Max width (px), 0 to ignore
     * @param int    $maxH    Max height (px), 0 to ignore
     */
    public function saveResizedImage(string $srcTmp, string $mime, string $destAbs, int $maxW = 0, int $maxH = 0): void
    {
        // No resize requested — plain copy with backup via atomicWrite
        if ($maxW <= 0 && $maxH <= 0) {
            $data = @file_get_contents($srcTmp);
            if ($data === false) {
                throw new \Exception('Failed to read uploaded file.');
            }
            $this->atomicWrite($destAbs, $data, 'Copy uploaded image');
            return;
        }

        if (class_exists('\Imagick')) {
            $this->resizeWithImagick($srcTmp, $mime, $destAbs, $maxW, $maxH);
            return;
        }

        if (extension_loaded('gd')) {
            $this->resizeWithGd($srcTmp, $mime, $destAbs, $maxW, $maxH);
            return;
        }

        // Fallback: plain copy if no libraries
        $data = @file_get_contents($srcTmp);
        if ($data === false) {
            throw new \Exception('Failed to read uploaded file (no imaging libs available).');
        }
        $this->atomicWrite($destAbs, $data, 'Copy uploaded image (no imaging libs)');
    }

    /**
     * Resize using Imagick (preserves transparency).
     */
    protected function resizeWithImagick(string $srcTmp, string $mime, string $destAbs, int $maxW, int $maxH): void
    {
        $img = new \Imagick();
        if (!$img->readImage($srcTmp)) {
            throw new \Exception('Imagick failed to read image.');
        }

        $img->setImageColorspace(\Imagick::COLORSPACE_RGB); // avoid profiles
        $img->setImageCompressionQuality(90);

        $geo = $img->getImageGeometry();
        $w = (int)($geo['width'] ?? 0);
        $h = (int)($geo['height'] ?? 0);
        if ($w <= 0 || $h <= 0) {
            $img->clear(); $img->destroy();
            throw new \Exception('Invalid image dimensions.');
        }

        [$targetW, $targetH] = $this->fitWithin($w, $h, $maxW, $maxH);
        if ($targetW > 0 && $targetH > 0 && ($targetW !== $w || $targetH !== $h)) {
            $img->resizeImage($targetW, $targetH, \Imagick::FILTER_LANCZOS, 1);
        }

        // Normalize output format based on destination extension
        $ext = strtolower(pathinfo($destAbs, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            $img->setImageFormat('png');
            $img->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
        } else {
            $img->setImageFormat('jpeg');
            $img->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $img->setBackgroundColor('white');
        }

        $blob = $img->getImagesBlob();
        $img->clear(); $img->destroy();

        $this->atomicWrite($destAbs, $blob, 'Resize image (Imagick)');
    }

    /**
     * Resize using GD (preserves PNG alpha).
     */
    protected function resizeWithGd(string $srcTmp, string $mime, string $destAbs, int $maxW, int $maxH): void
    {
        // Create source image
        if ($mime === 'image/png') {
            $src = @imagecreatefrompng($srcTmp);
        } elseif ($mime === 'image/jpeg') {
            $src = @imagecreatefromjpeg($srcTmp);
        } else {
            throw new \Exception('Unsupported MIME type for GD.');
        }
        if (!$src) {
            throw new \Exception('GD failed to read image.');
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= 0 || $h <= 0) {
            imagedestroy($src);
            throw new \Exception('Invalid image dimensions.');
        }

        [$targetW, $targetH] = $this->fitWithin($w, $h, $maxW, $maxH);

        // If no resize needed
        if ($targetW === $w && $targetH === $h) {
            $data = @file_get_contents($srcTmp);
            imagedestroy($src);
            if ($data === false) {
                throw new \Exception('Failed to read uploaded file.');
            }
            $this->atomicWrite($destAbs, $data, 'Copy uploaded image (no resize)');
            return;
        }

        $dst = imagecreatetruecolor($targetW, $targetH);
        if ($mime === 'image/png') {
            // Preserve alpha for PNG
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $transparent);
        } else {
            // Fill white for JPG
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $white);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $w, $h);
        imagedestroy($src);

        // Encode based on extension
        $ext = strtolower(pathinfo($destAbs, PATHINFO_EXTENSION));
        ob_start();
        $ok = false;
        if ($ext === 'png') {
            $ok = imagepng($dst, null, 6);
        } else {
            $ok = imagejpeg($dst, null, 90);
        }
        $blob = ob_get_clean();
        imagedestroy($dst);

        if (!$ok || $blob === false) {
            throw new \Exception('Failed to encode resized image.');
        }

        $this->atomicWrite($destAbs, $blob, 'Resize image (GD)');
    }

    /**
     * Compute a size that fits ($w,$h) within max bounds while preserving aspect.
     * If both max are zero, returns original size.
     */
    public function fitWithin(int $w, int $h, int $maxW = 0, int $maxH = 0): array
    {
        $maxW = max(0, (int)$maxW);
        $maxH = max(0, (int)$maxH);
        if ($maxW === 0 && $maxH === 0) return [$w, $h];

        // If one bound is missing, derive from aspect ratio with the other
        if ($maxW === 0) {
            $scale = $maxH / $h;
            return [max(1, (int)round($w * $scale)), $maxH];
        }
        if ($maxH === 0) {
            $scale = $maxW / $w;
            return [$maxW, max(1, (int)round($h * $scale))];
        }

        // Both present: choose the constraining dimension
        $scale = min($maxW / $w, $maxH / $h);
        if ($scale >= 1) return [$w, $h]; // already smaller
        $tw = max(1, (int)floor($w * $scale));
        $th = max(1, (int)floor($h * $scale));
        return [$tw, $th];
    }

    /* ---------------------------------------------------------------------
     * Validation utilities
     * ------------------------------------------------------------------- */

    /**
     * Validate a URL is http/https (returns normalized string or empty).
     */
    public function sanitizeHttpUrl(?string $url): string
    {
        $u = trim((string)$url);
        if ($u === '') return '';
        $parts = parse_url($u);
        if (!$parts || empty($parts['scheme'])) return '';
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) return '';
        return $u;
    }

    /**
     * Determine MIME type via finfo (returns null if unknown).
     */
    public function finfoMime(string $file): ?string
    {
        $f = @finfo_open(FILEINFO_MIME_TYPE);
        if (!$f) return null;
        $m = @finfo_file($f, $file);
        @finfo_close($f);
        return $m ?: null;
    }

    /* ---------------------------------------------------------------------
     * URLs / Plugin Manager helpers
     * ------------------------------------------------------------------- */

    /**
     * Return the Plugin Manager URL for this plugin.
     * (Use for clean redirects after handling.)
     */
    public function pluginManagerUrl(): string
    {
        global $us_url_root;
        return $us_url_root . 'users/admin.php?view=plugins_config&plugin=rebrand';
    }

    /**
     * Convenience: build an absolute filesystem path from a web-root relative path.
     * e.g., 'users/images/rebrand/logo.png' -> $abs_us_root.'users/images/rebrand/logo.png'
     */
    public function absPathFromWebPath(string $webPath): string
    {
        global $abs_us_root;
        $root = rtrim((string)$abs_us_root, '/\\');
        $rel  = ltrim($webPath, '/\\');
        return $root . '/' . $rel;
    }
}
