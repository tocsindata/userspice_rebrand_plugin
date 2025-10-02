<?php
declare(strict_types=1);

namespace Rebrand;

/**
 * ReBrand — MenuPatcher
 * Location: usersc/plugins/rebrand/classes/MenuPatcher.php
 *
 * Responsibilities:
 *  - fetchAll(): read current menus into a simple array keyed by id
 *  - preview($rules): compute diffs without writing
 *  - apply($rules): backup menus then update existing rows only
 *
 * Schema assumptions (typical UserSpice):
 *   Table: menus
 *   Columns: id, menu (label), link, parent, display, sort
 *   Optional: key (slug) — if present, can be used to identify rows
 */
class MenuPatcher
{
    /** Fetch all menus as [id => row array]. */
    public function fetchAll(): array
    {
        $db = \DB::getInstance();
        $rows = [];
        try {
            $q = $db->query("SELECT * FROM menus ORDER BY parent ASC, sort ASC, id ASC");
            foreach ($q->results() as $r) {
                $row = (array)$r;
                $id = (int)$row['id'];
                $rows[$id] = [
                    'id'      => $id,
                    'menu'    => isset($row['menu'])    ? (string)$row['menu']    : '',
                    'link'    => isset($row['link'])    ? (string)$row['link']    : '',
                    'parent'  => isset($row['parent'])  ? (int)$row['parent']     : 0,
                    'display' => isset($row['display']) ? (int)$row['display']    : 1,
                    'sort'    => isset($row['sort'])    ? (int)$row['sort']       : 0,
                    'key'     => isset($row['key'])     ? (string)$row['key']     : '',
                ];
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to read menus: '.$e->getMessage(), 0, $e);
        }
        return $rows;
    }

    /** Build an index by key (if column exists) for fast lookup. */
    protected function indexByKey(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $id => $r) {
            if (!empty($r['key'])) $byKey[$r['key']] = $id;
        }
        return $byKey;
    }

    /**
     * Compute diffs for a set of rules without writing.
     * $rules: array of rule arrays; each rule may include:
     *   - 'id' (preferred) or 'key' (if schema has it)
     *   - any of: 'label' (maps to menu), 'link', 'enabled' (maps to display), 'parent', 'sort'
     */
    public function preview(array $rules): array
    {
        $current = $this->fetchAll();
        $byKey   = $this->indexByKey($current);

        $diffs = [];
        $skipped = 0;
        foreach ($rules as $rule) {
            if (!is_array($rule)) { $skipped++; continue; }

            $targetId = null;
            if (isset($rule['id'])) {
                $targetId = (int)$rule['id'];
            } elseif (isset($rule['key']) && isset($byKey[(string)$rule['key']])) {
                $targetId = (int)$byKey[(string)$rule['key']];
            } else {
                $skipped++; continue;
            }

            if (!isset($current[$targetId])) { $skipped++; continue; }

            $cur = $current[$targetId];
            $changes = [];

            // label => menu
            if (array_key_exists('label', $rule)) {
                $nv = (string)$rule['label'];
                if ($nv !== (string)$cur['menu']) {
                    $changes['menu'] = ['from'=>$cur['menu'], 'to'=>$nv];
                }
            }
            // link
            if (array_key_exists('link', $rule)) {
                $nv = (string)$rule['link'];
                if ($nv !== (string)$cur['link']) {
                    $changes['link'] = ['from'=>$cur['link'], 'to'=>$nv];
                }
            }
            // enabled => display
            if (array_key_exists('enabled', $rule)) {
                $nv = (int)$rule['enabled'];
                if ($nv !== (int)$cur['display']) {
                    $changes['display'] = ['from'=>$cur['display'], 'to'=>$nv];
                }
            }
            // parent
            if (array_key_exists('parent', $rule)) {
                $nv = (int)$rule['parent'];
                if ($nv !== (int)$cur['parent']) {
                    $changes['parent'] = ['from'=>$cur['parent'], 'to'=>$nv];
                }
            }
            // sort
            if (array_key_exists('sort', $rule)) {
                $nv = (int)$rule['sort'];
                if ($nv !== (int)$cur['sort']) {
                    $changes['sort'] = ['from'=>$cur['sort'], 'to'=>$nv];
                }
            }

            if (!empty($changes)) {
                $diffs[$targetId] = [
                    'target'  => $targetId,
                    'changes' => $changes,
                ];
            }
        }

        return [
            'summary' => [
                'total_rules' => count($rules),
                'will_change' => count($diffs),
                'skipped'     => $skipped,
            ],
            'diffs' => $diffs,
        ];
    }

    /**
     * Apply rules: back up menus to us_rebrand_menu_backups, then update rows.
     * Returns ['updated'=>N, 'backup_id'=>id, 'errors'=>[]]
     */
    public function apply(array $rules): array
    {
        $db = \DB::getInstance();

        // Take a JSON snapshot first
        $snapshot = $this->snapshotMenusJson();

        $backupId = 0;
        try {
            $db->insert('us_rebrand_menu_backups', [
                'took_at'   => date('Y-m-d H:i:s'),
                'user_id'   => (int)($GLOBALS['user']->data()->id ?? 0),
                'menu_json' => $snapshot,
                'notes'     => 'pre-apply',
            ]);
            $backupId = (int)$db->lastId();
        } catch (\Throwable $e) {
            // Non-fatal: we still try to apply, but report the error
        }

        $current = $this->fetchAll();
        $byKey   = $this->indexByKey($current);

        $updated = 0;
        $errors  = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;

            $targetId = null;
            if (isset($rule['id'])) {
                $targetId = (int)$rule['id'];
            } elseif (isset($rule['key']) && isset($byKey[(string)$rule['key']])) {
                $targetId = (int)$byKey[(string)$rule['key']];
            } else {
                $errors[] = 'Rule missing id/key or not found.';
                continue;
            }
            if (!isset($current[$targetId])) {
                $errors[] = "Menu id {$targetId} not found.";
                continue;
            }

            $payload = [];
            if (array_key_exists('label', $rule))   $payload['menu']    = (string)$rule['label'];
            if (array_key_exists('link', $rule))    $payload['link']    = (string)$rule['link'];
            if (array_key_exists('enabled', $rule)) $payload['display'] = (int)$rule['enabled'];
            if (array_key_exists('parent', $rule))  $payload['parent']  = (int)$rule['parent'];
            if (array_key_exists('sort', $rule))    $payload['sort']    = (int)$rule['sort'];

            if (empty($payload)) continue;

            try {
                $db->update('menus', $targetId, $payload);
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = "Update failed for id {$targetId}: ".$e->getMessage();
            }
        }

        return ['updated'=>$updated, 'backup_id'=>$backupId, 'errors'=>$errors];
    }

    /** Return current menus JSON (array of rows) for backup table. */
    protected function snapshotMenusJson(): string
    {
        $db = \DB::getInstance();
        $arr = [];
        try {
            $q = $db->query("SELECT * FROM menus ORDER BY id ASC");
            foreach ($q->results() as $r) {
                $arr[] = (array)$r;
            }
        } catch (\Throwable $e) {
            // Return empty array JSON on failure
        }
        return json_encode($arr, JSON_UNESCAPED_SLASHES);
    }
}
