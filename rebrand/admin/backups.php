<?php
/**
 * ReBrand — admin/backups.php
 * Lists and restores backups for files, site settings, and menus.
 *
 * Path: usersc/plugins/rebrand/admin/backups.php
 * Usage: Include via Plugin Manager (e.g., as a secondary admin view)
 *
 * - Admin only (User ID 1)
 * - No header/footer includes here; Plugin Manager provides chrome.
 * - Requires classes/BackupService.php
 */

use Rebrand\BackupService;

if (!isset($user) || (int)($user->data()->id ?? 0) !== 1) {
  die('Admin only.');
}

require_once __DIR__.'/../classes/BackupService.php';

// ---- guarded helpers (per global function rule) ----
if (!function_exists('rb_h')) {
  function rb_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Read pagination params (simple)
$limit  = max(1, (int)($_GET['limit']  ?? 25));
$offset = max(0, (int)($_GET['offset'] ?? 0));

// Init service
$svc = new BackupService();

// Fetch lists
try {
  $fileBackups  = $svc->listFileBackups($limit, $offset);
  $siteBackups  = $svc->listSiteBackups($limit, $offset);
  $menuBackups  = $svc->listMenuBackups($limit, $offset);
} catch (Throwable $e) {
  $_SESSION['msg'][] = ['type'=>'danger', 'msg'=>'Failed to load backups: '.$e->getMessage()];
  $fileBackups = $siteBackups = $menuBackups = [];
}

?>

<!-- ============================
     File Backups
============================= -->
<div class="card border-secondary-subtle shadow-sm mb-4">
  <div class="card-header d-flex align-items-center">
    <i class="bi bi-file-earmark-text me-2"></i> File Backups
    <span class="ms-auto small text-muted">Showing <?= (int)$limit ?> from offset <?= (int)$offset ?></span>
  </div>
  <div class="card-body">
    <?php if (empty($fileBackups)): ?>
      <div class="text-muted">No file backups found.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Took At</th>
              <th>User</th>
              <th>File Path</th>
              <th>Notes</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($fileBackups as $row): ?>
              <tr>
                <td><?= (int)$row['id'] ?></td>
                <td><?= rb_h($row['took_at'] ?? '') ?></td>
                <td><?= (int)($row['user_id'] ?? 0) ?></td>
                <td class="text-break"><code class="small"><?= rb_h($row['file_path'] ?? '') ?></code></td>
                <td class="small"><?= rb_h($row['notes'] ?? '') ?></td>
                <td class="text-end">
                  <form method="post" action="<?=$us_url_root?>users/admin.php?view=plugins_config&plugin=rebrand&do=restore_file_backup" class="d-inline">
                    <input type="hidden" name="csrf" value="<?=Token::generate()?>">
                    <input type="hidden" name="backup_id" value="<?= (int)$row['id'] ?>">
                    <button class="btn btn-sm btn-primary" type="submit" onclick="return confirm('Restore this file backup? This will overwrite the target file on disk.')">
                      Restore
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============================
     Site Settings Backups
============================= -->
<div class="card border-secondary-subtle shadow-sm mb-4">
  <div class="card-header d-flex align-items-center">
    <i class="bi bi-gear me-2"></i> Site Settings Backups
    <span class="ms-auto small text-muted">Showing <?= (int)$limit ?> from offset <?= (int)$offset ?></span>
  </div>
  <div class="card-body">
    <?php if (empty($siteBackups)): ?>
      <div class="text-muted">No site settings backups found.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Took At</th>
              <th>User</th>
              <th>Site Name</th>
              <th>Site URL</th>
              <th>Contact Email</th>
              <th>Notes</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($siteBackups as $row): ?>
              <tr>
                <td><?= (int)$row['id'] ?></td>
                <td><?= rb_h($row['took_at'] ?? '') ?></td>
                <td><?= (int)($row['user_id'] ?? 0) ?></td>
                <td class="small"><?= rb_h($row['site_name'] ?? '') ?></td>
                <td class="small text-break"><code><?= rb_h($row['site_url'] ?? '') ?></code></td>
                <td class="small"><?= rb_h($row['contact_email'] ?? '') ?></td>
                <td class="small"><?= rb_h($row['notes'] ?? '') ?></td>
                <td class="text-end">
                  <form method="post" action="<?=$us_url_root?>users/admin.php?view=plugins_config&plugin=rebrand&do=restore_site_backup" class="d-inline">
                    <input type="hidden" name="csrf" value="<?=Token::generate()?>">
                    <input type="hidden" name="backup_id" value="<?= (int)$row['id'] ?>">
                    <button class="btn btn-sm btn-primary" type="submit" onclick="return confirm('Restore these site settings? This will overwrite current values in the settings table.')">
                      Restore
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============================
     Menu Backups
============================= -->
<div class="card border-secondary-subtle shadow-sm mb-4">
  <div class="card-header d-flex align-items-center">
    <i class="bi bi-list-ul me-2"></i> Menu Backups
    <span class="ms-auto small text-muted">Showing <?= (int)$limit ?> from offset <?= (int)$offset ?></span>
  </div>
  <div class="card-body">
    <?php if (empty($menuBackups)): ?>
      <div class="text-muted">No menu backups found.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Took At</th>
              <th>User</th>
              <th>Notes</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($menuBackups as $row): ?>
              <tr>
                <td><?= (int)$row['id'] ?></td>
                <td><?= rb_h($row['took_at'] ?? '') ?></td>
                <td><?= (int)($row['user_id'] ?? 0) ?></td>
                <td class="small"><?= rb_h($row['notes'] ?? '') ?></td>
                <td class="text-end">
                  <form method="post" action="<?=$us_url_root?>users/admin.php?view=plugins_config&plugin=rebrand&do=restore_menu_backup" class="d-inline">
                    <input type="hidden" name="csrf" value="<?=Token::generate()?>">
                    <input type="hidden" name="backup_id" value="<?= (int)$row['id'] ?>">
                    <button class="btn btn-sm btn-primary" type="submit" onclick="return confirm('Restore menus from this backup? Existing menu rows will be updated to match the snapshot (no inserts/deletes).')">
                      Restore
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Optional: basic pager -->
<nav aria-label="Backups pagination">
  <ul class="pagination pagination-sm">
    <?php
      $prevOffset = max(0, $offset - $limit);
      $nextOffset = $offset + $limit;
      $base = $us_url_root.'users/admin.php?view=plugins_config&plugin=rebrand&panel=backups';
    ?>
    <li class="page-item <?= $offset <= 0 ? 'disabled' : '' ?>">
      <a class="page-link" href="<?=$base?>&limit=<?=$limit?>&offset=<?=$prevOffset?>">Previous</a>
    </li>
    <li class="page-item">
      <a class="page-link" href="<?=$base?>&limit=<?=$limit?>&offset=<?=$nextOffset?>">Next</a>
    </li>
  </ul>
</nav>
