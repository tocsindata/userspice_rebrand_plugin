<?php
namespace Rebrand;

/**
 * SiteSettings
 *
 * Safely reads and updates the UserSpice `settings` table while:
 *  - Treating it as potentially multi-site (multiple rows).
 *  - Editing ONLY `site_name` and `site_url`.
 *  - Recording a timestamped backup of the original values before any write.
 *
 * This class deliberately avoids touching other columns.
 */
class SiteSettings
{
    /** @var \DB */
    protected $db;

    /** @var string */
    protected string $settingsTable = 'settings';

    /** @var string */
    protected string $backupTable = 'us_rebrand_site_backups';

    public function __construct($db, string $settingsTable = 'settings', string $backupTable = 'us_rebrand_site_backups')
    {
        $this->db = $db;
        $this->settingsTable = $settingsTable;
        $this->backupTable = $backupTable;

        // Ensure backup table exists (idempotent).
        $this->ensureBackupTable();
    }

    /**
     * List available site rows (id, site_name, site_url) for selection.
     * Assumes potentially multiple rows for future multi-site support.
     *
     * @return array<int, array{ id:int, site_name:string, site_url:string|null }>
     */
    public function listSites(): array
    {
        $rows = $this->db->query("SELECT `id`, `site_name`, `site_url` FROM `{$this->settingsTable}` ORDER BY `id` ASC")->results();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r->id,
                'site_name' => (string)$r->site_name,
                'site_url' => isset($r->site_url) ? (string)$r->site_url : null,
            ];
        }
        return $out;
    }

    /**
     * Fetch a single row (id, site_name, site_url) by settings.id.
     */
    public function getSite(int $id): ?array
    {
        $row = $this->db->query("SELECT `id`, `site_name`, `site_url` FROM `{$this->settingsTable}` WHERE `id` = ? LIMIT 1", [$id])->first();
        if (!$row) return null;
        return [
            'id' => (int)$row->id,
            'site_name' => (string)$row->site_name,
            'site_url' => isset($row->site_url) ? (string)$row->site_url : null,
        ];
    }

    /**
     * Update ONLY site_name and site_url for the given settings.id.
     * Creates a backup of the existing values before update.
     *
     * @throws \Exception on validation errors or DB failure
     */
    public function updateSite(int $id, string $siteName, ?string $siteUrl): void
    {
        // Validate
        $siteName = $this->sanitizeSiteName($siteName);
        if ($siteName === '') {
            throw new \Exception('Site name is required and must be ≤ 100 characters.');
        }
        $siteUrl = $this->sanitizeHttpUrl($siteUrl);

        // Read current values for backup
        $curr = $this->getSite($id);
        if (!$curr) {
            throw new \Exception("settings.id {$id} not found.");
        }

        // Backup
        $this->backup($id, $curr['site_name'], $curr['site_url']);

        // Update only the two fields
        $fields = [
            'site_name' => $siteName,
            'site_url'  => $siteUrl,
        ];
        $this->db->update($this->settingsTable, $id, $fields);
    }

    /**
     * Restore the most recent backup for a given settings.id (site_name + site_url).
     * Returns true if a restore occurred.
     */
    public function revertLastBackup(int $id): bool
    {
        $row = $this->db->query(
            "SELECT * FROM `{$this->backupTable}` WHERE `settings_id` = ? ORDER BY `id` DESC LIMIT 1",
            [$id]
        )->first();

        if (!$row) return false;

        $fields = [
            'site_name' => (string)$row->site_name_backup,
            'site_url'  => $row->site_url_backup !== null ? (string)$row->site_url_backup : null,
        ];
        $this->db->update($this->settingsTable, $id, $fields);
        return true;
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------- */

    protected function ensureBackupTable(): void
    {
        // Try to create backup table if it doesn't exist.
        // Column widths chosen to mirror your schema constraints.
        try {
            $sql = "
                CREATE TABLE IF NOT EXISTS `{$this->backupTable}` (
                  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `settings_id` INT NOT NULL,
                  `site_name_backup` VARCHAR(100) NOT NULL,
                  `site_url_backup` VARCHAR(255) NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_settings_id` (`settings_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            ";
            $this->db->query($sql);
        } catch (\Exception $e) {
            // If creation fails, we still allow operation but without backup — not recommended.
            // Better to surface an error:
            throw new \Exception('Failed to ensure backup table for site settings: ' . $e->getMessage());
        }
    }

    protected function backup(int $settingsId, string $siteName, ?string $siteUrl): void
    {
        try {
            $this->db->insert($this->backupTable, [
                'settings_id'      => $settingsId,
                'site_name_backup' => $siteName,
                'site_url_backup'  => $siteUrl,
            ]);
        } catch (\Exception $e) {
            // Refuse to proceed if we cannot back up
            throw new \Exception('Failed to record site settings backup: ' . $e->getMessage());
        }
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
}
