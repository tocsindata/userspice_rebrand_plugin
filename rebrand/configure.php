<?php
// usersc/plugins/rebrand/configure.php
// Minimal, single-file admin for Rebrand. No backups. No helpers. KISS.

if (!isset($user) || !is_object($user)) { die('User context not available.'); }
if ((int)($user->data()->id ?? 0) !== 1) { Redirect::to($us_url_root.'users/admin.php'); } // master only

include 'plugin_info.php';
pluginActive($plugin_name);

$db   = DB::getInstance();
$csrf = Token::generate();

// CSRF check (single place)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Token::check($_POST['csrf'] ?? '')) {
    include $abs_us_root.$us_url_root.'usersc/scripts/token_error.php';
    exit;
  }
}

// -------------------------
// ACTIONS
// -------------------------

// Save site settings
if (($_POST['rebrand_update'] ?? '') === 'site_settings') {
  $site_id       = (int)($_POST['site_id'] ?? 1);
  $site_name     = trim($_POST['site_name'] ?? '');
  $site_url      = trim($_POST['site_url'] ?? '');
  $contact_email = trim($_POST['contact_email'] ?? '');
  $copyright     = trim($_POST['copyright'] ?? '');

  $payload = [
    'site_name'     => $site_name,
    'site_url'      => $site_url,
    'contact_email' => $contact_email,
    'copyright'     => $copyright,
  ];

  foreach ($payload as $setting => $value) {
    $exists = $db->query("SELECT id FROM settings WHERE setting = ? AND site_id = ? LIMIT 1", [$setting, $site_id]);
    if ($exists->count()) {
      $db->query("UPDATE settings SET value = ? WHERE setting = ? AND site_id = ?",
        [$value, $setting, $site_id]);
    } else {
      $db->query("INSERT INTO settings (setting, value, site_id) VALUES (?, ?, ?)",
        [$setting, $value, $site_id]);
    }
  }
  $flash_settings_ok = true;
}

// Upload favicon/logo (overwrite)
if (($_POST['rebrand_update'] ?? '') === 'brand_assets') {
  // favicon.ico at web root
  if (!empty($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
    $tmp  = $_FILES['favicon']['tmp_name'];
    $dest = $abs_us_root.$us_url_root.'favicon.ico';
    @move_uploaded_file($tmp, $dest) ? $flash_favicon_ok = true : $flash_favicon_err = 'Failed to move favicon.';
  }
  // images/logo.png
  if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $tmp  = $_FILES['logo']['tmp_name'];
    $dir  = $abs_us_root.$us_url_root.'images/';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); } // create if missing
    $dest = $dir.'logo.png';
    @move_uploaded_file($tmp, $dest) ? $flash_logo_ok = true : $flash_logo_err = 'Failed to move logo.';
  }
}

// Social menu: ensure exists
$menuRow = $db->query("SELECT id FROM menus WHERE menu_name = 'rebrand_social' LIMIT 1")->first();
if (!$menuRow) {
  $db->query("INSERT INTO menus (menu_name, menu_location, menu_order, created_at, updated_at)
              VALUES ('rebrand_social','header',1,NOW(),NOW())");
  $menuRow = $db->query("SELECT id FROM menus WHERE menu_name = 'rebrand_social' LIMIT 1")->first();
  $flash_social_created = true;
}
$rebrand_menu_id = (int)$menuRow->id;

// Social: update/delete existing item
if (($_POST['rebrand_update'] ?? '') === 'social_menu_item') {
  $menu_item_id = (int)($_POST['menu_item_id'] ?? 0);
  if (isset($_POST['delete']) && $menu_item_id > 0) {
    $db->query("DELETE FROM us_menu_items WHERE id = ? LIMIT 1", [$menu_item_id]);
    $flash_social_deleted = true;
  } else {
    $label       = trim($_POST['label'] ?? '');
    $link        = trim($_POST['link'] ?? '');
    $icon_class  = trim($_POST['icon_class'] ?? '');
    $link_target = in_array($_POST['link_target'] ?? '_self', ['_self','_blank'], true) ? $_POST['link_target'] : '_self';
    if ($menu_item_id > 0) {
      $db->query("UPDATE us_menu_items SET label = ?, link = ?, icon_class = ?, link_target = ? WHERE id = ? LIMIT 1",
        [$label, $link, $icon_class, $link_target, $menu_item_id]);
      $flash_social_updated = true;
    }
  }
}

// Social: add new
if (($_POST['rebrand_update'] ?? '') === 'social_menu_add') {
  $label       = trim($_POST['label'] ?? '');
  $link        = trim($_POST['link'] ?? '');
  $icon_class  = trim($_POST['icon_class'] ?? '');
  $link_target = in_array($_POST['link_target'] ?? '_self', ['_self','_blank'], true) ? $_POST['link_target'] : '_self';
  if ($label !== '' && $link !== '' && $rebrand_menu_id > 0) {
    $db->insert('us_menu_items', [
      'menu'          => $rebrand_menu_id,
      'label'         => $label,
      'link'          => $link,
      'icon_class'    => $icon_class,
      'link_target'   => $link_target,
      'display_order' => 1,
      'disabled'      => 0,
      'dropdown'      => 0,
      'parent'        => 0,
    ]);
    $flash_social_added = true;
  } else {
    $flash_social_add_err = 'Provide label and link.';
  }
}

// Head Tags editor: usersc/includes/head_tags.php
$head_tags_path = $abs_us_root.$us_url_root.'usersc/includes/head_tags.php';
if (($_POST['rebrand_update'] ?? '') === 'save_head_tags') {
  $new_head_tags = $_POST['head_tags_contents'] ?? '';
  // Create dir if missing
  $ht_dir = dirname($head_tags_path);
  if (!is_dir($ht_dir)) { @mkdir($ht_dir, 0755, true); }
  file_put_contents($head_tags_path, $new_head_tags) !== false
    ? $flash_headtags_ok = true
    : $flash_headtags_err = 'Failed to write head_tags.php';
}
$head_tags_current = is_file($head_tags_path) ? file_get_contents($head_tags_path) :
"<?php
// usersc/includes/head_tags.php
// Put extra <meta>, <link>, and <script> tags here.
// Example:
// echo '<meta name=\"description\" content=\"My site\">';
?>";

// .htaccess editor at web root
$htaccess_path = $abs_us_root.$us_url_root.'.htaccess';
if (($_POST['rebrand_update'] ?? '') === 'save_htaccess') {
  $new_ht = $_POST['htaccess_contents'] ?? '';
  file_put_contents($htaccess_path, $new_ht) !== false
    ? $flash_ht_ok = true
    : $flash_ht_err = 'Failed to write .htaccess';
}
$ht_current = is_file($htaccess_path) ? file_get_contents($htaccess_path) :
"Options -Indexes
# Add your rewrite rules or security headers here
";

// -------------------------
// READ current data
// -------------------------

$site_id  = (int)($_POST['site_id'] ?? 1);
$site_ids = [];
$rows = $db->query("SELECT DISTINCT site_id FROM settings ORDER BY site_id ASC")->results();
foreach ($rows as $r) { $site_ids[] = (int)$r->site_id; }
if (!$site_ids) { $site_ids = [1]; }

$keys = ['site_name','site_url','contact_email','copyright'];
$current = array_fill_keys($keys, '');
foreach ($keys as $k) {
  $row = $db->query("SELECT value FROM settings WHERE setting = ? AND site_id = ? LIMIT 1", [$k, $site_id])->first();
  $current[$k] = $row ? $row->value : '';
}

$social_items = $db->query(
  "SELECT * FROM us_menu_items WHERE disabled = 0 AND menu = ? ORDER BY display_order DESC, id DESC",
  [$rebrand_menu_id]
)->results();

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES); }
?>
<div class="content mt-3">
  <div class="row"><div class="col-12">
    <div class="card mb-4"><div class="card-body">
      <h1 class="h3 mb-0 text-gray-800">Rebrand Configuration</h1>
      <p>Simple controls to rename the site, swap the logo &amp; favicon, tweak head tags, manage a small social menu, and edit <code>.htaccess</code>.</p>
    </div></div>
  </div></div>

  <div class="row"><div class="col-12">
    <div class="card mb-4"><div class="card-body">
      <h1 class="h3 mb-0 text-gray-800">SETTINGS</h1>
      <p>Site-wide settings.</p>
      <?php if (!empty($flash_settings_ok)): ?><div class="alert alert-success">Settings saved.</div><?php endif; ?>

      <form class="form-horizontal" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="rebrand_update" value="site_settings">
        <?php if (count($site_ids) > 1): ?>
          <div class="form-group row">
            <label class="col-md-4 control-label" for="site_id">Site ID</label>
            <div class="col-md-8">
              <select id="site_id" name="site_id" class="form-control" onchange="this.form.submit()">
                <?php foreach ($site_ids as $id): ?>
                  <option value="<?= (int)$id ?>" <?= $id===$site_id?'selected':''; ?>><?= (int)$id ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        <?php else: ?>
          <input type="hidden" name="site_id" value="<?= (int)$site_id ?>">
        <?php endif; ?>

        <div class="form-group row">
          <label class="col-md-4 control-label" for="site_name">Site Name</label>
          <div class="col-md-8"><input id="site_name" name="site_name" type="text" class="form-control" value="<?= h($current['site_name']) ?>"></div>
        </div>

        <div class="form-group row">
          <label class="col-md-4 control-label" for="site_url">Site URL</label>
          <div class="col-md-8"><input id="site_url" name="site_url" type="text" class="form-control" value="<?= h($current['site_url']) ?>"></div>
        </div>

        <div class="form-group row">
          <label class="col-md-4 control-label" for="contact_email">Contact Email</label>
          <div class="col-md-8"><input id="contact_email" name="contact_email" type="email" class="form-control" value="<?= h($current['contact_email']) ?>"></div>
        </div>

        <div class="form-group row">
          <label class="col-md-4 control-label" for="copyright">Copyright</label>
          <div class="col-md-8"><input id="copyright" name="copyright" type="text" class="form-control" value="<?= h($current['copyright']) ?>"></div>
        </div>

        <div class="form-group row">
          <div class="col-md-8 offset-md-4"><button class="btn btn-primary">Save Settings</button></div>
        </div>
      </form>
    </div></div>
  </div></div>

  <div class="row"><div class="col-12">
    <div class="card mb-4"><div class="card-body">
      <h1 class="h3 mb-0 text-gray-800">Brand Assets (Manual Upload)</h1>
      <p>Overwrite <code>favicon.ico</code> (web root) and <code>images/logo.png</code>.</p>

      <?php if (!empty($flash_favicon_ok)): ?><div class="alert alert-success">Favicon uploaded.</div><?php endif; ?>
      <?php if (!empty($flash_favicon_err)): ?><div class="alert alert-danger"><?= h($flash_favicon_err) ?></div><?php endif; ?>
      <?php if (!empty($flash_logo_ok)):    ?><div class="alert alert-success">Logo uploaded.</div><?php endif; ?>
      <?php if (!empty($flash_logo_err)):   ?><div class="alert alert-danger"><?= h($flash_logo_err) ?></div><?php endif; ?>

      <form class="form-horizontal" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="rebrand_update" value="brand_assets">
        <div class="form-group row">
          <label class="col-md-4 control-label" for="favicon">Favicon (.ico)</label>
          <div class="col-md-8"><input id="favicon" name="favicon" type="file" class="form-control"></div>
        </div>
        <div class="form-group row">
          <label class="col-md-4 control-label" for="logo">Logo (logo.png)</label>
          <div class="col-md-8"><input id="logo" name="logo" type="file" class="form-control"></div>
        </div>
        <div class="form-group row">
          <div class="col-md-8 offset-md-4"><button class="btn btn-primary">Upload</button></div>
        </div>
      </form>
    </div></div>
  </div></div>

  <div class="row"><div class="col-12">
    <div class="card mb-4"><div class="card-body">
      <h1 class="h3 mb-0 text-gray-800">Head Tags / Meta Manager</h1>
      <?php if (!empty($flash_headtags_ok)): ?><div class="alert alert-success">head_tags.php saved.</div><?php endif; ?>
      <?php if (!empty($flash_headtags_err)): ?><div class="alert alert-danger"><?= h($flash_headtags_err) ?></div><?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="rebrand_update" value="save_head_tags">
        <div class="form-group">
          <label for="head_tags_contents">Edit <code>usersc/includes/head_tags.php</code></label>
          <textarea id="head_tags_contents" name="head_tags_contents" class="form-control" rows="14" spellcheck="false"><?= h($head_tags_current) ?></textarea>
        </div>
        <button class="btn btn-primary">Save Head Tags</button>
      </form>
      <small class="text-muted">Tip: You can echo raw tags inside PHP, e.g. <code>echo '&lt;meta name=\"robots\" content=\"noindex\"&gt;';</code></small>
    </div></div>
  </div></div>

  <div class="row"><div class="col-12">
    <div class="card mb-4"><div class="card-body">
      <h1 class="h3 mb-0 text-gray-800">Social Links Menu (Below logo)</h1>
      <?php if (!empty($flash_social_created)): ?><div class="alert alert-info">Social menu created.</div><?php endif; ?>
      <?php if (!empty($flash_social_updated)): ?><div class="alert alert-success">Menu item updated.</div><?php endif; ?>
      <?php if (!empty($flash_social_deleted)): ?><div class="alert alert-success">Menu item deleted.</div><?php endif; ?>
      <?php if (!empty($flash_social_added)):   ?><div class="alert alert-success">Menu item added.</div><?php endif; ?>
      <?php if (!empty($flash_social_add_err)): ?><div class="alert alert-danger"><?= h($flash_social_add_err) ?></div><?php endif; ?>

      <?php if ($social_items): ?>
        <?php foreach ($social_items as $it): ?>
          <div class="card mb-2"><div class="card-body">
            <form class="form-horizontal" method="post">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="rebrand_update" value="social_menu_item">
              <input type="hidden" name="menu_item_id" value="<?= (int)$it->id ?>">
              <div class="form-group row">
                <label class="col-md-2 control-label" for="label_<?= (int)$it->id ?>">Label</label>
                <div class="col-md-10"><input id="label_<?= (int)$it->id ?>" name="label" type="text" class="form-control" value="<?= h($it->label) ?>"></div>
              </div>
              <div class="form-group row">
                <label class="col-md-2 control-label" for="link_<?= (int)$it->id ?>">Link</label>
                <div class="col-md-10"><input id="link_<?= (int)$it->id ?>" name="link" type="text" class="form-control" value="<?= h($it->link) ?>"></div>
              </div>
              <div class="form-group row">
                <label class="col-md-2 control-label" for="icon_<?= (int)$it->id ?>">Icon Class</label>
                <div class="col-md-10"><input id="icon_<?= (int)$it->id ?>" name="icon_class" type="text" class="form-control" value="<?= h($it->icon_class) ?>"></div>
              </div>
              <div class="form-group row">
                <label class="col-md-2 control-label" for="tgt_<?= (int)$it->id ?>">Target</label>
                <div class="col-md-10">
                  <select id="tgt_<?= (int)$it->id ?>" name="link_target" class="form-control">
                    <option value="_self"  <?= ($it->link_target ?? '_self') === '_self'  ? 'selected':''; ?>>Same Tab</option>
                    <option value="_blank" <?= ($it->link_target ?? '_self') === '_blank' ? 'selected':''; ?>>New Tab</option>
                  </select>
                </div>
              </div>
              <div class="form-group row">
                <div class="col-md-10 offset-md-2">
                  <button class="btn btn-primary">Update</button>
                  <button name="delete" value="1" class="btn btn-danger" onclick="return confirm('Delete this item?');">Delete</button>
                </div>
              </div>
            </form>
          </div></div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="mb-2">No social links yet.</p>
      <?php endif; ?>

      <hr>
      <h5>Add New Link</h5>
      <form class="form-horizontal" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="rebrand_update" value="social_menu_add">
        <div class="form-group row">
          <label class="col-md-2 control-label" for="new_label">Label</label>
          <div class="col-md-10"><input id="new_label" name="label" type="text" class="form-control" required></div>
        </div>
        <div class="form-group row">
          <label class="col-md-2 control-label" for="new_link">Link</label>
          <div class="col-md-10"><input id="new_link" name="link" type="text" class="form-control" placeholder="https://example.com" required></div>
        </div>
        <div class="form-group row">
          <label class="col-md-2 control-label" for="new_icon">Icon Class</label>
          <div class="col-md-10"><input id="new_icon" name="icon_class" type="text" class="form-control" placeholder="fab fa-x-twitter"></div>
        </div>
        <div class="form-group row">
          <label class="col-md-2 control-label" for="new_target">Target</label>
          <div class="col-md-10">
            <select id="new_target" name="link_target" class="form-control">
              <option value="_self">Same Tab</option>
              <option value="_blank">New Tab</option>
            </select>
          </div>
        </div>
        <div class="form-group row">
          <div class="col-md-10 offset-md-2"><button class="btn btn-success">Add Link</button></div>
        </div>
      </form>
    </div></div>
  </div></div>

  <div class="row"><div class="col-12">
    <div class="card mb-4"><div class="card-body">
      <h1 class="h3 mb-0 text-gray-800">Manually Edit .htaccess</h1>
      <?php if (!empty($flash_ht_ok)): ?><div class="alert alert-success">.htaccess saved.</div><?php endif; ?>
      <?php if (!empty($flash_ht_err)): ?><div class="alert alert-danger"><?= h($flash_ht_err) ?></div><?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="rebrand_update" value="save_htaccess">
        <div class="form-group">
          <label for="htaccess_contents">Edit <code>.htaccess</code> (web root)</label>
          <textarea id="htaccess_contents" name="htaccess_contents" class="form-control" rows="16" spellcheck="false"><?= h($ht_current) ?></textarea>
        </div>
        <button class="btn btn-primary">Save .htaccess</button>
      </form>
    </div></div>
  </div></div>
</div>
