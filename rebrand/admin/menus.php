<?php
/**
 * ReBrand — admin/menus.php
 * Purpose: Manage menu labels/links with preview & apply flows.
 *
 * Usage: Include via Plugin Manager, e.g.:
 *   users/admin.php?view=plugins_config&plugin=rebrand&panel=menus
 *
 * Security: Admin only (User ID 1). No header/footer includes here.
 */

use Rebrand\MenuPatcher;

if (!isset($user) || (int)($user->data()->id ?? 0) !== 1) {
  die('Admin only.');
}

// Require class
require_once __DIR__.'/../classes/MenuPatcher.php';

// Flash helper shortcut
function rb_flash($type, $msg) { $_SESSION['msg'][] = ['type'=>$type,'msg'=>$msg]; }

// Initialize
$mp = new MenuPatcher();

// Fetch current menus for the table
try {
  $currentMenus = $mp->fetchAll(); // array keyed by id
} catch (Throwable $e) {
  $currentMenus = [];
  rb_flash('danger', 'Failed to load menus: '.$e->getMessage());
}

// Handle local PREVIEW (non-destructive)
$previewResult = null;
$rulesJsonForApply = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['local_preview'])) {
  if (!Token::check($_POST['csrf'] ?? '')) {
    rb_flash('danger', 'CSRF token invalid.');
  } else {
    $raw = (string)($_POST['rules_json'] ?? '');
    $rulesJsonForApply = $raw;
    $rules = json_decode($raw, true);
    if (!is_array($rules)) {
      rb_flash('danger', 'Rules JSON is invalid. Please provide a valid JSON array.');
    } else {
      try {
        $previewResult = $mp->preview($rules);
        rb_flash('info', 'Preview only — no changes have been saved.');
      } catch (Throwable $e) {
        rb_flash('danger', 'Preview failed: '.$e->getMessage());
      }
    }
  }
}

// Small esc helper
function rb_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Build a compact example rules JSON if none submitted
if ($rulesJsonForApply === '') {
  $rulesJsonForApply = json_encode([
    [
      'id'     => 1,                 // menu row id (preferred)
      'label'  => 'Dashboard',       // new label (optional)
      'link'   => '/users/admin.php',// new link (optional)
      'enabled'=> 1,                 // 1=show, 0=hide (optional)
      'sort'   => 10,                // numeric sort (optional)
    ],
    // [
    //   'key' => 'admin_users',     // if your schema has a unique key/slug, you can use this instead of id
    //   'label' => 'Users',
    // ]
  ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
}

?>

<!-- ============================
     Current Menus (read-only)
============================= -->
<div class="card border-secondary-subtle shadow-sm mb-4">
  <div class="card-header d-flex align-items-center">
    <i class="bi bi-list-task me-2"></i> Current Menus
    <span class="ms-auto small text-muted">Rows: <?= count($currentMenus) ?></span>
  </div>
  <div class="card-body">
    <?php if (empty($currentMenus)): ?>
      <div class="text-muted">No menu rows found.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Label (menu)</th>
              <th>Link</th>
              <th>Parent</th>
              <th>Enabled</th>
              <th>Sort</th>
              <th>Key (if present)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($currentMenus as $row): ?>
              <tr>
                <td><?= (int)$row['id'] ?></td>
                <td class="small"><?= rb_h($row['menu'] ?? '') ?></td>
                <td class="small text-break"><code><?= rb_h($row['link'] ?? '') ?></code></td>
                <td><?= isset($row['parent']) ? (int)$row['parent'] : '' ?></td>
                <td><?= isset($row['display']) ? (int)$row['display'] : '' ?></td>
                <td><?= isset($row['sort']) ? (int)$row['sort'] : '' ?></td>
                <td class="small"><?= rb_h($row['key'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============================
     Menu Patcher — Preview & Apply
============================= -->
<div class="card border-secondary-subtle shadow-sm mb-4">
  <div class="card-header d-flex align-items-center">
    <i class="bi bi-pencil-square me-2"></i> Menu Labels/Links Patcher
  </div>
  <div class="card-body">
    <p class="small text-muted mb-3">
      Paste a JSON array of rules. Each rule may include:
      <code>id</code> (preferred) or <code>key</code>, and any of
      <code>label</code>, <code>link</code>, <code>enabled</code> (1|0),
      <code>parent</code>, <code>sort</code>.
      Click <strong>Preview</strong> to see diffs. When satisfied, click <strong>Apply</strong> to back up and update rows.
    </p>

    <!-- Local Preview (no writes) -->
    <form method="post" class="mb-3">
      <input type="hidden" name="csrf" value="<?=Token::generate()?>">
      <input type="hidden" name="local_preview" value="1">

      <div class="mb-2">
        <label class="form-label">Rules JSON</label>
        <textarea name="rules_json" class="form-control" rows="10" spellcheck="false"><?= rb_h($rulesJsonForApply) ?></textarea>
        <div class="form-text">Tip: Keep rules minimal — only include fields you want to change.</div>
      </div>

      <button class="btn btn-primary" type="submit">Preview Changes</button>
    </form>

    <!-- Apply (posts JSON to process.php) -->
    <form method="post" action="<?=$us_url_root?>users/admin.php?view=plugins_config&plugin=rebrand&do=menu_apply" onsubmit="return confirm('Apply these menu changes? A backup will be created first.');">
      <input type="hidden" name="csrf" value="<?=Token::generate()?>">
      <input type="hidden" name="rules_json" value="<?= rb_h($rulesJsonForApply) ?>">
      <button class="btn btn-success" type="submit">Apply Changes</button>
    </form>
  </div>
</div>

<?php if ($previewResult): 
  $summary = $previewResult['summary'] ?? ['total_rules'=>0,'will_change'=>0,'skipped'=>0];
  $diffs = $previewResult['diffs'] ?? [];
?>
<!-- ============================
     Preview Results (Diff)
============================= -->
<div class="card border-secondary-subtle shadow-sm mb-4">
  <div class="card-header d-flex align-items-center">
    <i class="bi bi-eye me-2"></i> Preview Results
    <span class="ms-auto small text-muted">
      Rules: <?= (int)$summary['total_rules'] ?> &middot;
      Will Change: <?= (int)$summary['will_change'] ?> &middot;
      Skipped: <?= (int)$summary['skipped'] ?>
    </span>
  </div>
  <div class="card-body">
    <?php if (empty($diffs)): ?>
      <div class="text-muted">No changes detected for the provided rules.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Field</th>
              <th>From</th>
              <th>To</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($diffs as $id => $d): ?>
              <?php foreach ($d['changes'] as $col => $pair): ?>
                <tr>
                  <td><?= (int)$id ?></td>
                  <td><code><?= rb_h($col) ?></code></td>
                  <td class="small text-break"><?= rb_h(var_export($pair['from'], true)) ?></td>
                  <td class="small text-break"><?= rb_h(var_export($pair['to'], true)) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
