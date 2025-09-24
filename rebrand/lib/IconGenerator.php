<?php
namespace Rebrand;

/**
 * IconGenerator (UserSpice v5 compliant)
 *
 * - Generates favicon/app-icon set from a master PNG.
 * - Writes ONLY to the canonical location:
 *     $abs_us_root.$us_url_root.'users/images/rebrand/icons'
 * - Enforces admin-only (user id 1) for write operations.
 * - Creates DB backups in `us_rebrand_file_backups` prior to any overwrite.
 * - Maintains a persistent asset_version in
 *     $abs_us_root.$us_url_root.'usersc/plugins/rebrand/.asset_version'
 *   and appends ?v=<asset_version> to all controlled assets.
 * - Emits a head snippet that uses <?=$us_url_root?> for URLs.
 *
 * NOTE:
 * - This library throws Exceptions on failure; the admin/process layer should
 *   catch and route back to:
 *     <?=$us_url_root?>users/admin.php?view=plugins_config&plugin=rebrand
 */
class IconGenerator
{
    /** Output sizes (PNG) beyond the smalls used in ICO */
    protected array $pngSizes = [16, 32, 48, 64, 180, 192, 256, 384, 512];

    /** ICO sizes (subset of pngSizes) */
    protected array $icoSizes = [16, 32, 48, 64];

    /**
     * Public entry: generate icons into the canonical icons directory.
     * Returns ['files' => [...], 'snippet' => '...'].
     *
     * Options:
     *  - include_maskable (bool) : also write maskable-512.png
     *  - theme_color (string)    : hex color for commented theme meta    
     */
    public function generateFromMaster(string $masterPngTmp, array $opts = []): array
    {
        $this->enforceOwner();

        $dirs = $this->resolvedPaths(); // ['icons_dir' => ..., 'version_file' => ...]
        $iconsDir = $dirs['icons_dir'];

        $this->mkdirp($iconsDir);

        // Probe master
        $info = @getimagesize($masterPngTmp);
        if (!$info || empty($info[0]) || empty($info[1]) || ($info['mime'] ?? '') !== 'image/png') {
            throw new \Exception('Master image must be a valid PNG.');
        }
        [$mw, $mh] = [$info[0], $info[1]];
        if ($mw < 64 || $mh < 64) {
            throw new \Exception('Master PNG is too small. Provide at least 1024×1024 for best results.');
        }

        $includeMaskable = !empty($opts['include_maskable']);
        $themeColor = trim($opts['theme_color'] ?? '');

        $written = [];

        // Produce all PNG sizes (backup if overwriting)
        foreach ($this->pngSizes as $sz) {
            $path = rtrim($iconsDir, '/\\') . "/favicon-{$sz}x{$sz}.png";
            $this->backupIfExists($path, "pre-write resize {$sz}x{$sz}");
            $this->resizePngSquare($masterPngTmp, $path, $sz);
            $written[] = $path;
        }

        // Optional maskable (512)
        if ($includeMaskable) {
            $maskPath = rtrim($iconsDir, '/\\') . "/maskable-icon-512x512.png";
            $this->backupIfExists($maskPath, "pre-write maskable 512x512");
            $this->resizePngSquare($masterPngTmp, $maskPath, 512);
            $written[] = $maskPath;
        }

        // Build ICO from 16/32/48/64
        $icoPath = rtrim($iconsDir, '/\\') . '/favicon.ico';
        $this->backupIfExists($icoPath, "pre-write favicon.ico");
        $this->buildIcoFromPngs($iconsDir, $this->icoSizes, $icoPath);
        $written[] = $icoPath;

        // Bump persistent asset_version
        $assetVersion = $this->nextAssetVersion();

        // Build head snippet with <?=$us_url_root?> and cache-busting
        $snippet = $this->buildHeadSnippet($includeMaskable, $themeColor, $assetVersion);

        return [
            'files'   => $written,
            'snippet' => $snippet,
            'asset_version' => $assetVersion,
        ];
    }

    /**
     * Revert a single file from the most recent backup entry.
     * Returns true on success, false if no backup found.
     */
    public function revertLatest(string $absolutePath): bool
    {
        $this->enforceOwner();
        $db = \DB::getInstance();

        $row = $db->query(
            "SELECT id, content_backup FROM us_rebrand_file_backups WHERE file_path = ? ORDER BY id DESC LIMIT 1",
            [$absolutePath]
        )->first(true);

        if (!$row || empty($row['content_backup'])) {
            return false;
        }

        // Backup current before revert (chain backups are OK)
        if (is_file($absolutePath)) {
            $this->backupIfExists($absolutePath, "pre-revert current");
        }

        $this->atomicWrite($absolutePath, $row['content_backup']);
        return true;
    }

    /* ---------------------------------------------------------------------
     * Image generation helpers
     * ------------------------------------------------------------------- */

    protected function resizePngSquare(string $srcPng, string $destPng, int $size): void
    {
        if (class_exists('\Imagick')) {
            $im = new \Imagick();
            if (!$im->readImage($srcPng)) {
                throw new \Exception('Imagick failed to read master PNG.');
            }
            $im->setImageFormat('png');
            $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);

            $geo = $im->getImageGeometry();
            $w = (int)$geo['width'];
            $h = (int)$geo['height'];
            if ($w <= 0 || $h <= 0) {
                $im->clear(); $im->destroy();
                throw new \Exception('Invalid PNG dimensions.');
            }

            [$tw, $th] = $this->fitWithin($w, $h, $size, $size);
            $im->resizeImage($tw, $th, \Imagick::FILTER_LANCZOS, 1);

            $canvas = new \Imagick();
            $canvas->newImage($size, $size, new \ImagickPixel('transparent'), 'png');
            $x = (int)floor(($size - $tw) / 2);
            $y = (int)floor(($size - $th) / 2);
            $canvas->compositeImage($im, \Imagick::COMPOSITE_DEFAULT, $x, $y);

            $blob = $canvas->getImageBlob();
            $this->atomicWrite($destPng, $blob);

            $im->clear(); $im->destroy();
            $canvas->clear(); $canvas->destroy();
            return;
        }

        if (!extension_loaded('gd')) {
            throw new \Exception('Neither Imagick nor GD is available for image processing.');
        }

        $src = @imagecreatefrompng($srcPng);
        if (!$src) {
            throw new \Exception('GD failed to read master PNG.');
        }
        $w = imagesx($src);
        $h = imagesy($src);

        [$tw, $th] = $this->fitWithin($w, $h, $size, $size);
        $dst = imagecreatetruecolor($size, $size);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $size, $size, $transparent);

        $x = (int)floor(($size - $tw) / 2);
        $y = (int)floor(($size - $th) / 2);
        imagecopyresampled($dst, $src, $x, $y, 0, 0, $tw, $th, $w, $h);

        ob_start();
        $ok = imagepng($dst, null, 6);
        $blob = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        if (!$ok || $blob === false) {
            throw new \Exception('Failed to encode resized PNG.');
        }
        $this->atomicWrite($destPng, $blob);
    }

    /**
     * Pack an ICO file with PNG-compressed entries for sizes in $sizes.
     * Writes to $destIco.
     */
    protected function buildIcoFromPngs(string $dir, array $sizes, string $destIco): void
    {
        $entries = [];
        $dataBlob = '';

        foreach ($sizes as $sz) {
            $path = rtrim($dir, '/\\') . "/favicon-{$sz}x{$sz}.png";
            if (!file_exists($path)) {
                throw new \Exception("Missing PNG for ICO: {$path}");
            }
            $png = @file_get_contents($path);
            if ($png === false) {
                throw new \Exception("Failed to read PNG: {$path}");
            }
            $offset = 6 + (16 * count($sizes)) + strlen($dataBlob);
            $length = strlen($png);

            $entries[] = [
                'width'  => $sz === 256 ? 0 : $sz,
                'height' => $sz === 256 ? 0 : $sz,
                'colors' => 0,
                'reserved' => 0,
                'planes' => 1,
                'bitcount' => 32,
                'size' => $length,
                'offset' => $offset,
                'blob' => $png,
            ];
            $dataBlob .= $png;
        }

        $ico = pack('vvv', 0, 1, count($entries));

        foreach ($entries as $e) {
            $ico .= pack(
                'CCCCvvVV',
                $e['width'],
                $e['height'],
                $e['colors'],
                $e['reserved'],
                $e['planes'],
                $e['bitcount'],
                $e['size'],
                $e['offset']
            );
        }

        $ico .= $dataBlob;

        $this->atomicWrite($destIco, $ico);
    }

    /* ---------------------------------------------------------------------
     * Snippet builder
     * ------------------------------------------------------------------- */

    /**
     * Build the HEAD snippet using <?=$us_url_root?> and ?v=<asset_version>.
     * PWA lines are commented for future enablement.
     */
    protected function buildHeadSnippet(bool $includeMaskable, string $themeColor, string $assetVersion): string
    {
        $q = '?v=' . rawurlencode($assetVersion);

        // Build relative path (under users/images/rebrand/icons/), but hrefs are prefixed by <?=$us_url_root?>.
        $href = function (string $filename) use ($q): string {
            return '<?=$us_url_root?>users/images/rebrand/icons/' . ltrim($filename, '/') . $q;
        };

        $lines = [];

        // Basic favicons
        $lines[] = '<link rel="icon" type="image/x-icon" href="<?=$us_url_root?>users/images/rebrand/icons/favicon.ico' . $q . '">';
        $lines[] = '<link rel="icon" type="image/png" sizes="32x32" href="' . $href('favicon-32x32.png') . '">';
        $lines[] = '<link rel="icon" type="image/png" sizes="16x16" href="' . $href('favicon-16x16.png') . '">';

        // Apple touch icon
        $lines[] = '<link rel="apple-touch-icon" sizes="180x180" href="' . $href('favicon-180x180.png') . '">';

        // Android/Chrome high-res
        $lines[] = '<link rel="icon" type="image/png" sizes="192x192" href="' . $href('favicon-192x192.png') . '">';
        $lines[] = '<link rel="icon" type="image/png" sizes="256x256" href="' . $href('favicon-256x256.png') . '">';
        $lines[] = '<link rel="icon" type="image/png" sizes="384x384" href="' . $href('favicon-384x384.png') . '">';
        $lines[] = '<link rel="icon" type="image/png" sizes="512x512" href="' . $href('favicon-512x512.png') . '">';

        if ($includeMaskable) {
            $lines[] = '<!-- Maskable icon (for Android adaptive icons) -->';
            $lines[] = '<link rel="icon" type="image/png" sizes="512x512" href="' . $href('maskable-icon-512x512.png') . '" purpose="maskable">';
        }

        // PWA-related (COMMENTED OUT intentionally)
        $lines[] = '<!--';
        $lines[] = '  PWA manifest & theme-color (intentionally commented by ReBrand; enable when ready)';
        $lines[] = '  <link rel="manifest" href="<?=$us_url_root?>manifest.webmanifest' . $q . '">';
        if ($themeColor !== '') {
            $lines[] = '  <meta name="theme-color" content="' . htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8') . '">';
        } else {
            $lines[] = '  <!-- <meta name="theme-color" content="#111111"> -->';
        }
        $lines[] = '-->';

        return implode("\n", $lines) . "\n";
    }

    /* ---------------------------------------------------------------------
     * Utilities
     * ------------------------------------------------------------------- */

    /**
     * Enforce that the currently logged in user is id===1.
     */
    protected function enforceOwner(): void
    {
        global $user;
        if (!isset($user) || !is_object($user) || !$user->isLoggedIn()) {
            throw new \Exception('You must be logged in.');
        }
        $data = $user->data();
        $uid = is_object($data) ? ($data->id ?? null) : ($data['id'] ?? null);
        if ((int)$uid !== 1) {
            throw new \Exception('Insufficient privileges. Only user ID 1 may perform this action.');
        }
    }

    /**
     * Resolve canonical paths using $abs_us_root and $us_url_root.
     */
    protected function resolvedPaths(): array
    {
        global $abs_us_root, $us_url_root;

        if (empty($abs_us_root) || empty($us_url_root)) {
            throw new \Exception('UserSpice globals $abs_us_root / $us_url_root are not set.');
        }

        // Ensure trailing slash semantics
        $rootFs = rtrim($abs_us_root, '/\\');
        $rootUrl = rtrim($us_url_root, '/');

        $iconsDir = $rootFs . $rootUrl . '/users/images/rebrand/icons';
        $versionFile = $rootFs . $rootUrl . '/usersc/plugins/rebrand/.asset_version';

        return [
            'icons_dir'    => $iconsDir,
            'version_file' => $versionFile,
            'root_fs'      => $rootFs,
            'root_url'     => $rootUrl,
        ];
    }

    /**
     * Get next asset version (persistently increments).
     * Stored as plain text integer in .asset_version; file is backed up before write.
     */
    protected function nextAssetVersion(): string
    {
        $paths = $this->resolvedPaths();
        $vf = $paths['version_file'];

        $current = 0;
        if (is_file($vf)) {
            $raw = @file_get_contents($vf);
            if ($raw !== false) {
                $current = (int)trim($raw);
            }
        }

        $next = $current > 0 ? $current + 1 : (int)date('YmdHis');

        // Backup existing version file if present, then write new
        if (is_file($vf)) {
            $this->backupIfExists($vf, 'pre-increment asset_version');
        }
        $this->atomicWrite($vf, (string)$next);

        return (string)$next;  
    }

    /**
     * Insert a backup row for a file if it currently exists.
     */
    protected function backupIfExists(string $absolutePath, string $notes): void
    {
        if (!is_file($absolutePath)) {
            return;
        }
        $content = @file_get_contents($absolutePath);
        if ($content === false) {
            // If we cannot read, still insert a stub so there's a record.
            $content = '';
        }

        $db = \DB::getInstance();
        // Attempt to store; content may be large, ensure `content_backup` column is LONGTEXT in schema.
        $db->insert('us_rebrand_file_backups', [
            'file_path'      => $absolutePath,
            'content_backup' => $content,
            'notes'          => $notes . ' @ ' . date('c'),
        ]);
    }

    protected function fitWithin(int $w, int $h, int $maxW, int $maxH): array
    {
        $scale = min($maxW / max(1, $w), $maxH / max(1, $h));
        if ($scale >= 1) return [$w, $h];
        $tw = max(1, (int)floor($w * $scale));
        $th = max(1, (int)floor($h * $scale));
        return [$tw, $th];
    }

    /**
     * Atomic write with directory creation. Does NOT change permissions.
     * Will create parent directories as needed.
     */
    protected function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        $this->mkdirp($dir);

        $tmp = @tempnam($dir, '.rebrand.tmp.');
        if ($tmp === false) {
            throw new \Exception("Failed to create temp file in {$dir}");
        }
        $bytes = @file_put_contents($tmp, $content);
        if ($bytes === false) {
            @unlink($tmp);
            throw new \Exception("Failed to write to temp file: {$tmp}");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \Exception("Failed to move temp file into place: {$path}");
        }
    }

    protected function mkdirp(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \Exception("Failed to create directory: {$dir}");
            }
        }
    }
}
