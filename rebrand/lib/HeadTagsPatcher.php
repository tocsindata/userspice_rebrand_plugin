<?php
namespace Rebrand;

/**
 * HeadTagsPatcher
 *
 * Safely injects favicon/head markup into usersc/includes/head_tags.php
 * using START/END markers, with timestamped backups and a simple diff.
 *
 * Markers:
 *   <!-- ReBrand START -->
 *   ... (plugin-managed content) ...
 *   <!-- ReBrand END -->
 */
class HeadTagsPatcher
{
    /** @var \DB */
    protected $db;

    /** @var string */
    protected $tableFileBackups;

    /** @var string Absolute path to usersc/includes/head_tags.php */
    protected $headPath;

    public function __construct($db, string $tableFileBackups, string $headPath)
    {
        $this->db = $db;
        $this->tableFileBackups = $tableFileBackups;
        $this->headPath = $headPath;
    }

    /**
     * Apply (insert/replace) the snippet between markers, after creating a backup.
     * Adds cache-busting query param (?v=<asset_version>) to common favicon/image href/src URLs.
     */
    public function apply(string $snippet, int $assetVersion): void
    {
        $current = $this->readFileOrScaffold();
        $withMarkers = $this->ensureMarkers($current);

        // Normalize/augment snippet: trim + ensure trailing newline
        $snippet = rtrim($snippet) . "\n";

        // Add cache-busting to typical favicon/icon URLs (non-destructive)
        $snippet = $this->appendVersionToAssetUrls($snippet, $assetVersion);

        // Prepare new content
        $pattern = $this->markersRegex();
        $replacement = "<!-- ReBrand START -->\n" . $snippet . "<!-- ReBrand END -->";
        $next = preg_replace($pattern, $replacement, $withMarkers);

        if ($next === null) {
            throw new \Exception('Failed to prepare patched head_tags.php content.');
        }

        // Backup current file before writing
        $this->backup($current, 'apply');

        // Atomic write
        $this->atomicWrite($this->headPath, $next);
    }

    /**
     * Show a lightweight diff between current marker content and what would be applied
     * (with cache-busting).
     */
    public function diff(string $candidateSnippet = null, int $assetVersion = null): string
    {
        $current = $this->readFileOrScaffold();
        $currBlock = $this->extractBlock($current);

        if ($candidateSnippet === null) {
            // If no candidate provided, just show what's inside markers now
            return $this->formatDiff($currBlock, $currBlock);
        }

        $cand = rtrim($candidateSnippet) . "\n";
        if ($assetVersion !== null) {
            $cand = $this->appendVersionToAssetUrls($cand, $assetVersion);
        }

        return $this->formatDiff($currBlock, $cand);
    }

    /**
     * Revert to the most recent backup for this file.
     */
    public function revertLastBackup(): bool
    {
        try {
            $row = $this->db->query(
                "SELECT * FROM `{$this->tableFileBackups}` WHERE `path` = ? ORDER BY `id` DESC LIMIT 1",
                [$this->headPath]
            )->first();
            if ($row && isset($row->content_backup)) {
                $this->atomicWrite($this->headPath, $row->content_backup);
                return true;
            }
        } catch (\Exception $e) {
            // swallow
        }
        return false;
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------- */

    protected function readFileOrScaffold(): string
    {
        if (!file_exists($this->headPath)) {
            $scaffold = <<<PHP
<?php
/**
 * usersc/includes/head_tags.php
 * This file is included in the <head> of your pages.
 * The ReBrand plugin will insert content BETWEEN its markers below.
 */
?>
<!-- ReBrand START -->
<!-- ReBrand END -->
PHP;
            $this->atomicWrite($this->headPath, $scaffold);
            return $scaffold;
        }
        $c = @file_get_contents($this->headPath);
        if ($c === false) {
            throw new \Exception("Unable to read {$this->headPath}");
        }
        return $c;
    }

    protected function ensureMarkers(string $contents): string
    {
        if (preg_match($this->markersRegex(), $contents)) {
            return $contents;
        }
        // Insert empty markers at end of file
        $append = "\n<!-- ReBrand START -->\n<!-- ReBrand END -->\n";
        return rtrim($contents) . "\n" . $append;
    }

    protected function markersRegex(): string
    {
        // Non-greedy match between START and END
        return '/<!--\s*ReBrand\s+START\s*-->.*?<!--\s*ReBrand\s+END\s*-->/is';
    }

    protected function extractBlock(string $contents): string
    {
        if (preg_match($this->markersRegex(), $contents, $m)) {
            $block = $m[0];
            // Strip markers for diff payload
            $inner = preg_replace('/^.*?START\s*-->\s*/is', '', $block);
            $inner = preg_replace('/\s*<!--\s*ReBrand\s+END\s*-->$/is', '', $inner);
            return ltrim((string)$inner);
        }
        return "";
    }

    protected function backup(string $content, string $notes = ''): void
    {
        try {
            $this->db->insert($this->tableFileBackups, [
                'path'           => $this->headPath,
                'content_backup' => $content,
                'notes'          => $notes,
            ]);
        } catch (\Exception $e) {
            // If backup fails, we refuse to proceed to avoid destructive overwrite.
            throw new \Exception('Failed to record backup for head_tags.php: ' . $e->getMessage());
        }
    }

    /**
     * Append ?v=<assetVersion> to common asset href/src occurrences if not already present.
     */
    protected function appendVersionToAssetUrls(string $html, int $assetVersion): string
    {
        $v = (int)$assetVersion;

        // Only add to our known paths:
        //  - /favicon.ico
        //  - users/images/rebrand/icons/*.png
        //  - users/images/rebrand/logo*.png (if referenced in head)
        // Avoid double-appending when ?v= already present.
        $patterns = [
            // href="/favicon.ico"
            '~(href\s*=\s*["\'])(/favicon\.ico)(["\'])~i',
            // users/images/rebrand/icons/<file>.png
            '~((?:href|src)\s*=\s*["\'])(users/images/rebrand/icons/[^"\']+?\.png)(["\'])~i',
            // optional logo refs in head
            '~((?:href|src)\s*=\s*["\'])(users/images/rebrand/logo[^"\']*?\.png)(["\'])~i',
        ];

        foreach ($patterns as $rx) {
            $html = preg_replace_callback($rx, function ($m) use ($v) {
                $prefix = $m[1];
                $url = $m[2];
                $quote = $m[3];
                if (strpos($url, '?v=') !== false) {
                    return $m[0]; // already versioned
                }
                $sep = (strpos($url, '?') === false) ? '?' : '&';
                return $prefix . $url . $sep . 'v=' . $v . $quote;
            }, $html);
        }

        return $html;
    }

    protected function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \Exception("Failed to create directory: {$dir}");
            }
        }
        $tmp = @tempnam($dir, '.rebrand.tmp.');
        if ($tmp === false) {
            throw new \Exception("Failed to create temp file in {$dir}");
        }
        $bytes = @file_put_contents($tmp, $content);
        if ($bytes === false) {
            @unlink($tmp);
            throw new \Exception("Failed to write temporary content.");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \Exception("Failed to move temp file into place: {$path}");
        }
    }

    /**
     * Basic unified-like diff (line-by-line). Returns a readable text block.
     */
    protected function formatDiff(string $old, string $new): string
    {
        $a = explode("\n", (string)$old);
        $b = explode("\n", (string)$new);

        $out = [];
        $max = max(count($a), count($b));
        for ($i = 0; $i < $max; $i++) {
            $la = $a[$i] ?? '';
            $lb = $b[$i] ?? '';
            if ($la === $lb) {
                $out[] = '  ' . $la;
            } else {
                if ($la !== '') $out[] = '- ' . $la;
                if ($lb !== '') $out[] = '+ ' . $lb;
            }
        }
        return implode("\n", $out);
    }
}
