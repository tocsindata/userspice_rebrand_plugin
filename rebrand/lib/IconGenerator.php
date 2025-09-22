<?php
namespace Rebrand;

/**
 * IconGenerator
 *
 * Offline favicon & app-icon generator.
 * - Accepts a single master PNG (recommended ≥ 1024×1024).
 * - Emits:
 *    * ICO with 16/32/48/64px layers (PNG-compressed entries for modern browsers)
 *    * PNG set: 180, 192, 256, 384, 512 (plus 16/32/48/64 to match ICO)
 *    * Optional maskable variant(s)
 * - Produces a HEAD snippet with the proper <link> tags.
 *   PWA/manifest/splash lines are COMMENTED by default (future-ready).
 *
 * Prefers Imagick if available; otherwise uses GD.
 * No network calls. Fully local/offline.
 */
class IconGenerator
{
    /** Output sizes (PNG) beyond the smalls used in ICO */
    protected array $pngSizes = [16, 32, 48, 64, 180, 192, 256, 384, 512];

    /** ICO sizes (subset of pngSizes) */
    protected array $icoSizes = [16, 32, 48, 64];

    /**
     * Generate icons from a master PNG temp file into $outputDir.
     * Returns ['files' => [...], 'snippet' => '...'].
     *
     * Options:
     *  - include_maskable (bool): also write maskable-512.png
     *  - theme_color (string): hex color for commented meta/theme tags
     */
    public function generateFromMaster(string $masterPngTmp, string $outputDir, array $opts = []): array
    {
        $includeMaskable = !empty($opts['include_maskable']);
        $themeColor = trim($opts['theme_color'] ?? '');

        $this->mkdirp($outputDir);

        // Probe master
        $info = @getimagesize($masterPngTmp);
        if (!$info || empty($info[0]) || empty($info[1]) || ($info['mime'] ?? '') !== 'image/png') {
            throw new \Exception('Master image must be a valid PNG.');
        }
        [$mw, $mh] = [$info[0], $info[1]];
        if ($mw < 64 || $mh < 64) {
            throw new \Exception('Master PNG is too small. Provide at least 1024×1024 for best results.');
        }

        // Produce all PNG sizes
        $written = [];
        foreach ($this->pngSizes as $sz) {
            $path = rtrim($outputDir, '/\\') . "/favicon-{$sz}x{$sz}.png";
            $this->resizePngSquare($masterPngTmp, $path, $sz);
            $written[] = $path;
        }

        // Optional maskable variant (512)
        $maskPath = '';
        if ($includeMaskable) {
            $maskPath = rtrim($outputDir, '/\\') . "/maskable-icon-512x512.png";
            $this->resizePngSquare($masterPngTmp, $maskPath, 512);
            $written[] = $maskPath;
        }

        // Build ICO from 16/32/48/64 pngs (PNG-compressed ICO entries)
        $icoPath = rtrim($outputDir, '/\\') . '/favicon.ico';
        $this->buildIcoFromPngs($outputDir, $this->icoSizes, $icoPath);
        $written[] = $icoPath;

        // Build head snippet (PWA lines commented)
        $snippet = $this->buildHeadSnippet($icoPath, $outputDir, $includeMaskable, $themeColor);

        return [
            'files'   => $written,
            'snippet' => $snippet,
        ];
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

            // Fit within a square canvas of $size x $size, preserving aspect
            $geo = $im->getImageGeometry();
            $w = (int)$geo['width'];
            $h = (int)$geo['height'];
            if ($w <= 0 || $h <= 0) {
                $im->clear(); $im->destroy();
                throw new \Exception('Invalid PNG dimensions.');
            }

            [$tw, $th] = $this->fitWithin($w, $h, $size, $size);
            $im->resizeImage($tw, $th, \Imagick::FILTER_LANCZOS, 1);

            // Composite onto transparent square if not square already
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

        // GD path
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
            $offset = 6 + (16 * count($sizes)) + strlen($dataBlob); // header + dir entries + existing data
            $length = strlen($png);

            $entries[] = [
                'width'  => $sz === 256 ? 0 : $sz, // 0 denotes 256 in ICO spec
                'height' => $sz === 256 ? 0 : $sz,
                'colors' => 0,  // no palette
                'reserved' => 0,
                'planes' => 1,
                'bitcount' => 32,
                'size' => $length,
                'offset' => $offset,
                'blob' => $png,
            ];
            $dataBlob .= $png;
        }

        // ICO header: reserved(2)=0, type(2)=1 (icon), count(2)=n
        $ico = pack('vvv', 0, 1, count($entries));

        // Directory entries (16 bytes each)
        foreach ($entries as $e) {
            $ico .= pack(
                'CCCCvvVV',
                $e['width'],            // 1 byte (0==256)
                $e['height'],           // 1 byte (0==256)
                $e['colors'],           // 1 byte
                $e['reserved'],         // 1 byte
                $e['planes'],           // 2 bytes
                $e['bitcount'],         // 2 bytes
                $e['size'],             // 4 bytes
                $e['offset']            // 4 bytes
            );
        }

        // Append the PNG image data in sequence
        $ico .= $dataBlob;

        $this->atomicWrite($destIco, $ico);
    }

    /* ---------------------------------------------------------------------
     * Snippet builder
     * ------------------------------------------------------------------- */

    protected function buildHeadSnippet(string $icoPath, string $outputDir, bool $includeMaskable, string $themeColor = ''): string
    {
        // The outputDir will be served from users/images/rebrand/icons
        // We intentionally DO NOT add ?v= here — the caller will append asset_version when injecting.
        $rel = function (string $filename): string {
            // Return path relative to the usual location users/images/rebrand/icons
            return "users/images/rebrand/icons/" . ltrim($filename, '/');
        };

        $lines = [];

        // Basic favicons
        $lines[] = '<link rel="icon" type="image/x-icon" href="/favicon.ico">';
        $lines[] = '<link rel="icon" type="image/png" sizes="32x32" href="' . $rel('favicon-32x32.png') . '">';
        $lines[] = '<link rel="icon" type="image/png" sizes="16x16" href="' . $rel('favicon-16x16.png') . '">';

        // Apple touch icon
        $lines[] = '<link rel="apple-touch-icon" sizes="180x180" href="' . $rel('favicon-180x180.png') . '">';

        // Android/Chrome high-res
        $lines[] = '<link rel="icon" type="image/png" sizes="192x192" href="' . $rel('favicon-192x192.png') . '">';
        $lines[] = '<link rel="icon" type="image/png" sizes="256x256" href="' . $rel('favicon-256x256.png') . '">';
        $lines[] = '<link rel="icon" type="image/png" sizes="384x384" href="' . $rel('favicon-384x384.png') . '">';
        $lines[] = '<link rel="icon" type="image/png" sizes="512x512" href="' . $rel('favicon-512x512.png') . '">';

        if ($includeMaskable) {
            $lines[] = '<!-- Maskable icon (for Android adaptive icons) -->';
            $lines[] = '<link rel="icon" type="image/png" sizes="512x512" href="' . $rel('maskable-icon-512x512.png') . '" purpose="maskable">';
        }

        // PWA-related (COMMENTED OUT intentionally)
        $lines[] = '<!--';
        $lines[] = '  PWA manifest & theme-color (intentionally commented by ReBrand; enable when ready)';
        $lines[] = '  <link rel="manifest" href="/manifest.webmanifest">';
        if ($themeColor !== '') {
            $lines[] = '  <meta name="theme-color" content="' . htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8') . '">';
        } else {
            $lines[] = '  <!-- <meta name="theme-color" content="#111111"> -->';
        }
        $lines[] = '-->';

        // Combine
        return implode("\n", $lines) . "\n";
    }

    /* ---------------------------------------------------------------------
     * Utilities
     * ------------------------------------------------------------------- */

    protected function fitWithin(int $w, int $h, int $maxW, int $maxH): array
    {
        $scale = min($maxW / $w, $maxH / $h);
        if ($scale >= 1) return [$w, $h];
        $tw = max(1, (int)floor($w * $scale));
        $th = max(1, (int)floor($h * $scale));
        return [$tw, $th];
    }

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
