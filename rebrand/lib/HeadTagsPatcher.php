<?php
namespace Rebrand;

use DB;

/**
 * HeadTagsPatcher
 *
 * NOTES:
 * - Filesystem paths are built from $abs_us_root.$us_url_root.'…'
 * - URL strings that land in PHP files must literally use <?=$us_url_root?>.
 * - All writes create a backup row in `us_rebrand_file_backups` first.
 * - Only user ID 1 may perform mutating operations.
 */
class HeadTagsPatcher
{
    /** Tables */
    protected string $fileBackupsTable = 'us_rebrand_file_backups';

    /** Resolved from UserSpice globals */
    protected string $abs_us_root;
    protected string $us_url_root;

    public function __construct()
    {
        // Pull required US globals without inventing alternates
        global $abs_us_root, $us_url_root;

        $this->abs_us_root = rtrim((string)$abs_us_root, "/\\") . '/';
        $this->us_url_root = rtrim((string)$us_url_root, "/") . '/';
    }

    /** Absolute filesystem path to usersc/includes/head_tags.php */
    protected function headPath(): string
    {
        // Real path rule per project: build from $abs_us_root.$us_url_root.'…'
        // e.g. /var/www/html/ + /users/ => /var/www/html//users/… (double slash safe)
        return $this->abs_us_root . ltrim($this->us_url_root, '/')
             . 'usersc/includes/head_tags.php';
    }

    /** Plugin Manager return URL */
    protected function returnUrl(): string
    {
        return $this->us_url_root . 'users/admin.php?view=plugins_config&plugin=rebrand';
    }

    /** Only user id 1 can mutate */
    protected function requireOwner(): void
    {
        global $user;
        if (empty($user) || !is_object($user) || !isset($user->data()->id) || (int)$user->data()->id !== 1) {
            throw new \RuntimeException('Access denied. Only user ID 1 may perform this action.');
        }
    }

    /** Ensure the head file exists on disk (owner-gated because we might write) */
    protected function ensureFileExists(string $reasonForBackup = 'create if missing'): void
    {
        $path = $this->headPath();
        if (file_exists($path)) {
            return;
        }

        $this->requireOwner();

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Minimal, plugin-safe boilerplate. Keep URL references using <?=$us_url_root?>.
        $boiler = <<<'PHP'
<?php if (!defined('INIT')) require_once __DIR__ . '/../../users/init.php'; ?>
<?php // UserSpice custom head tags (managed by Rebrand plugin). ?>
PHP;

        // Record a "pre-create" backup row with empty content so revert has history.
        $this->backupFile($reasonForBackup);

        $this->atomicWrite($path, $boiler . "\n");
        @chmod($path, 0644);
    }

    /** Insert a single file backup row */
    protected function backupFile(string $notes = 'edit'): void
    {
        $path = $this->headPath();
        $content = file_exists($path) ? (string)file_get_contents($path) : '';

        /** @var DB $db */
        $db = DB::getInstance();
        $db->insert($this->fileBackupsTable, [
            'file_path'      => 'usersc/includes/head_tags.php',
            'content_backup' => $content,
            'notes'          => $notes,
        ]);
    }

    /**
     * Revert the latest backup for head_tags.php
     * Returns true on success, false if no backup found.
     */
    public function revertLatest(): bool
    {
        $this->requireOwner();
        $this->ensureFileExists('revert bootstrap');

        /** @var DB $db */
        $db = DB::getInstance();

        $row = $db->query(
            "SELECT id, content_backup FROM {$this->fileBackupsTable}
             WHERE file_path = ? ORDER BY id DESC LIMIT 1",
            ['usersc/includes/head_tags.php']
        )->first();

        if (!$row) {
            return false;
        }

        $this->backupFile('pre-revert safeguard'); // backup current before revert
        $this->atomicWrite($this->headPath(), (string)$row->content_backup);

        return true;
    }

    /**
     * Convert {{root}} -> <?=$us_url_root?> and append ?v=<assetVer>
     * to common static assets we control (images, icons, css, js, webmanifest).
     */
    protected function normalizeSnippet(string $snippet, int $assetVer): string
    {
        $phpRootLiteral = '<?=$us_url_root?>';
        $out = str_replace('{{root}}', $phpRootLiteral, $snippet);

        // Add ?v= cache buster to typical assets if not already present.
        $out = preg_replace_callback(
            '#(?P<attr>\b(?:href|src)\s*=\s*["\'])(?P<url>[^"\']+)(["\'])#i',
            function ($m) use ($assetVer) {
                $url = $m['url'];

                // Already versioned?
                if (preg_match('#(?:^|[?&])v=\d+#i', $url)) {
                    return $m[0];
                }

                // Version the assets we typically control in head.
                if (preg_match('#\.(?:png|jpg|jpeg|gif|ico|svg|webmanifest|css|js)(?:$|\?)#i', $url)) {
                    $url .= (strpos($url, '?') !== false ? '&' : '?') . 'v=' . (int)$assetVer;
                }

                return $m['attr'] . $url . $m[3];
            },
            $out
        );

        return $out;
    }

    /** Read current meta/link values from the file (non-mutating) */
    public function readCurrentMeta(): array
    {
        $this->ensureFileExists('ensure for read');
        $txt = (string)file_get_contents($this->headPath());

        $get = function (string $pattern, string $txt) {
            if (preg_match($pattern, $txt, $m)) {
                return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            return '';
        };

        return [
            'charset'        => $get('#<meta\s+charset=["\']([^"\']+)#i', $txt),
            'x_ua'           => $get('#<meta\s+http-equiv=["\']X-UA-Compatible["\']\s+content=["\']([^"\']+)#i', $txt),
            'description'    => $get('#<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)#i', $txt),
            'author'         => $get('#<meta\s+name=["\']author["\']\s+content=["\']([^"\']*)#i', $txt),
            'og_url'         => $get('#<meta\s+property=["\']og:url["\']\s+content=["\']([^"\']*)#i', $txt),
            'og_type'        => $get('#<meta\s+property=["\']og:type["\']\s+content=["\']([^"\']*)#i', $txt),
            'og_title'       => $get('#<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']*)#i', $txt),
            'og_desc'        => $get('#<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']*)#i', $txt),
            'og_image'       => $get('#<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']*)#i', $txt),
            'shortcut_icon'  => $get('#<link\s+rel=["\']shortcut icon["\']\s+href=["\']([^"\']+)#i', $txt),
        ];
    }

    /**
     * Apply meta/link replacements in-place (backup first).
     * @throws \RuntimeException on permission or IO error.
     */
    public function applyMeta(array $fields, int $assetVer): void
    {
        try {
            $this->requireOwner();
            $this->ensureFileExists('applyMeta bootstrap');

            $path = $this->headPath();
            $txt  = (string)file_get_contents($path);

            $this->backupFile('applyMeta');

            $replaceContent = function (string $pattern, string $replacement, string $txt) {
                if (preg_match($pattern, $txt)) {
                    return preg_replace($pattern, $replacement, $txt, 1);
                }
                // Insert near top (after first closing PHP tag or first meta)
                $lines = preg_split("/(\r\n|\n|\r)/", $txt);
                $insertAt = 0;
                if (!empty($lines)) {
                    foreach ($lines as $i => $line) {
                        if (strpos($line, '?>') !== false || stripos($line, '<meta') !== false) {
                            $insertAt = $i + 1;
                            break;
                        }
                    }
                }
                array_splice($lines, $insertAt, 0, $replacement);
                return implode("\n", $lines);
            };

            $charset = trim($fields['charset'] ?? '');
            if ($charset !== '') {
                $txt = $replaceContent(
                    '#<meta\s+charset=["\'][^"\']+["\']\s*/?>#i',
                    '<meta charset="' . htmlspecialchars($charset, ENT_QUOTES, 'UTF-8') . '">',
                    $txt
                );
            }

            $xua = trim($fields['x_ua'] ?? '');
            if ($xua !== '') {
                $txt = $replaceContent(
                    '#<meta\s+http-equiv=["\']X-UA-Compatible["\']\s+content=["\'][^"\']*["\']\s*/?>#i',
                    '<meta http-equiv="X-UA-Compatible" content="' . htmlspecialchars($xua, ENT_QUOTES, 'UTF-8') . '">',
                    $txt
                );
            }

            $desc = (string)($fields['description'] ?? '');
            $txt = $replaceContent(
                '#<meta\s+name=["\']description["\']\s+content=["\'][^"\']*["\']\s*/?>#i',
                '<meta name="description" content="' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '">',
                $txt
            );

            $author = (string)($fields['author'] ?? '');
            $txt = $replaceContent(
                '#<meta\s+name=["\']author["\']\s+content=["\'][^"\']*["\']\s*/?>#i',
                '<meta name="author" content="' . htmlspecialchars($author, ENT_QUOTES, 'UTF-8') . '">',
                $txt
            );

            $og_url = (string)($fields['og_url'] ?? '');
            $txt = $replaceContent(
                '#<meta\s+property=["\']og:url["\']\s+content=["\'][^"\']*["\']\s*/?>#i',
                '<meta property="og:url" content="' . htmlspecialchars($og_url, ENT_QUOTES, 'UTF-8') . '">',
                $txt
            );

            $og_type = (string)($fields['og_type'] ?? 'website');
            $txt = $replaceContent(
                '#<meta\s+property=["\']og:type["\']\s+content=["\'][^"\']*["\']\s*/?>#i',
                '<meta property="og:type" content="' . htmlspecialchars($og_type, ENT_QUOTES, 'UTF-8') . '">',
                $txt
            );

            $og_title = (string)($fields['og_title'] ?? '');
            $txt = $replaceContent(
                '#<meta\s+property=["\']og:title["\']\s+content=["\'][^"\']*["\']\s*/?>#i',
                '<meta property="og:title" content="' . htmlspecialchars($og_title, ENT_QUOTES, 'UTF-8') . '">',
                $txt
            );

            $og_desc = (string)($fields['og_desc'] ?? '');
            $txt = $replaceContent(
                '#<meta\s+property=["\']og:description["\']\s+content=["\'][^"\']*["\']\s*/?>#i',
                '<meta property="og:description" content="' . htmlspecialchars($og_desc, ENT_QUOTES, 'UTF-8') . '">',
                $txt
            );

            $og_image = (string)($fields['og_image'] ?? '');
            $txt = $replaceContent(
                '#<meta\s+property=["\']og:image["\']\s+content=["\'][^"\']*["\']\s*/?>#i',
                '<meta property="og:image" content="' . htmlspecialchars($og_image, ENT_QUOTES, 'UTF-8') . '">',
                $txt
            );

            // shortcut icon (ensure versioning)
            $shortcut = (string)($fields['shortcut_icon'] ?? '<?=$us_url_root?>favicon.ico');
            if (!preg_match('#(?:^|[?&])v=\d+$#', $shortcut)) {
                $shortcut .= (strpos($shortcut, '?') !== false ? '&' : '?') . 'v=' . (int)$assetVer;
            }
            $txt = $replaceContent(
                '#<link\s+rel=["\']shortcut icon["\']\s+href=["\'][^"\']*["\']\s*/?>#i',
                '<link rel="shortcut icon" href="' . $shortcut . '">',
                $txt
            );

            $this->atomicWrite($path, $txt);
        } catch (\Throwable $e) {
            // Surface a clean message; caller can catch and redirect.
            throw new \RuntimeException(
                'Head meta update failed: ' . $e->getMessage() .
                ' | Return: ' . $this->returnUrl()
            );
        }
    }

    /**
     * Apply a custom snippet between markers (backup first).
     * {{root}} inside $snippet becomes <?=$us_url_root?> and assets get ?v=
     * @throws \RuntimeException on permission or IO error.
     */
    public function applySnippet(string $snippet, int $assetVer): void
    {
        try {
            $this->requireOwner();
            $this->ensureFileExists('applySnippet bootstrap');

            $path = $this->headPath();
            $txt  = (string)file_get_contents($path);

            $this->backupFile('applySnippet');

            $start = '<!-- ReBrand START -->';
            $end   = '<!-- ReBrand END -->';

            $norm  = $this->normalizeSnippet($snippet, $assetVer);
            $block = $start . "\n" . $norm . "\n" . $end;

            if (preg_match('#<!--\s*ReBrand\s+START\s*-->.*?<!--\s*ReBrand\s+END\s*-->#is', $txt)) {
                $txt = preg_replace('#<!--\s*ReBrand\s+START\s*-->.*?<!--\s*ReBrand\s+END\s*-->#is', $block, $txt, 1);
            } else {
                $txt .= (substr($txt, -1) === "\n" ? '' : "\n") . $block . "\n";
            }

            $this->atomicWrite($path, $txt);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Snippet apply failed: ' . $e->getMessage() .
                ' | Return: ' . $this->returnUrl()
            );
        }
    }

    /** Return a simple unified diff between the current block and a proposed snippet (non-mutating) */
    public function diffAgainst(string $snippet): string
    {
        $this->ensureFileExists('diff ensure');
        $txt  = (string)file_get_contents($this->headPath());
        $have = $this->extractBlock($txt);
        return $this->unifiedDiff($have, $snippet);
    }

    protected function extractBlock(string $txt): string
    {
        if (preg_match('#<!--\s*ReBrand\s+START\s*-->(.*?)<!--\s*ReBrand\s+END\s*-->#is', $txt, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    protected function unifiedDiff(string $old, string $new): string
    {
        $o = explode("\n", (string)$old);
        $n = explode("\n", (string)$new);
        $out = [];
        $max = max(count($o), count($n));
        for ($i = 0; $i < $max; $i++) {
            $ol = $o[$i] ?? '';
            $nl = $n[$i] ?? '';
            if ($ol === $nl) { $out[] = "  " . $ol; continue; }
            if ($ol !== '' && $nl === '') { $out[] = "- " . $ol; continue; }
            if ($ol === '' && $nl !== '') { $out[] = "+ " . $nl; continue; }
            $out[] = "- " . $ol;
            $out[] = "+ " . $nl;
        }
        return implode("\n", $out);
    }

    /** Atomic write helper (owner-gated via the callers that mutate) */
    protected function atomicWrite(string $path, string $data): void
    {
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $data) === false) {
            throw new \RuntimeException('Failed to write temporary file for atomic save.');
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to move temporary file into place.');
        }
    }
}
