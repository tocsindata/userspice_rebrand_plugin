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
 * Security/Policy notes:
 * - All DB access via DB::getInstance() (no $db parameters).
 * - All mutating operations restricted to user id === 1.
 * - No header/footer includes in this library.
 */
class MenuPatcher
{
    /** Backup table name */
    protected string $tableBackups = 'us_rebrand_menu_backups';

    /** Fixed schema */
    protected string $menuTable   = 'us_menus';
    protected string $contentCol  = 'brand_html';
    protected string $nameCol     = 'menu_name';
    protected string $pkCol       = 'id';

    public function __construct(string $tableBackups = 'us_rebrand_menu_backups')
    {
        // Allow backup table override if needed, but never accept a DB handle here.
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
        $db = \DB::getInstance();
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
            $rows = $db->query($sql, ['%' . $h . '%', $limit])->results();
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
        $this->assertAdmin();
        $db = \DB::getInstance();

        $menuIds = array_values(array_unique(array_map('intval', $menuIds)));
        if (empty($menuIds)) return;

        $block = $this->buildInjectedBlock($context);

        foreach ($menuIds as $id) {
            $row = $this->getRow($id);
            if (!$row) {
                // Skip silently; caller can decide how to surface.
                continue;
            }

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
        $db = \DB::getInstance();
        $menuIds = array_values(array_unique(array_map('intval', $menuIds)));
        if (empty($menuIds)) return 'No targets provided.';

        $out = [];
        foreach ($menuIds as $id) {
            $row = $this->getRow($id);
            if (!$row) continue;
            $curr = (string)$row->{$this->contentCol};
            $inner = $this->extractInner($curr);
            $menuName = (string)$row->{$this->nameCol};
            $out[] = "=== us_menus.id {$id} ({$menuName}) ===";
            $out[] = $this->formatDiff($inner, $inner); // placeholder showing current state
        }
        return implode("\n", $out);
    }

    /**
     * Revert the most recent backup set (last N rows inserted).
     * Returns true if any row restored.
     */
    public function revertLastBackups(int $max = 50): bool
    {
        $this->assertAdmin();
        $db = \DB::getInstance();

        $rows = $db->query("SELECT * FROM `{$this->tableBackups}` ORDER BY `id` DESC LIMIT {$max}")->results();
        $any = false;
        foreach ($rows as $b) {
            // Accept either menu_id or menu_item_id; both appear in some schemas
            $id = (int)($b->menu_id ?? 0);
            if ($id <= 0) {
                $id = (int)($b->menu_item_id ?? 0);
            }
            $content = (string)($b->content_backup ?? '');
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

    /**
     * Enforce that only user ID 1 may perform mutating operations.
     * Throws an Exception if unauthorized.
     */
    protected function assertAdmin(): void
    {
        global $user;
        if (!isset($user) || !is_object($user) || !method_exists($user, 'data')) {
            throw new \Exception('Unauthorized: user context unavailable.');
        }
        $data = $user->data();
        $id = isset($data->id) ? (int)$data->id : 0;
        if ($id !== 1) {
            throw new \Exception('Unauthorized: only user ID 1 may perform this action.');
        }
    }

    protected function getRow(int $id): ?object
    {
        $db = \DB::getInstance();
        $sql = "SELECT `{$this->pkCol}`, `{$this->nameCol}`, `{$this->contentCol}` FROM `{$this->menuTable}` WHERE `{$this->pkCol}` = ? LIMIT 1";
        $row = $db->query($sql, [$id])->first();
        return $row ?: null;
    }

    protected function updateRow(int $id, string $content): void
    {
        $this->assertAdmin();
        $db = \DB::getInstance();
        $db->update($this->menuTable, $id, [
            $this->contentCol => $content,
        ]);
    }

    protected function backupRow(int $menuId, ?string $menuName, string $content, string $notes = ''): void
    {
        $this->assertAdmin();
        $db = \DB::getInstance();
        try {
            $db->insert($this->tableBackups, [
                'menu_id'        => $menuId,
                'menu_item_id'   => $menuId, // compatibility with earlier schema variants
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
     * If no markers exist yet, append the block to the end of brand_html.
     * Always store ENCODED block in DB (brand_html is encoded).
     */
    protected function upsertBlock(string $current, string $block): string
    {
        $encodedBlock = $this->enc("<!-- ReBrand START -->\n{$block}\n<!-- ReBrand END -->");

        if (preg_match($this->markersRegex(), $current)) {
            // Replace whatever variant (raw or encoded) with encoded
            return (string)preg_replace($this->markersRegex(), $encodedBlock, $current);
        }

        // Append encoded markers at end
        $sep = (substr($current, -1) === "\n") ? "" : "\n";
        return $current . $sep . $encodedBlock . "\n";
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

        // URLs use {{root}} so they work with existing menu rendering (no PHP eval in DB columns)
        $logoUrl = '{{root}}' . $this->verUrl($logo, $ver);
        $logoDarkUrl = $logoDark !== '' ? '{{root}}' . $this->verUrl(ltrim($logoDark, '/'), $ver) : '';

        // Sort socials by configured order
        uasort($social, function ($a, $b) {
            return (int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0);
        });

        $lines = [];
        $lines[] = '<div class="rebrand-header-block" style="display:flex;align-items:center;gap:1rem;">';
        $lines[] = '  <div class="rebrand-logo">';
        if ($logoDarkUrl !== '') {
            $lines[] = '    <picture>';
            $lines[] = '      <source media="(prefers-color-scheme: dark)" srcset="' . $logoDarkUrl . '">';
            $lines[] = '      <img src="' . $logoUrl . '" alt="Logo" class="img-fluid" style="max-height:48px">';
            $lines[] = '    </picture>';
        } else {
            $lines[] = '    <img src="' . $logoUrl . '" alt="Logo" class="img-fluid" style="max-height:48px">';
        }
        $lines[] = '  </div>';
        $lines[] = '  <div class="rebrand-socials" style="display:flex; gap:.75rem; align-items:center;">';
        foreach ($social as $key => $cfg) {
            if (empty($cfg['enabled'])) continue;
            $url = trim((string)($cfg['url'] ?? ''));
            if ($url === '') continue;
            $label = $this->labelForPlatform($key);
            // Keep it simple; most sites already use FA in menus
            $lines[] = '    <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">' . $this->iconForPlatform($key) . '</a>';
        }
        $lines[] = '  </div>';
        $lines[] = '</div>';

        // Return RAW HTML; upsertBlock() will encode with htmlspecialchars()
        return implode("\n", $lines);
    }

    protected function enc(string $html): string
    {
        return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
    }

    protected function extractInner(string $content): string
    {
        if (preg_match($this->markersRegex(), $content, $m)) {
            $block = html_entity_decode($m[0], ENT_QUOTES, 'UTF-8');
            $inner = preg_replace('/^.*?START\s*-->\s*/is', '', $block);
            $inner = preg_replace('/\s*<!--\s*ReBrand\s+END\s*-->$/is', '', $inner);
            return ltrim((string)$inner);
        }
        return '';
    }

    protected function markersRegex(): string
    {
        // raw OR encoded markers
        return '/(?:<!--\s*ReBrand\s+START\s*-->.*?<!--\s*ReBrand\s+END\s*-->)|(?:&lt;!--\s*ReBrand\s+START\s*--&gt;.*?&lt;!--\s*ReBrand\s+END\s*--&gt;)/is';
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

    protected function verUrl(string $rel, int $ver): string
    {
        $rel = ltrim($rel, '/');
        return $rel . (strpos($rel, '?') === false ? '?' : '&') . 'v=' . $ver;
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
}
