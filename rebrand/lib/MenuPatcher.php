<?php
namespace Rebrand;

/**
 * MenuPatcher (UserSpice-specific: us_menus.brand_html)
 *
 * Safely injects/updates the logo & social-links block inside the
 * UserSpice `us_menus`.`brand_html` field for the specified menu IDs.
 *
 * - Writes ONLY between <!-- ReBrand START --> and <!-- ReBrand END --> markers.
 * - Keeps row-level backups in us_rebrand_menu_backups.
 * - Provides discovery & simple diff helpers.
 *
 * NOTE:
 *   Your SQL shows branding is stored in `us_menus.brand_html`.
 *   This class is now locked to that schema (no auto-detection).
 */
class MenuPatcher
{
    /** @var \DB */
    protected $db;

    /** @var string */
    protected $tableBackups;

    /** Fixed schema (from your CREATE TABLE) */
    protected string $menuTable   = 'us_menus';
    protected string $contentCol  = 'brand_html';
    protected string $nameCol     = 'menu_name';
    protected string $pkCol       = 'id';

    public function __construct($db, string $tableBackups = 'us_rebrand_menu_backups')
    {
        $this->db = $db;
        $this->tableBackups = $tableBackups;
    }

    /* ---------------------------------------------------------------------
     * Public API
     * ------------------------------------------------------------------- */

    /**
     * Attempt to find likely menus whose brand_html already contains branding or socials.
     * Returns an array of menu IDs.
     */
    public function discoverCandidates(int $limit = 10): array
    {
        $ids = [];
        $hints = [
            'logo', 'brand', 'branding', 'social',
            'twitter', 'x.com', 'facebook', 'linkedin', 'github', 'youtube', 'instagram',
            'navbar-brand', 'site-logo', 'fa-twitter', 'fa-facebook', 'fa-linkedin', 'fa-github', 'fa-youtube', 'fa-instagram',
            'ReBrand START',
        ];
        $seen = [];

        foreach ($hints as $h) {
            $sql = "SELECT `{$this->pkCol}` AS id FROM `{$this->menuTable}` WHERE `{$this->contentCol}` LIKE ? LIMIT ?";
            $rows = $this->db->query($sql, ['%' . $h . '%', $limit])->results();
            foreach ($rows as $r) {
                $rid = (int)$r->id;
                if (!isset($seen[$rid])) {
                    $seen[$rid] = true;
                    $ids[] = $rid;
                }
                if (count($ids) >= $limit) break 2;
            }
        }
        return $ids;
    }

    /**
     * Apply or update the plugin block to the given **menu IDs**.
     *
     * $context:
     *   - logo_path (string, relative to web root)
     *   - logo_dark (string|empty)
     *   - asset_ver (int)
     *   - social_links (array key=>['enabled'=>bool,'url'=>string,'order'=>int])
     *   - favicon_root (string, relative path to icons folder)  // not currently used in brand_html
     */
    public function apply(array $menuIds, array $context): void
    {
        $menuIds = array_values(array_unique(array_map('intval', $menuIds)));
        if (empty($menuIds)) return;

        $block = $this->buildInjectedBlock($context);

        foreach ($menuIds as $id) {
            $row = $this->getRow($id);
            if (!$row) continue;

            $current = (string)$row->{$this->contentCol};

            // Backup before change
            $this->backupRow($id, (string)$row->{$this->nameCol}, $current, 'apply');

            $next = $this->upsertBlock($current, $block);
            if ($next !== $current) {
                $this->updateRow($id, $next);
            }
        }
    }

    /**
     * Produce a simple diff for the specified IDs (shows the *current* inner block).
     */
    public function diff(array $menuIds): string
    {
        $menuIds = array_values(array_unique(array_map('intval', $menuIds)));
        if (empty($menuIds)) return 'No targets provided.';

        $out = [];
        foreach ($menuIds as $id) {
            $row = $this->getRow($id);
            if (!$row) continue;
            $curr = (string)$row->{$this->contentCol};
            $inner = $this->extractInner($curr);
            $out[] = "=== us_menus.id {$id} ({$row->{$this->nameCol}}) ===";
            $out[] = $this->formatDiff($inner, $inner); // placeholder for now
        }
        return implode("\n", $out);
    }

    /**
     * Revert the most recent backup set (last N rows inserted).
     * Returns true if any row restored.
     */
    public function revertLastBackups(int $max = 50): bool
    {
        $rows = $this->db->query("SELECT * FROM `{$this->tableBackups}` ORDER BY `id` DESC LIMIT {$max}")->results();
        $any = false;
        foreach ($rows as $b) {
            $id = (int)($b->menu_item_id ?? 0);
            $content = (string)$b->content_backup;
            if ($id > 0 && $content !== '') {
                $this->updateRow($id, $content);
                $any = true;
            }
        }
        return $any;
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------- */

    protected function getRow(int $id): ?object
    {
        $sql = "SELECT `{$this->pkCol}`, `{$this->nameCol}`, `{$this->contentCol}` FROM `{$this->menuTable}` WHERE `{$this->pkCol}` = ? LIMIT 1";
        $row = $this->db->query($sql, [$id])->first();
        return $row ?: null;
    }

    protected function updateRow(int $id, string $content): void
    {
        $this->db->update($this->menuTable, $id, [
            $this->contentCol => $content,
        ]);
    }

    protected function backupRow(int $menuId, ?string $menuName, string $content, string $notes = ''): void
    {
        try {
            $this->db->insert($this->tableBackups, [
                'menu_id'        => $menuId,
                'menu_item_id'   => $menuId, // using same field for compatibility with earlier schema
                'menu_name'      => $menuName,
                'content_backup' => $content,
                'notes'          => $notes,
            ]);
        } catch (\Exception $e) {
            throw new \Exception('MenuPatcher: failed to record backup for menu ' . $menuId . ': ' . $e->getMessage());
        }
    }

    /**
     * Insert or replace the plugin block inside START/END markers.
     * If no markers exist yet, we append the block to the end of brand_html.
     */
    protected function upsertBlock(string $current, string $block): string
    {
        $pattern = $this->markersRegex();
        $replacement = "<!-- ReBrand START -->\n{$block}\n<!-- ReBrand END -->";

        if (preg_match($pattern, $current)) {
            return (string)preg_replace($pattern, $replacement, $current);
        }

        $sep = (substr($current, -1) === "\n") ? "" : "\n";
        return $current . $sep . $replacement . "\n";
    }

    /**
     * Build the injected HTML block for logo + social icons.
     * Uses cache-busted URLs via ?v=asset_ver.
     */
    protected function buildInjectedBlock(array $ctx): string
    {
        $logo = ltrim((string)($ctx['logo_path'] ?? 'users/images/rebrand/logo.png'), '/');
        $logoDark = trim((string)($ctx['logo_dark'] ?? ''));
        $ver = (int)($ctx['asset_ver'] ?? 1);
        $social = (array)($ctx['social_links'] ?? []);

        // Sort socials by 'order'
        uasort($social, function ($a, $b) {
            return (int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0);
        });

        // Brand HTML often uses {{root}} in your data; we keep absolute-root-friendly URLs (leading /)
        $logoUrl = '/' . $this->verUrl($logo, $ver);
        $logoDarkUrl = $logoDark !== '' ? '/' . $this->verUrl($logoDark, $ver) : '';

        $lines = [];
        $lines[] = '<div class="rebrand-header-block" style="display:flex;align-items:center;gap:1rem;">';
        $lines[] = '  <div class="rebrand-logo">';
        if ($logoDarkUrl !== '') {
            $lines[] = '    <picture>';
            $lines[] = '      <source media="(prefers-color-scheme: dark)" srcset="' . htmlspecialchars($logoDarkUrl, ENT_QUOTES, 'UTF-8') . '">';
            $lines[] = '      <img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="Logo" class="img-fluid" style="max-height:48px">';
            $lines[] = '    </picture>';
        } else {
            $lines[] = '    <img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="Logo" class="img-fluid" style="max-height:48px">';
        }
        $lines[] = '  </div>';

        // Social links
        $lines[] = '  <div class="rebrand-socials" style="display:flex; gap:.75rem; align-items:center;">';
        foreach ($social as $key => $cfg) {
            if (empty($cfg['enabled'])) continue;
            $url = trim((string)($cfg['url'] ?? ''));
            if ($url === '') continue;
            $label = $this->labelForPlatform($key);
            $icon = $this->iconForPlatform($key); // minimal fallback; site may have fontawesome already
            $lines[] = '    <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">' . $icon . '</a>';
        }
        $lines[] = '  </div>';
        $lines[] = '</div>';

        return implode("\n", $lines);
    }

    protected function labelForPlatform(string $key): string
    {
        $map = [
            'x' => 'X (Twitter)',
            'facebook' => 'Facebook',
            'linkedin' => 'LinkedIn',
            'github' => 'GitHub',
            'youtube' => 'YouTube',
            'instagram' => 'Instagram',
        ];
        return $map[$key] ?? ucfirst($key);
    }

    protected function iconForPlatform(string $key): string
    {
        // Simple placeholders; projects often use <i class="fa-brands fa-...">
        $map = [
            'x' => '𝕏',
            'facebook' => 'f',
            'linkedin' => 'in',
            'github' => '🐙',
            'youtube' => '▶',
            'instagram' => '◎',
        ];
        return $map[$key] ?? '•';
    }

    protected function verUrl(string $rel, int $ver): string
    {
        $rel = ltrim($rel, '/');
        return $rel . (strpos($rel, '?') === false ? '?' : '&') . 'v=' . $ver;
    }

    protected function extractInner(string $content): string
    {
        if (preg_match($this->markersRegex(), $content, $m)) {
            $block = $m[0];
            $inner = preg_replace('/^.*?START\s*-->\s*/is', '', $block);
            $inner = preg_replace('/\s*<!--\s*ReBrand\s+END\s*-->$/is', '', $inner);
            return ltrim((string)$inner);
        }
        return '';
    }

    protected function markersRegex(): string
    {
        return '/<!--\s*ReBrand\s+START\s*-->.*?<!--\s*ReBrand\s+END\s*-->/is';
    }

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
