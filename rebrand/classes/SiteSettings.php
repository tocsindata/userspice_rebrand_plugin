<?php
declare(strict_types=1);

namespace Rebrand;

/**
 * ReBrand — SiteSettings
 * Location: usersc/plugins/rebrand/classes/SiteSettings.php
 *
 * Responsibilities:
 *  - Read current `settings` table snapshot
 *  - Backup current settings to `us_rebrand_site_backups`
 *  - Update settings (id=1) with validation
 *  - Restore from latest backup
 *
 * Notes:
 *  - Uses DB::getInstance() internally (no $db params)
 *  - Does not echo/redirect — throw \RuntimeException on failure
 *  - Admin/CSRF gates should be enforced by the caller
 */

class SiteSettings
{
    /** Fetch the first row from `settings` as an associative array (safe defaults). */
    public static function fetch(): array
    {
        $db = \DB::getInstance();
        try {
            $row = $db->query("SELECT * FROM settings LIMIT 1")->first();
        } catch (\Throwable $e) {
            // Provide safe shape even if query fails
            return [
                'site_name'     => '',
                'site_url'      => '',
                'copyright'     => '',
                'contact_email' => '',
            ];
        }

        if (!$row) {
            return [
                'site_name'     => '',
                'site_url'      => '',
                'copyright'     => '',
                'contact_email' => '',
            ];
        }

        return [
            'site_name'     => (string)($row->site_name ?? ''),
            'site_url'      => (string)($row->site_url ?? ''),
            'copyright'     => (string)($row->copyright ?? ''),
            'contact_email' => (string)($row->contact_email ?? ''),
        ];
    }

    /** Insert a backup snapshot of current settings into `us_rebrand_site_backups`. */
    public static function backup(string $note = 'settings snapshot'): void
    {
        $db  = \DB::getInstance();
        $now = date('Y-m-d H:i:s');
        $uId = (int)($GLOBALS['user']->data()->id ?? 0);
        $s   = self::fetch();

        try {
            $db->insert('us_rebrand_site_backups', [
                'took_at'       => $now,
                'user_id'       => $uId,
                'site_name'     => $s['site_name'],
                'site_url'      => $s['site_url'],
                'copyright'     => $s['copyright'],
                'contact_email' => $s['contact_email'],
                'notes'         => $note,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to backup settings: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Update `settings` (row id=1) with provided changes.
     * Performs soft validation and throws on DB errors.
     *
     * @param array{site_name?:string,site_url?:string,copyright?:string,contact_email?:string} $changes
     */
    public static function update(array $changes): void
    {
        $db = \DB::getInstance();

        // Normalize inputs
        $site_name     = isset($changes['site_name'])     ? trim((string)$changes['site_name'])     : null;
        $site_url      = isset($changes['site_url'])      ? trim((string)$changes['site_url'])      : null;
        $copyright     = isset($changes['copyright'])     ? trim((string)$changes['copyright'])     : null;
        $contact_email = isset($changes['contact_email']) ? trim((string)$changes['contact_email']) : null;

        // Validate (soft; leave stricter rules to caller if desired)
        if ($site_name !== null && $site_name === '') {
            throw new \InvalidArgumentException('Site Name cannot be empty.');
        }
        if ($site_url !== null && $site_url !== '' && !preg_match('#^https?://#i', $site_url)) {
            throw new \InvalidArgumentException('Site URL should start with http:// or https://');
        }
        if ($contact_email !== null && $contact_email !== '' && !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Contact Email is not a valid email address.');
        }

        // Build update payload
        $payload = [];
        if ($site_name !== null)     { $payload['site_name']     = $site_name; }
        if ($site_url !== null)      { $payload['site_url']      = $site_url; }
        if ($copyright !== null)     { $payload['copyright']     = $copyright; }
        if ($contact_email !== null) { $payload['contact_email'] = $contact_email; }

        if (empty($payload)) {
            // Nothing to update
            return;
        }

        try {
            // Most US installs keep settings as single row id=1
            $db->update('settings', 1, $payload);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to update settings: '.$e->getMessage(), 0, $e);
        }
    }

    /** Return the latest backup row (as array) or null if none. */
    public static function lastBackup(): ?array
    {
        $db = \DB::getInstance();
        try {
            $row = $db->query(
                "SELECT * FROM us_rebrand_site_backups ORDER BY took_at DESC, id DESC LIMIT 1"
            )->first();
        } catch (\Throwable $e) {
            return null;
        }

        if (!$row) return null;

        return [
            'took_at'       => (string)$row->took_at,
            'user_id'       => (int)$row->user_id,
            'site_name'     => (string)($row->site_name ?? ''),
            'site_url'      => (string)($row->site_url ?? ''),
            'copyright'     => (string)($row->copyright ?? ''),
            'contact_email' => (string)($row->contact_email ?? ''),
            'notes'         => (string)($row->notes ?? ''),
        ];
        }

    /** Restore settings from the latest backup. Returns true if restored, false if none found. */
    public static function restoreLatest(): bool
    {
        $db  = \DB::getInstance();
        $bak = self::lastBackup();
        if (!$bak) return false;

        try {
            $db->update('settings', 1, [
                'site_name'     => $bak['site_name'],
                'site_url'      => $bak['site_url'],
                'copyright'     => $bak['copyright'],
                'contact_email' => $bak['contact_email'],
            ]);
            return true;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to restore settings: '.$e->getMessage(), 0, $e);
        }
    }
}
