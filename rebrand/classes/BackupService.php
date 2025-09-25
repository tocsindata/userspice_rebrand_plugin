<?php
declare(strict_types=1);

namespace Rebrand;

/**
 * ReBrand — BackupService
 * Location: usersc/plugins/rebrand/classes/BackupService.php
 *
 * Responsibilities:
 *  - List, read, and restore backups from:
 *      • us_rebrand_file_backups   (file_path + content_backup)
 *      • us_rebrand_site_backups   (settings snapshots)
 *      • us_rebrand_menu_backups   (menus as JSON)
 *  - Restore helpers for common targets (e.g., head_tags.php)
 *
 * Notes:
 *  - Uses DB::getInstance() (no $db params).
 *  - Throws \RuntimeException on failures callers should surface.
 *  - Admin/CSRF gating is the caller's job (e.g., process.php).
 */
class BackupService
{
    /* ---------------------------------
       Public listing helpers
    ----------------------------------*/
    /** List recent file backups, newest first. */
    public function listFileBackups(int $limit = 50, int $offset = 0): array
    {
        $db = \DB::getInstance();
        try {
            $q = $db->query(
                "SELECT * FROM us_rebrand_file_backups ORDER BY took_at DESC, id DESC LIMIT ?, ?",
                [$offset, $limit]
            );
            return array_map(static fn($r) => (array)$r, $q->results());
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to list file backups: '.$e->getMessage(), 0, $e);
        }
    }

    /** List recent settings snapshots, newest first. */
    public function listSiteBackups(int $limit = 50, int $offset = 0): array
    {
        $db = \DB::getInstance();
        try {
            $q = $db->query(
                "SELECT * FROM us_rebrand_site_backups ORDER BY took_at DESC, id DESC LIMIT ?, ?",
                [$offset, $limit]
            );
            return array_map(static fn($r) => (array)$r, $q->results());
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to list site backups: '.$e->getMessage(), 0, $e);
        }
    }

    /** List recent menu snapshots, newest first. */
    public function listMenuBackups(int $limit = 50, int $offset = 0): array
    {
        $db = \DB::getInstance();
        try {
            $q = $db->query(
                "SELECT * FROM us_rebrand_menu_backups ORDER BY took_at DESC, id DESC LIMIT ?, ?",
                [$offset, $limit]
            );
            return array_map(static fn($r) => (array)$r, $q->results());
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to list menu backups: '.$e->getMessage(), 0, $e);
        }
    }

    /* ---------------------------------
       File backup restore
    ----------------------------------*/
    /** Restore a specific file backup by its backup row id. */
    public function restoreFileByBackupId(int $backupId): bool
    {
        $db = \DB::getInstance();
        try {
            $row = $db->query("SELECT * FROM us_rebrand_file_backups WHERE id = ? LIMIT 1", [$backupId])->first();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to read file backup: '.$e->getMessage(), 0, $e);
        }
        if (!$row) {
            throw new \RuntimeException('File backup not found.');
        }

        $path = (string)$row->file_path;
        $content = $this->readBlob($row->content_backup);

        // Normalize text files to LF (best-effort; binary safe if no CR present)
        $content = $this->dos2unix($content);

        // Ensure dir exists; then atomic write
        @mkdir(\dirname($path), 0755, true);
        if (!$this->atomicWrite($path, $content)) {
            throw new \RuntimeException('Failed to write restored file: '.basename($path));
        }
        return true;
    }

    /**
     * Restore the most recent backup row for an exact file path,
     * or by suffix match (LIKE) if exact not found.
     */
    public function restoreLatestForPath(string $targetPath): bool
    {
        $db = \DB::getInstance();

        // exact first
        try {
            $row = $db->query(
                "SELECT * FROM us_rebrand_file_backups WHERE file_path = ? ORDER BY took_at DESC, id DESC LIMIT 1",
                [$targetPath]
            )->first();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed reading backups: '.$e->getMessage(), 0, $e);
        }

        // fallback: suffix match (e.g., when root changed)
        if (!$row) {
            try {
                $like = '%'.ltrim($targetPath, '%');
                $row = $db->query(
                    "SELECT * FROM us_rebrand_file_backups WHERE file_path LIKE ? ORDER BY took_at DESC, id DESC LIMIT 1",
                    [$like]
                )->first();
            } catch (\Throwable $e) {
                throw new \RuntimeException('Failed reading backups: '.$e->getMessage(), 0, $e);
            }
        }

        if (!$row) {
            return false; // no backup to restore
        }

        $content = $this->readBlob($row->content_backup);
        $content = $this->dos2unix($content);

        @mkdir(\dirname($targetPath), 0755, true);
        if (!$this->atomicWrite($targetPath, $content)) {
            throw new \RuntimeException('Failed writing restored file.');
        }
        return true;
    }

    /* ---------------------------------
       Settings snapshot restore
    ----------------------------------*/
    /** Restore settings table from a specific site backup id. */
    public function restoreSiteFromBackupId(int $backupId): bool
    {
        $db = \DB::getInstance();
        try {
            $row = $db->query("SELECT * FROM us_rebrand_site_backups WHERE id = ? LIMIT 1", [$backupId])->first();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to read site backup: '.$e->getMessage(), 0, $e);
        }
        if (!$row) {
            throw new \RuntimeException('Site backup not found.');
        }

        try {
            $db->update('settings', 1, [
                'site_name'     => (string)($row->site_name ?? ''),
                'site_url'      => (string)($row->site_url ?? ''),
                'copyright'     => (string)($row->copyright ?? ''),
                'contact_email' => (string)($row->contact_email ?? ''),
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to restore settings: '.$e->getMessage(), 0, $e);
        }
        return true;
    }

    /** Restore settings from most recent snapshot. Returns false if none. */
    public function restoreLatestSite(): bool
    {
        $db = \DB::getInstance();
        try {
            $row = $db->query(
                "SELECT * FROM us_rebrand_site_backups ORDER BY took_at DESC, id DESC LIMIT 1"
            )->first();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to read site backups: '.$e->getMessage(), 0, $e);
        }
        if (!$row) return false;

        try {
            $db->update('settings', 1, [
                'site_name'     => (string)($row->site_name ?? ''),
                'site_url'      => (string)($row->site_url ?? ''),
                'copyright'     => (string)($row->copyright ?? ''),
                'contact_email' => (string)($row->contact_email ?? ''),
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to restore settings: '.$e->getMessage(), 0, $e);
        }
        return true;
    }

    /* ---------------------------------
       Menu snapshot restore
    ----------------------------------*/
    /** Restore menus table from a specific menu backup id. */
    public function restoreMenusFromBackupId(int $backupId): bool
    {
        $db = \DB::getInstance();
        try {
            $row = $db->query("SELECT * FROM us_rebrand_menu_backups WHERE id = ? LIMIT 1", [$backupId])->first();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to read menu backup: '.$e->getMessage(), 0, $e);
        }
        if (!$row) {
            throw new \RuntimeException('Menu backup not found.');
        }

        $json = (string)($row->menu_json ?? '[]');
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Menu backup JSON is invalid.');
        }

        // Very common schema (id, menu, link, parent, display, sort). Adjust if your install differs.
        // Strategy: update rows that exist; skip inserts/deletes to be safe.
        $updated = 0;
        foreach ($data as $rowArr) {
            if (!is_array($rowArr) || !isset($rowArr['id'])) continue;
            $id = (int)$rowArr['id'];
            $payload = [];

            foreach (['menu','link','parent','display','sort'] as $col) {
                if (array_key_exists($col, $rowArr)) {
                    $payload[$col] = $rowArr[$col];
                }
            }
            if (empty($payload)) continue;

            try {
                $db->update('menus', $id, $payload);
                $updated++;
            } catch (\Throwable $e) {
                // continue, but record the last error
                $last = $e->getMessage();
            }
        }

        if ($updated === 0 && isset($last)) {
            throw new \RuntimeException('No menus updated; last error: '.$last);
        }
        return true;
    }

    /* ---------------------------------
       Convenience: restore head_tags.php
    ----------------------------------*/
    /**
     * Restore users/includes/head_tags.php from most recent backup.
     * Returns false if none exists.
     */
    public function restoreLatestHeadTags(): bool
    {
        $path = $this->headFilePath();
        return $this->restoreLatestForPath($path);
    }

    /* ---------------------------------
       Internals
    ----------------------------------*/
    /** Absolute FS path to users/includes/head_tags.php (no create). */
    protected function headFilePath(): string
    {
        global $abs_us_root, $us_url_root;
        return rtrim($abs_us_root.$us_url_root, '/').'/users/includes/head_tags.php';
    }

    /** Atomic write (0644) with temp file. */
    protected function atomicWrite(string $path, string $content): bool
    {
        $tmp = $path.'.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0644);
        return true;
    }

    /** Normalize CRLF/CR → LF. */
    protected function dos2unix(string $s): string
    {
        return \preg_replace("/\r\n?/", "\n", $s);
    }

    /**
     * Read BLOB/longblob content. $val may already be a PHP string,
     * or a stream resource depending on DB driver.
     */
    protected function readBlob($val): string
    {
        if (is_resource($val)) {
            $c = stream_get_contents($val);
            return $c === false ? '' : $c;
        }
        if (is_string($val)) return $val;
        return '';
    }
}
