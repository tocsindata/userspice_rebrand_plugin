<?php
// usersc/plugins/rebrand/admin/settings.php

/* -------------------------------------------------------------
   Bootstrap UserSpice and environment
-------------------------------------------------------------- */
$init = null;
for ($i = 0; $i < 6; $i++) {
  $try = realpath(__DIR__ . str_repeat(DIRECTORY_SEPARATOR . '..', $i) . '/users/init.php');
  if ($try && file_exists($try)) { $init = $try; break; }
}
if ($init) { require_once $init; } else { die('ReBrand: could not locate users/init.php'); }

/* -------------------------------------------------------------
   Access control: only user ID 1 may access
-------------------------------------------------------------- */
if (!isset($user) || !$user->isLoggedIn() || (int)$user->data()->id !== 1) {
  // Send them back to the plugin config page
  header("Location: {$us_url_root}users/admin.php?view=plugins_config&plugin=rebrand");
  exit;
}

/* -------------------------------------------------------------
   Constants / helpers
-------------------------------------------------------------- */
$tableSettings       = 'us_rebrand_settings';
$tableMenuBackups    = 'us_rebrand_menu_backups';
$tableFileBackups    = 'us_rebrand_file_backups';
$tableSiteBackups    = 'us_rebrand_site_backups';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function rebrand_url($rel, $root, $ver=null){
  $u = rtrim($root,'/').'/'.ltrim($rel,'/');
  if ($ver !== null) $u .= (str_contains($u,'?')?'&':'?').'v='.(int)$ver;
  return $u;
}

/* -------------------------------------------------------------
   DB handle (do not pass around; get it where used)
-------------------------------------------------------------- */
$db = DB::getInstance();

/* -------------------------------------------------------------
   Load site settings rows first (used for default OG title)
-------------------------------------------------------------- */
$siteRows = $db->query("SELECT id, site_name, site_url, copyright FROM settings ORDER BY id ASC")->results();
$currentSiteId = isset($_POST['site_id']) ? (int)$_POST['site_id'] : ((isset($siteRows[0]->id)) ? (int)$siteRows[0]->id : 0);
$currentSite   = null;
if ($currentSiteId) {
  foreach ($siteRows as $r) {
    if ((int)$r->id === $currentSiteId) {
      $currentSite = [
        'id'        => (int)$r->id,
        'site_name' => (string)$r->site_name,
        'site_url'  => isset($r->site_url)?(string)$r->site_url:'',
        'copyright' => isset($r->copyright)?(string)$r->copyright:'',
      ];
      break;
    }
  }
}

/* -------------------------------------------------------------
   Settings row (singleton id=1) + CSRF + status
-------------------------------------------------------------- */
$settingsRow = $db->query("SELECT * FROM `{$tableSettings}` WHERE id = 1")->first();
if (!$settingsRow) {
  // fallback shape if installer not run yet
  $settingsRow = (object)[
    'asset_version'=>1,
    'logo_path'=>'users/images/rebrand/logo.png',
    'logo_dark_path'=>null,
    'favicon_mode'=>'single',
    'favicon_root'=>'users/images/rebrand/icons',
    'favicon_html'=>null,
    'social_links'=>json_encode(new stdClass()),
    'menu_target_ids'=>json_encode([]),
    'header_override_enabled'=>1,
    'id1_only'=>1,
  ];
}
$assetVersion = (int)($settingsRow->asset_version ?? 1);

$csrf = null;
if (class_exists('Token') && method_exists('Token','generate')) {
  $csrf = Token::generate();
}

/* -------------------------------------------------------------
   Head meta (via HeadTagsPatcher) and default OG title
   NOTE: HeadTagsPatcher must self-use DB::getInstance() and US globals.
-------------------------------------------------------------- */
$currentMeta = [];
try {
  require_once __DIR__ . '/../lib/HeadTagsPatcher.php';
  $headPatch = new \Rebrand\HeadTagsPatcher(); // no $db, no path params
  $currentMeta = $headPatch->readCurrentMeta() ?: [];
} catch (Throwable $e) {
  // fail soft; form still renders
  $currentMeta = [];
}
$defaultOgTitle = $currentSite ? $currentSite['site_name'] : ($currentMeta['og_title'] ?? '');

/* -------------------------------------------------------------
   Resolve assets (use US globals as-is; do NOT overwrite them)
-------------------------------------------------------------- */
// $abs_us_root and $us_url_root come from init.php and must not be redefined.

// Logo candidates
$logoCandidates = [
  (string)($settingsRow->logo_path ?? ''),      // plugin-configured
  'users/images/rebrand/logo.png',              // plugin default
  'users/images/logo.png',                      // stock UserSpice
];
$logoPath = '';
foreach ($logoCandidates as $cand) {
  if (!$cand) continue;
  $abs = $abs_us_root . ltrim($cand, '/');
  if (file_exists($abs)) { $logoPath = $cand; break; }
}
if ($logoPath === '') $logoPath = 'users/images/logo.png';
$logoUrl = rebrand_url($logoPath, $us_url_root, $assetVersion);

// Dark logo (optional)
$logoDarkPath = (string)($settingsRow->logo_dark_path ?? '');
$logoDarkUrl  = $logoDarkPath ? rebrand_url($logoDarkPath, $us_url_root, $assetVersion) : '';

// Favicon status
$faviconRootAbs = $abs_us_root . 'favicon.ico';
$faviconExists  = file_exists($faviconRootAbs);
$faviconSize    = $faviconExists ? (int)filesize($faviconRootAbs) : 0;
$faviconMtime   = $faviconExists ? date('Y-m-d H:i:s', (int)filemtime($faviconRootAbs)) : null;

// Menus list for selects
$menusList = $db->query("SELECT id, menu_name FROM us_menus ORDER BY id ASC")->results();

// Social links (JSON)
$socialLinks = json_decode((string)($settingsRow->social_links ?? '{}'), true) ?: [];

// Flash messages (from process.php)
$flash = $_SESSION['rebrand_flash'] ?? [];
unset($_SESSION['rebrand_flash']);

?>
<div class="container-fluid">

  <?php if (!empty($flash['success'])): ?>
    <div class="alert alert-success" style="white-space:pre-wrap;"><?= h($flash['success']) ?></div>
  <?php endif; ?>
  <?php if (!empty($flash['error'])): ?>
    <div class="alert alert-danger" style="white-space:pre-wrap;"><?= h($flash['error']) ?></div>
  <?php endif; ?>

  <!-- Title / Status -->
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">ReBrand — Settings</h3>
    <form action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" method="post" class="d-inline">
      <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
      <input type="hidden" name="action" value="bump_version">
      <button class="btn btn-sm btn-outline-secondary" title="Increase asset_version to bust caches">Bump Version (now v<?= (int)$assetVersion ?>)</button>
    </form>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="border rounded p-3 h-100">
        <div class="fw-bold">Asset Version</div>
        <div class="display-6"><?= (int)$assetVersion ?></div>
        <small class="text-muted d-block">Appended as <code>?v=<?= (int)$assetVersion ?></code> to images/links.</small>
      </div>
    </div>
    <div class="col-md-3">
      <div class="border rounded p-3 h-100">
        <div class="fw-bold">Root favicon.ico</div>
        <?php if ($faviconExists): ?>
          <div>Size: <?= (int)$faviconSize ?> bytes</div>
          <div>Modified: <?= h($faviconMtime) ?></div>
          <a href="<?= h(rtrim($us_url_root,'/')) ?>/favicon.ico?v=<?= (int)$assetVersion ?>" target="_blank">Open</a>
        <?php else: ?>
          <div class="text-danger">Not found at site root.</div>
        <?php endif; ?>
        <small class="text-muted d-block mt-1">Path: <code><?= h($faviconRootAbs) ?></code></small>
      </div>
    </div>
    <div class="col-md-6">
      <div class="border rounded p-3 h-100">
        <div class="fw-bold">Current Logo Preview</div>
        <div class="rebrand-preview mt-2">
          <?php if ($logoDarkUrl): ?>
            <picture>
              <source media="(prefers-color-scheme: dark)" srcset="<?= h($logoDarkUrl) ?>">
              <img src="<?= h($logoUrl) ?>" alt="Logo" class="img-fluid" style="max-height:60px">
            </picture>
          <?php else: ?>
            <img src="<?= h($logoUrl) ?>" alt="Logo" class="img-fluid" style="max-height:60px">
          <?php endif; ?>
        </div>
        <small class="text-muted d-block mt-2">Source: <code><?= h($logoPath) ?></code></small>
      </div>
    </div>
  </div>

  <!-- Site Settings -->
  <div class="card mb-4">
    <div class="card-header"><strong>Site Settings</strong> — edit only name, URL, and copyright</div>
    <div class="card-body">
      <form method="post" action="<?= $us_url_root ?>usersc/plugins/rebrand/configure.php" class="mb-3">
        <div class="row g-2 align-items-end">
          <div class="col-md-4">
            <label class="form-label">Select Settings Row (id)</label>
            <select name="site_id" class="form-select" onchange="this.form.submit()">
              <?php foreach ($siteRows as $r): ?>
                <option value="<?= (int)$r->id ?>" <?= ((int)$r->id===$currentSiteId)?'selected':''; ?>>
                  ID <?= (int)$r->id ?> — <?= h($r->site_name) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-8">
            <small class="text-muted">UserSpice typically uses a single row, but we treat it as multi-site capable.</small>
          </div>
        </div>
      </form>

      <?php if ($currentSite): ?>
      <form method="post" action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" class="mb-3">
        <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
        <input type="hidden" name="action" value="site_settings_save">
        <input type="hidden" name="site_id" value="<?= (int)$currentSite['id'] ?>">

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Site Name</label>
            <input type="text" class="form-control" name="site_name" maxlength="100" required
                   value="<?= h($currentSite['site_name']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Site URL (http/https)</label>
            <input type="url" class="form-control" name="site_url" placeholder="https://example.com"
                   value="<?= h($currentSite['site_url'] ?? '') ?>">
          </div>
        </div>

        <div class="col-md-12 mt-2">
          <label class="form-label">Copyright (footer text)</label>
          <input type="text" class="form-control" name="copyright"
                 value="<?= h($currentSite['copyright'] ?? '') ?>" maxlength="255" placeholder="© Your Company">
        </div>

        <div class="mt-3 d-flex gap-2">
          <button class="btn btn-primary">Save Site Settings</button>
          <button formaction="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php"
                  name="action" value="site_settings_revert"
                  class="btn btn-outline-danger"
                  onclick="return confirm('Revert site_name/site_url/copyright to the last backup for this row?');">
            Revert Last Backup
          </button>
        </div>
        <small class="text-muted d-block mt-2">
          Only <code>site_name</code>, <code>site_url</code>, and <code>copyright</code> are changed. A backup is recorded before saving.
        </small>
      </form>
      <?php else: ?>
        <div class="alert alert-warning">No settings row selected or found.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Logo -->
  <div class="card mb-4">
    <div class="card-header"><strong>Logo — Preview &amp; Upload</strong></div>
    <div class="card-body">
      <form action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" method="post" enctype="multipart/form-data" class="row g-3">
        <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
        <input type="hidden" name="action" value="upload_logo">
        <div class="col-md-6">
          <label class="form-label">Choose PNG/JPG</label>
          <input class="form-control" type="file" name="logo_file" accept=".png,.jpg,.jpeg,image/png,image/jpeg" required>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="is_dark" id="is_dark" value="1">
            <label class="form-check-label" for="is_dark">This is a dark-mode logo</label>
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Optional resize (max width/height)</label>
          <div class="row g-2">
            <div class="col-6"><input class="form-control" type="number" min="0" name="logo_max_w" placeholder="e.g., 240"></div>
            <div class="col-6"><input class="form-control" type="number" min="0" name="logo_max_h" placeholder="e.g., 60"></div>
          </div>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="resize_logo" id="resize_logo" value="1">
            <label class="form-check-label" for="resize_logo">Resize before saving</label>
          </div>
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary">Upload Logo</button>
        </div>
        <small class="text-muted">Saved to <code>users/images/rebrand/</code>; we never overwrite the stock <code>users/images/logo.png</code>.</small>
      </form>
    </div>
  </div>

  <!-- Favicon -->
  <div class="card mb-4">
    <div class="card-header"><strong>Favicon</strong> — single-file upload and offline generator</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <div class="border rounded p-3 h-100">
            <div class="fw-bold mb-2">Upload favicon.ico (root)</div>
            <form action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" method="post" enctype="multipart/form-data">
              <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
              <input type="hidden" name="action" value="upload_favicon_single">
              <input class="form-control mb-2" type="file" name="favicon_ico" accept=".ico,image/x-icon,image/vnd.microsoft.icon" required>
              <button class="btn btn-primary">Replace /favicon.ico</button>
            </form>
            <small class="text-muted d-block mt-2">Writes to your site root (e.g., <code>/public_html/favicon.ico</code>) and bumps cache version.</small>
          </div>
        </div>
        <div class="col-md-6">
          <div class="border rounded p-3 h-100">
            <div class="fw-bold mb-2">Generate Offline (from PNG)</div>
            <form action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" method="post" enctype="multipart/form-data">
              <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
              <input type="hidden" name="action" value="generate_icons_offline">
              <div class="mb-2">
                <label class="form-label">Master PNG (1024×1024 recommended)</label>
                <input class="form-control" type="file" name="master_png" accept="image/png" required>
              </div>
              <div class="row g-2">
                <div class="col-6">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="include_maskable" id="include_maskable" value="1">
                    <label class="form-check-label" for="include_maskable">Include maskable icons</label>
                  </div>
                </div>
                <div class="col-6">
                  <input class="form-control" type="text" name="theme_color" placeholder="#000000 (optional)">
                </div>
              </div>
              <div class="mb-2 form-check">
                <input class="form-check-input" type="checkbox" name="copy_ico_to_root" id="copy_ico_to_root" value="1" checked>
                <label class="form-check-label" for="copy_ico_to_root">Also copy favicon.ico to site root</label>
              </div>
              <button class="btn btn-primary">Generate Icons</button>
            </form>
            <small class="text-muted d-block mt-2">Outputs to <code>users/images/rebrand/icons/</code> and fills the Head Snippet box below.</small>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Head Meta (direct edits to usersc/includes/head_tags.php) -->
  <div class="card mb-4">
    <div class="card-header"><strong>Head Meta</strong> — edits the actual <code>usersc/includes/head_tags.php</code></div>
    <div class="card-body">
      <form action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" method="post" class="row g-3">
        <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
        <input type="hidden" name="action" value="save_head_meta">

        <div class="col-md-3">
          <label class="form-label">charset</label>
          <input class="form-control" name="charset" value="<?= h(($currentMeta['charset'] ?? '') ?: 'utf-8') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">X-UA-Compatible</label>
          <input class="form-control" name="x_ua" value="<?= h(($currentMeta['x_ua'] ?? '') ?: 'IE=edge') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Author</label>
          <input class="form-control" name="author" value="<?= h($currentMeta['author'] ?? '') ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Description</label>
          <input class="form-control" name="description" value="<?= h($currentMeta['description'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">OG Image URL</label>
          <input class="form-control" name="og_image" value="<?= h($currentMeta['og_image'] ?? '') ?>" placeholder="<?=$us_url_root?>users/images/rebrand/icons/apple-touch-icon.png">
        </div>

        <div class="col-md-4">
          <label class="form-label">OG URL</label>
          <input class="form-control" name="og_url" value="<?= h($currentMeta['og_url'] ?? '') ?>" placeholder="<?=$us_url_root?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">OG Type</label>
          <input class="form-control" name="og_type" value="<?= h(($currentMeta['og_type'] ?? '') ?: 'website') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">OG Title</label>
          <input class="form-control" name="og_title" value="<?= h($defaultOgTitle ?: 'Userspice Site') ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Shortcut Icon HREF</label>
          <input class="form-control" name="shortcut_icon" value="<?= h(($currentMeta['shortcut_icon'] ?? '') ?: $us_url_root.'favicon.ico') ?>">
          <small class="text-muted">We’ll append <code>?v=<?= (int)$assetVersion ?></code> automatically.</small>
        </div>

        <div class="col-12 d-flex gap-2 mt-2">
          <button class="btn btn-primary">Save Head Meta</button>
          <button class="btn btn-outline-danger" formaction="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" name="action" value="revert_head_tags"
            onclick="return confirm('Revert head_tags.php from last backup?');">
            Revert from Backup
          </button>
        </div>
        <small class="text-muted">This writes directly into <code>usersc/includes/head_tags.php</code> and keeps a backup.</small>
      </form>
    </div>
  </div>

  <!-- Head Snippet -->
  <div class="row g-3 mb-4">
    <?php
      // Prefill the Head Snippet box (saved value or sensible default)
      $headSnippet = (string)($settingsRow->favicon_html ?? '');
      if ($headSnippet === '') {
        $ver = (int)$assetVersion;
        $headSnippet = <<<HTML
<!-- Basic favicons -->
<link rel="icon" type="image/x-icon" href="{{root}}favicon.ico?v={$ver}">
<link rel="icon" type="image/png" sizes="32x32" href="{{root}}users/images/rebrand/icons/favicon-32x32.png?v={$ver}">
<link rel="icon" type="image/png" sizes="16x16" href="{{root}}users/images/rebrand/icons/favicon-16x16.png?v={$ver}">
<link rel="apple-touch-icon" sizes="180x180" href="{{root}}users/images/rebrand/icons/apple-touch-icon.png?v={$ver}">

<!-- PWA / manifest (commented by default) -->
<!--
<link rel="manifest" href="{{root}}users/images/rebrand/icons/site.webmanifest?v={$ver}">
<meta name="theme-color" content="#000000">
<link rel="mask-icon" href="{{root}}users/images/rebrand/icons/safari-pinned-tab.svg?v={$ver}" color="#000000">
-->
HTML;
      }
    ?>
    <div class="col-md-8">
      <div class="border rounded p-3">
        <div class="fw-bold mb-2">Head Snippet (generated or custom)</div>
        <form action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" method="post" class="mb-2">
          <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
          <input type="hidden" name="action" value="save_head_snippet">
          <textarea class="form-control" name="head_snippet" rows="10"><?= h($headSnippet) ?></textarea>
          <div class="mt-2 d-flex gap-2">
            <button class="btn btn-primary">Save Head Snippet</button>
            <a class="btn btn-outline-secondary" href="<?= $us_url_root ?>users/admin.php?view=plugins_config&plugin=rebrand">Reset (reload)</a>
          </div>
        </form>
        <small class="text-muted d-block mt-2">
          PWA/manifest lines are intentionally <strong>commented</strong>. You can enable them later.
        </small>
      </div>
    </div>
    <div class="col-md-4">
      <div class="border rounded p-3 h-100">
        <div class="fw-bold mb-2">Apply to &lt;head&gt; (head_tags.php)</div>
        <form action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" method="post" class="mb-2">
          <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
          <input type="hidden" name="action" value="apply_head_tags">
          <button class="btn btn-success w-100">Apply / Update</button>
        </form>
        <form action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" method="post" class="mb-2">
          <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
          <input type="hidden" name="action" value="diff_head_tags">
          <button class="btn btn-outline-secondary w-100">Show Diff</button>
        </form>
        <form action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" method="post"
              onsubmit="return confirm('Revert head_tags.php from last backup?');">
          <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
          <input type="hidden" name="action" value="revert_head_tags">
          <button class="btn btn-outline-danger w-100">Revert from Backup</button>
        </form>
        <small class="text-muted d-block mt-2">Edits <code>usersc/includes/head_tags.php</code> between markers. Backups saved.</small>
      </div>
    </div>
  </div>

  <!-- Menu Integration (marker-based) -->
  <div class="card mb-4">
    <div class="card-header"><strong>Menu Integration</strong> — inject block into <code>us_menus.brand_html</code></div>
    <div class="card-body">
      <form action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" method="post" class="mb-3">
        <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
        <input type="hidden" name="action" value="save_menu_targets">
        <label class="form-label">Target Menu Item IDs (JSON array)</label>
        <input type="text" class="form-control" name="menu_target_ids"
               value="<?= h((string)($settingsRow->menu_target_ids ?? '[]')) ?>">
        <div class="mt-2 d-flex gap-2">
          <button class="btn btn-outline-secondary" formaction="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" name="action" value="discover_menu_candidates">Discover Candidates</button>
          <button class="btn btn-primary">Save Target IDs</button>
        </div>
        <small class="text-muted d-block mt-2">Example: <code>[1,2]</code> (Main + Dashboard)</small>
      </form>

      <div class="d-flex gap-2">
        <form action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" method="post">
          <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
          <input type="hidden" name="action" value="apply_menu_patch">
          <button class="btn btn-success">Apply / Update Menu Content</button>
        </form>
        <form action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" method="post">
          <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
          <input type="hidden" name="action" value="diff_menu_patch">
          <button class="btn btn-outline-secondary">Show Diff</button>
        </form>
        <form action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" method="post" onsubmit="return confirm('Revert last menu backups?');">
          <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
          <input type="hidden" name="action" value="revert_menu_patch">
          <button class="btn btn-outline-danger">Revert from Backup</button>
        </form>
      </div>
      <small class="text-muted d-block mt-2">Stores an <em>encoded</em> marker block; safe with existing <code>{{root}}</code> usage.</small>
    </div>
  </div>

  <!-- Logo Path Search & Replace -->
  <?php
    $defaultFindPath   = '{{root}}users/images/logo.png';
    $defaultReplaceRel = $logoPath ?: 'users/images/rebrand/logo.png';
    $defaultReplaceUrl = '{{root}}' . ltrim($defaultReplaceRel, '/');
  ?>
  <div class="card mb-4">
    <div class="card-header"><strong>Logo Path Search &amp; Replace</strong> — edit selected menu(s) only</div>
    <div class="card-body">
      <p class="text-muted mb-3">
        Updates <code>brand_html</code> of selected <code>us_menus</code> rows. We back up each row first.
        Matches both raw and HTML-encoded variants automatically.
      </p>
      <form action="<?= $us_url_root ?>usersc/plugins/rebrand/admin/process.php" method="post" class="mb-2">
        <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
        <input type="hidden" name="action" value="menu_search_replace">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Menus to edit (hold Ctrl/Cmd to multi-select)</label>
            <select class="form-select" name="menu_ids[]" multiple size="6" required>
              <?php foreach ($menusList as $m): ?>
                <option value="<?= (int)$m->id ?>" <?= in_array((int)$m->id,[1,2],true)?'selected':''; ?>>
                  ID <?= (int)$m->id ?> — <?= h($m->menu_name ?? '') ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small class="text-muted d-block mt-1">Default selects IDs 1 &amp; 2 (Main + Dashboard).</small>
          </div>
          <div class="col-md-4">
            <label class="form-label">Find (raw or with {{root}})</label>
            <input type="text" class="form-control" name="find" required value="<?= h($defaultFindPath) ?>">
            <small class="text-muted">Example: <code>{{root}}users/images/logo.png</code></small>
          </div>
          <div class="col-md-4">
            <label class="form-label">Replace with</label>
            <input type="text" class="form-control" name="replace" value="<?= h($defaultReplaceUrl) ?>">
            <small class="text-muted">Default uses your plugin logo with <code>{{root}}</code>.</small>
          </div>
        </div>
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" id="sr_append_ver" name="append_version" value="1" checked>
          <label class="form-check-label" for="sr_append_ver">Append cache-buster <code>?v=asset_version</code> to the replacement URL</label>
        </div>
        <div class="mt-3 d-flex gap-2">
          <button class="btn btn-outline-secondary" name="dry_run" value="1">Dry-run (counts only)</button>
          <button class="btn btn-primary">Replace in Selected Menus</button>
        </div>
      </form>
      <small class="text-muted">For richer blocks (logo + socials), use the marker-based “Apply / Update Menu Content” above.</small>
    </div>
  </div>

</div>
