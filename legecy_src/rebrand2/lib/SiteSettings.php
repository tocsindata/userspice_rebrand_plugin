<?php
namespace Rebrand;

/**
 * SiteSettings
 *
 * Safely reads and updates the UserSpice `settings` table while:
 *  - Treating it as potentially multi-site (multiple rows).
 *  - Editing ONLY site_name, site_url, and copyright.
 *  - Recording a timestamped backup before any write.
 *
 * Rules enforced for this project:
 *  - DB access is ALWAYS via DB::getInstance() internally.
 *  - Only user ID 1 may perform write actions (update/revert).
 *  - No headers/footers or output here—controller handles redirects/UI.
 */
class SiteSettings
{
    /** @var \DB */
    protected $db;

    /** @var string */
    protected string $settingsTable = 'settings';

    /** @var string */
    protected string $backupTable = 'us_rebrand_site_backups';

    public function __construct(string $settingsTable = 'settings', string $backupTable = 'us_rebrand_site_backups')
    {
        // Always obtain DB the UserSpice way—no injection.
        $this->db = \DB::getInstance();
        $this->settingsTable = $settingsTable;
        $this->backupTable = $backupTable;

        // Ensure backup table exists (idempotent).
        $this->ensureBackupTable();
    }

    /**
     * List available site rows for selection.
     *
     * @return array<int, array{id:int, site_name:string, site_url:?string, copyright:?string}>
     */
    public function listSites(): array
    {
        $q = $this->db->query(
            "SELECT `id`, `site_name`, `site_url`, `copyright`
             FROM `{$this->settingsTable}` ORDER BY `id` ASC"
        );
        $rows = $q->results();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'        => (int)$r->id,
                'site_name' => (string)$r->site_name,
                'site_url'  => isset($r->site_url) ? (string)$r->site_url : null,
                'copyright' => isset($r->copyright) ? (string)$r->copyright : null,
            ];
        }
        return $out;
    }

    /**
     * Fetch a single row by settings.id.
     *
     * @return array{id:int, site_name:string, site_url:?string, copyright:?string}|null
     */
    public function getSite(int $id): ?array
    {
        $row = $this->db->query(
            "SELECT `id`, `site_name`, `site_url`, `copyright`
             FROM `{$this->settingsTable}` WHERE `id` = ? LIMIT 1",
            [$id]
        )->first();

        if (!$row) return null;

        return [
            'id'        => (int)$row->id,
            'site_name' => (string)$row->site_name,
            'site_url'  => isset($row->site_url) ? (string)$row->site_url : null,
            'copyright' => isset($row->copyright) ? (string)$row->copyright : null,
        ];
    }

    /**
     * Update ONLY site_name, site_url, and copyright for the given settings.id.
     * Creates a backup of the existing values before update.
     *
     * @throws \Exception on validation errors, permission errors, or DB failure
     */
    public function updateSite(int $id, string $siteName, ?string $siteUrl, ?string $copyright): void
    {
        $this->ensureAdmin(); // Only user ID 1 may write.

        // Validate & normalize
        $siteName  = $this->sanitizeSiteName($siteName);
        if ($siteName === '') {
            throw new \Exception('Site name is required and must be ≤ 100 characters.');
        }
        $siteUrl   = $this->sanitizeHttpUrl($siteUrl);
        $copyright = $this->sanitizeCopyright($copyright);

        // Read current values for backup
        $curr = $this->getSite($id);
        if (!$curr) {
            throw new \Exception("settings.id {$id} not found.");
        }

        // Backup current state (required by project rules)
        $this->backup($id, $curr['site_name'], $curr['site_url'], $curr['copyright']);

        // Update only the three fields
        $fields = [
            'site_name' => $siteName,
            'site_url'  => $siteUrl,
            'copyright' => $copyright,
        ];
        $this->db->update($this->settingsTable, $id, $fields);
    }

    /**
     * Restore the most recent backup for a given settings.id.
     * Returns true if a restore occurred.
     *
     * @throws \Exception on permission errors or DB failure
     */
    public function revertLastBackup(int $id): bool
    {
        $this->ensureAdmin(); // Only user ID 1 may write.

        $row = $this->db->query(
            "SELECT * FROM `{$this->backupTable}` WHERE `settings_id` = ? ORDER BY `id` DESC LIMIT 1",
            [$id]
        )->first();

        if (!$row) return false;

        $fields = [
            'site_name' => (string)$row->site_name_backup,
            'site_url'  => $row->site_url_backup !== null ? (string)$row->site_url_backup : null,
            'copyright' => property_exists($row, 'copyright_backup') && $row->copyright_backup !== null
                ? (string)$row->copyright_backup
                : null,
        ];

        $this->db->update($this->settingsTable, $id, $fields);
        return true;
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------- */

    /**
     * Only allow user ID 1 to perform write actions.
     * @throws \Exception
     */
    protected function ensureAdmin(): void
    {
        global $user;
        if (!isset($user) || !is_object($user) || !method_exists($user, 'data')) {
            throw new \Exception('Permission denied: user context not available.');
        }
        $ud = $user->data();
        if (!isset($ud->id) || (int)$ud->id !== 1) {
            throw new \Exception('Permission denied: only user ID 1 may perform this action.');
        }
    }

    protected function ensureBackupTable(): void
    {
        // Create backup table if not exists (idempotent)
        $sql = "
            CREATE TABLE IF NOT EXISTS `{$this->backupTable}` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `settings_id` INT NOT NULL,
              `site_name_backup` VARCHAR(100) NOT NULL,
              `site_url_backup` VARCHAR(255) NULL,
              `copyright_backup` VARCHAR(255) NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_settings_id` (`settings_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ";
        $this->db->query($sql);

        // In case an older version exists without the copyright column, attempt to add it.
        try {
            $this->db->query("ALTER TABLE `{$this->backupTable}` ADD COLUMN `copyright_backup` VARCHAR(255) NULL");
        } catch (\Exception $e) {
            // Ignore if it already exists or the ALTER fails because the column is present.
        }
    }

    protected function backup(int $settingsId, string $siteName, ?string $siteUrl, ?string $copyright): void
    {
        $this->db->insert($this->backupTable, [
            'settings_id'      => $settingsId,
            'site_name_backup' => $siteName,
            'site_url_backup'  => $siteUrl,
            'copyright_backup' => $copyright,
        ]);
    }

    /**
     * Accepts ASCII/UTF-8, trims, and enforces length ≤100 (per schema).
     */
    protected function sanitizeSiteName(?string $name): string
    {
        $n = trim((string)$name);
        if ($n === '' || mb_strlen($n, 'UTF-8') > 100) {
            return '';
        }
        return $n;
    }

    /**
     * Allow only http/https or null/empty.
     * Returns normalized string (original) or null.
     */
    protected function sanitizeHttpUrl(?string $url): ?string
    {
        $u = trim((string)$url);
        if ($u === '') return null;

        $parts = parse_url($u);
        if (!$parts || empty($parts['scheme'])) {
            throw new \Exception('Invalid site URL: missing scheme (http/https).');
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \Exception('Invalid site URL: only http/https allowed.');
        }
        return $u;
    }

    protected function sanitizeCopyright(?string $c): ?string
    {
        $t = trim((string)$c);
        if ($t === '') return null;
        // keep ≤255 chars
        if (mb_strlen($t, 'UTF-8') > 255) {
            $t = mb_substr($t, 0, 255, 'UTF-8');
        }
        return $t;
    }
}
