<?php
namespace Rebrand;

use Exception;

class HeadTagsPatcher
{
    private const BEGIN = '<!-- REBRAND:BEGIN META -->';
    private const END   = '<!-- REBRAND:END META -->';

    /**
     * Apply meta changes to usersc/includes/head_tags.php.
     * @param array $meta Keys (all optional except where your UI enforces):
     *   - description
     *   - author
     *   - og_url        (absolute URL; if omitted, runtime <?=$us_url_root?> fallback is used)
     *   - og_type       (default: website if omitted)
     *   - og_title
     *   - og_description
     *   - og_image      (absolute OR relative-to-webroot path, e.g. "users/images/rebrand/icons/512.png")
     */
    public function apply(array $meta): array
    {
        // Only User ID 1
        global $user;
        $uid = $user->data()->id ?? null;
        if ((int)$uid !== 1) {
            throw new Exception('HeadTagsPatcher: unauthorized (requires User ID 1).');
        }

        // Resolve exact file path (no fallbacks).
        [$abs, $web] = $this->roots();
        $target = $abs.$web.'usersc/includes/head_tags.php';

        if (!is_file($target)) {
            throw new Exception("HeadTagsPatcher: missing file {$target}");
        }
        if (!is_readable($target) || !is_writable($target)) {
            throw new Exception("HeadTagsPatcher: file not readable/writable {$target}");
        }

        $orig = (string)file_get_contents($target);

        $block = $this->buildMetaBlock($meta);
        $updated = $this->injectBlock($orig, $block);

        if ($updated === $orig) {
            return ['updated' => false, 'message' => 'No changes needed (already current).', 'file' => $target];
        }

        // Backup then write
        $this->backupFile($target, $orig, 'Backup before ReBrand head meta write');
        $this->atomicWrite($target, $updated);

        return ['updated' => true, 'file' => $target];
    }

    private function buildMetaBlock(array $meta): string
    {
        $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $description   = isset($meta['description'])    ? (string)$meta['description']    : '';
        $author        = isset($meta['author'])         ? (string)$meta['author']         : '';
        $og_url        = isset($meta['og_url'])         ? (string)$meta['og_url']         : null; // null -> runtime fallback
        $og_type       = isset($meta['og_type'])        ? (string)$meta['og_type']        : 'website';
        $og_title      = isset($meta['og_title'])       ? (string)$meta['og_title']       : '';
        $og_desc       = isset($meta['og_description']) ? (string)$meta['og_description'] : '';
        $og_image      = isset($meta['og_image'])       ? (string)$meta['og_image']       : '';

        $lines = [];
        $lines[] = self::BEGIN;

        $lines[] = '<meta http-equiv="X-UA-Compatible" content="IE=edge">';
        $lines[] = '<meta name="description" content="'.$esc($description).'">';
        $lines[] = '<meta name="author" content="'.$esc($author).'">';

        if ($og_url) {
            $lines[] = '<meta property="og:url" content="'.$esc(rtrim($og_url, '/')).'">';
        } else {
            // Exact runtime fallback using the real US var (NOT inside a string).
            $lines[] = '<?php /* ReBrand og:url fallback */ ?>';
            $lines[] = '<meta property="og:url" content="<?='.'$us_url_root'.'?>">';
        }

        $lines[] = '<meta property="og:type" content="'.$esc($og_type).'">';
        $lines[] = '<meta property="og:title" content="'.$esc($og_title).'">';
        $lines[] = '<meta property="og:description" content="'.$esc($og_desc).'">';

        if ($og_image !== '') {
            if (preg_match('#^https?://#i', $og_image)) {
                $lines[] = '<meta property="og:image" content="'.$esc($og_image).'">';
            } else {
                // Relative path: use $us_url_root at runtime, not inside a string.
                $rel = ltrim($og_image, '/');
                $lines[] = '<?php /* ReBrand og:image from relative path */ ?>';
                $lines[] = '<meta property="og:image" content="<?='.'rtrim($us_url_root, "/")'.'."/' . $esc($rel) . '"?>">';
            }
        } else {
            // Still emit the tag with empty content for determinism (since you said these must be used).
            $lines[] = '<meta property="og:image" content="">';
        }

        $lines[] = self::END;

        return implode("\n", $lines)."\n";
    }

    private function injectBlock(string $orig, string $block): string
    {
        $begin = preg_quote(self::BEGIN, '/');
        $end   = preg_quote(self::END, '/');
        $pattern = "/{$begin}.*?{$end}\n?/s";

        if (preg_match($pattern, $orig)) {
            return preg_replace($pattern, $block, $orig, 1);
        }

        // If there’s a </head>, place our block right before it; otherwise append.
        if (preg_match('/<\/head>/i', $orig)) {
            return preg_replace('/<\/head>/i', $block."</head>", $orig, 1);
        }
        return rtrim($orig)."\n\n".$block;
    }

    private function backupFile(string $path, string $content, string $notes): void
    {
        $db = \DB::getInstance();
        $db->insert('us_rebrand_file_backups', [
            'file_path'      => $path,
            'content_backup' => $content,
            'notes'          => $notes,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    private function atomicWrite(string $path, string $content): void
    {
        $tmp = $path.'.rebrand.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new Exception("HeadTagsPatcher: failed to write temp file {$tmp}");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new Exception("HeadTagsPatcher: failed to replace {$path}");
        }
    }

    private function roots(): array
    {
        // Use exact US globals as-is
        global $abs_us_root, $us_url_root;

        if (!isset($abs_us_root) || !isset($us_url_root)) {
            throw new Exception('HeadTagsPatcher: required globals $abs_us_root / $us_url_root not set.');
        }
        return [$abs_us_root, $us_url_root];
    }
}
