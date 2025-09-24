<?php
namespace Rebrand;

class HeadTagsPatcher
{
    /** @var \DB */
    protected $db;
    protected string $fileBackupsTable;
    protected string $abs_us_root;
    protected string $us_url_root;

    public function __construct($db, string $fileBackupsTable, string $abs_us_root, string $us_url_root)
    {
        $this->db = $db;
        $this->fileBackupsTable = $fileBackupsTable;
        $this->usRoot = rtrim($abs_us_root, '/\\') . '/';
        $this->usUrlRoot = $us_url_root;
    }

    protected function headPath(): string
    {
        return $this->usRoot . 'usersc/includes/head_tags.php';
    }

    protected function ensureFileExists(): void
    {
        $path = $this->headPath();
        if (file_exists($path)) return;

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $boiler = <<<'PHP'
<?php if (!defined('INIT')) require_once __DIR__ . '/../../users/init.php'; ?>
<?php // UserSpice custom head tags ?>
<?php // Keep this file lightweight; plugin-safe. ?>

PHP;
        file_put_contents($path, $boiler);
        @chmod($path, 0644);
    }

    protected function backupFile(string $reason = 'edit'): void
    {
        $path = $this->headPath();
        $content = file_exists($path) ? file_get_contents($path) : '';
        $this->db->insert($this->fileBackupsTable, [
            'file_path'      => 'usersc/includes/head_tags.php',
            'content_backup' => $content,
            'notes'          => $reason,
        ]);
    }

    /** Convert {{root}} -> <?=$us_url_root?> and append ?v= */
    protected function normalizeSnippet(string $snippet, int $assetVer): string
    {
        $phpRoot = '<?=$us_url_root?>';
        $out = str_replace('{{root}}', $phpRoot, $snippet);

        // Append ?v=assetVer to href/src URLs that look like our assets and don't already have v=
        $out = preg_replace_callback(
            '#(?P<attr>\b(?:href|src)\s*=\s*["\'])(?P<url>[^"\']+)(["\'])#i',
            function ($m) use ($assetVer) {
                $url = $m['url'];
                if (preg_match('#[?&]v=\d+#', $url)) return $m[0];
                if (preg_match('#\.(?:png|jpg|jpeg|gif|ico|svg|webmanifest)(?:$|\?)#i', $url)) {
                    $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . $assetVer;
                }
                return $m['attr'] . $url . $m[3];
            },
            $out
        );

        return $out;
    }

    /** Read current meta/link values from the file */
    public function readCurrentMeta(): array
    {
        $this->ensureFileExists();
        $path = $this->headPath();
        $txt = file_get_contents($path) ?: '';

        $get = function(string $pattern, string $txt) {
            if (preg_match($pattern, $txt, $m)) {
                return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            return '';
        };

        return [
            'charset'       => $get('#<meta\s+charset=["\']([^"\']+)#i', $txt),
            'x_ua'         => $get('#<meta\s+http-equiv=["\']X-UA-Compatible["\']\s+content=["\']([^"\']+)#i', $txt),
            'description'  => $get('#<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)#i', $txt),
            'author'       => $get('#<meta\s+name=["\']author["\']\s+content=["\']([^"\']*)#i', $txt),
            'og_url'       => $get('#<meta\s+property=["\']og:url["\']\s+content=["\']([^"\']*)#i', $txt),
            'og_type'      => $get('#<meta\s+property=["\']og:type["\']\s+content=["\']([^"\']*)#i', $txt),
            'og_title'     => $get('#<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']*)#i', $txt),
            'og_desc'      => $get('#<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']*)#i', $txt),
            'og_image'     => $get('#<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']*)#i', $txt),
            'shortcut_icon'=> $get('#<link\s+rel=["\']shortcut icon["\']\s+href=["\']([^"\']+)#i', $txt),
        ];
    }

    /** Apply meta/link replacements in-place (backup first). */
    public function applyMeta(array $fields, int $assetVer): void
    {
        $this->ensureFileExists();
        $path = $this->headPath();
        $txt = file_get_contents($path) ?: '';

        $this->backupFile('applyMeta');

        $replaceContent = function(string $pattern, string $replacement, string $txt) {
            if (preg_match($pattern, $txt)) {
                return preg_replace($pattern, $replacement, $txt, 1);
            }
            // If missing, insert near top (after opening block or first PHP line)
            $lines = preg_split("/(\r\n|\n|\r)/", $txt);
            $insertAt = 0;
            if (!empty($lines)) {
                // after initial PHP open/guard lines
                foreach ($lines as $i => $line) {
                    if (strpos($line, '?>') !== false || stripos($line, '<meta') !== false) { $insertAt = $i + 1; break; }
                }
            }
            $ins = $replacement . "\n";
            array_splice($lines, $insertAt, 0, $ins);
            return implode("\n", $lines);
        };

        // Build replacements (content/href values)
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

        $og_url  = (string)($fields['og_url'] ?? '');
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

        // shortcut icon -> ensure ?v=assetVer appended
        $shortcut = (string)($fields['shortcut_icon'] ?? '<?=$us_url_root?>favicon.ico');
        if (!preg_match('#[?&]v=\d+$#', $shortcut)) {
            $shortcut .= (str_contains($shortcut, '?') ? '&' : '?') . 'v=' . $assetVer;
        }
        $txt = $replaceContent(
            '#<link\s+rel=["\']shortcut icon["\']\s+href=["\'][^"\']*["\']\s*/?>#i',
            '<link rel="shortcut icon" href="' . $shortcut . '">',
            $txt
        );

        // Write back
        $this->atomicWrite($this->headPath(), $txt);
    }

    /** Apply our snippet between markers; converts {{root}} to <?=$us_url_root?> */
    public function applySnippet(string $snippet, int $assetVer): void
    {
        $this->ensureFileExists();
        $path = $this->headPath();
        $txt = file_get_contents($path) ?: '';

        $this->backupFile('applySnippet');

        $start = '<!-- ReBrand START -->';
        $end   = '<!-- ReBrand END -->';

        $norm = $this->normalizeSnippet($snippet, $assetVer);
        $block = $start . "\n" . $norm . "\n" . $end;

        if (preg_match('#<!--\s*ReBrand\s+START\s*-->.*?<!--\s*ReBrand\s+END\s*-->#is', $txt)) {
            $txt = preg_replace('#<!--\s*ReBrand\s+START\s*-->.*?<!--\s*ReBrand\s+END\s*-->#is', $block, $txt, 1);
        } else {
            // append near end
            $txt .= "\n" . $block . "\n";
        }

        $this->atomicWrite($path, $txt);
    }

    public function diffAgainst(string $snippet): string
    {
        $this->ensureFileExists();
        $txt = file_get_contents($this->headPath()) ?: '';
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
        $o = explode("\n", $old);
        $n = explode("\n", $new);
        $out = [];
        $max = max(count($o), count($n));
        for ($i=0;$i<$max;$i++) {
            $ol = $o[$i] ?? '';
            $nl = $n[$i] ?? '';
            if ($ol === $nl) { $out[] = "  ".$ol; continue; }
            if ($ol !== '' && $nl === '') { $out[] = "- ".$ol; continue; }
            if ($ol === '' && $nl !== '') { $out[] = "+ ".$nl; continue; }
            $out[] = "- ".$ol;
            $out[] = "+ ".$nl;
        }
        return implode("\n", $out);
    }

    protected function atomicWrite(string $path, string $data): void
    {
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $data);
        @chmod($tmp, 0644);
        @rename($tmp, $path);
    }
}
