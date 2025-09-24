<?php
/**
 * UserSpice ReBrand Plugin — Admin Settings UI
 *
 * - User ID 1 only
 * - CSRF via Form Builder (Token::generate / Token::check)
 * - Presents controls for:
 *    • Logo preview/upload (optional resize flags)
 *    • Favicon manager (single-file upload or offline generator master image)
 *    • Head tags injection (apply/revert/diff)
 *    • Menu integration (select menu IDs to patch; apply/revert/diff)
 *    • Social links (enable + URL)
 *    • Site Settings (multi-site aware): edit ONLY site_name and site_url with backups
 *    • Status panel + asset_version bump
 *
 * NOTE: This file is UI-only. All mutating actions are handled by admin/process.php.
 */

// FORCE REFRESH OF ON GITHUB 
// Helpers to build URLs relative to $us_url_root
function rebrand_url($rel, $usUrlRoot, $assetVersion = null) {
  $rel = ltrim($rel, '/');
  $url = rtrim($usUrlRoot, '/') . '/' . $rel;
  if ($assetVersion !== null) {
    $url .= '?v=' . (int)$assetVersion;
  }
  return $url; 
} 

$init = null;
for ($i = 0; $i < 6; $i++) {
  $try = realpath(__DIR__ . str_repeat(DIRECTORY_SEPARATOR . '..', $i) . '/users/init.php');
  if ($try && file_exists($try)) { $init = $try; break; }
}
if ($init) { require_once $init; } else { die('ReBrand: could not locate users/init.php'); }
if (!isset($db) || !($db instanceof DB)) { $db = DB::getInstance(); }


// Ensure we have a DB instance even if $db isn't global in this scope
if (!isset($db) || !($db instanceof DB)) {
  $db = DB::getInstance();
}

if (!isset($db)) {
  die('ReBrand: UserSpice DB context not available.');
}

$userId = $user->data()->id ?? null;
if ((int)$userId !== 1) {
  die('ReBrand: Only User ID 1 may access these settings.');
}

$usRoot     = isset($abs_us_root) ? rtrim($abs_us_root, '/\\') . '/' : rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/';
$usUrlRoot  = isset($us_url_root) ? $us_url_root : '/';
$usersc     = $usRoot . 'usersc/';
$imagesDir  = $usRoot . 'users/images/';
$rebrandDir = $imagesDir . 'rebrand/';
$iconsDir   = $rebrandDir . 'icons/';

$tableSettings     = 'us_rebrand_settings';
$tableMenuBackups  = 'us_rebrand_menu_backups';
$tableFileBackups  = 'us_rebrand_file_backups';

// Load settings row (id = 1)
$settings = $db->query("SELECT * FROM `{$tableSettings}` WHERE id = 1")->first();
if (!$settings) {
  // Soft fallback if installer didn't run
  $settings = (object)[
    'asset_version' => 1,
    'logo_path' => 'users/images/rebrand/logo.png',
    'logo_dark_path' => null,
    'favicon_mode' => 'single',
    'favicon_root' => 'users/images/rebrand/icons',
    'favicon_html' => null,
    'social_links' => json_encode(new stdClass()),
    'menu_target_ids' => json_encode([]),
    'header_override_enabled' => 1,
    'id1_only' => 1,
  ];
}

$assetVersion   = (int)$settings->asset_version;
// OLD:
$logoPath = $settings->logo_path ?: 'users/images/rebrand/logo.png';

// NEW (fallback to the stock UserSpice logo when ours doesn't exist yet):
$logoCandidates = [
  (string)($settings->logo_path ?: ''),                 // plugin setting if set
  'users/images/rebrand/logo.png',                       // plugin default location
  'users/images/logo.png',                               // stock UserSpice logo (your current)
];
$logoPath = '';
foreach ($logoCandidates as $cand) {
  if (!$cand) continue;
  $abs = $usRoot . ltrim($cand, '/');
  if (file_exists($abs)) { $logoPath = $cand; break; }
}
// Final safeguard if none found:
if ($logoPath === '') { $logoPath = 'users/images/logo.png'; }

$logoDarkPath   = $settings->logo_dark_path ?: '';
$faviconRootRel = $settings->favicon_root ?: 'users/images/rebrand/icons';
$faviconHtml    = $settings->favicon_html ?: '';
$menuTargetIds  = json_decode($settings->menu_target_ids ?: '[]', true) ?: [];
$socialLinks    = json_decode($settings->social_links ?: '{}', true) ?: [];

$gdAvailable       = extension_loaded('gd');
$imagickAvailable  = class_exists('Imagick');

// Compute preview URLs
$logoUrl      = rebrand_url($logoPath, $usUrlRoot, $assetVersion);
$logoDarkUrl  = $logoDarkPath ? rebrand_url($logoDarkPath, $usUrlRoot, $assetVersion) : '';
$faviconIco   = rebrand_url('favicon.ico', $usUrlRoot, $assetVersion);
$faviconPng32 = rebrand_url($faviconRootRel . '/favicon-32x32.png', $usUrlRoot, $assetVersion);
$faviconPng16 = rebrand_url($faviconRootRel . '/favicon-16x16.png', $usUrlRoot, $assetVersion);

// Utility: safely echo
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Flash messages (from process.php redirect)
$flash = [];
if (!empty($_SESSION['rebrand_flash'])) {
  $flash = $_SESSION['rebrand_flash'];
  unset($_SESSION['rebrand_flash']);
}

// Build token
$csrf = (class_exists('Token') && method_exists('Token', 'generate')) ? Token::generate() : '';

// Load SiteSettings helper for multi-site-aware site_name/site_url UI
require_once __DIR__ . '/../lib/SiteSettings.php';
$siteSvc = new \Rebrand\SiteSettings($db);
$sites = $siteSvc->listSites();
$selectedSiteId = (isset($_GET['site_id']) && ctype_digit((string)$_GET['site_id'])) ? (int)$_GET['site_id'] : ( (count($sites) > 0) ? (int)$sites[0]['id'] : 0 );
$currentSite = $selectedSiteId ? $siteSvc->getSite($selectedSiteId) : null;

?>
<link rel="stylesheet" href="<?= $usUrlRoot ?>usersc/plugins/rebrand/assets/admin.css">
<script src="<?= $usUrlRoot ?>usersc/plugins/rebrand/assets/admin.js" defer></script>

<div class="container-fluid">
  <div class="row align-items-center mb-3">
    <div class="col">
      <h2 class="mb-0">ReBrand — Settings</h2>
      <small class="text-muted">User ID 1 only • CSRF protected • Cache-busting v<?= (int)$assetVersion ?></small>
    </div>
    <div class="col-auto">
      <form action="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php" method="post" class="d-inline">
        <input type="hidden" name="action" value="bump_version">
        <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
        <button class="btn btn-outline-secondary btn-sm" title="Increment asset version to bust caches">
          Bump Asset Version
        </button>
      </form>
    </div>
  </div>

  <?php if (!empty($flash['success'])): ?>
    <div class="alert alert-success" style="white-space: pre-wrap;"><?= h($flash['success']) ?></div>
  <?php endif; ?>
  <?php if (!empty($flash['error'])): ?>
    <div class="alert alert-danger" style="white-space: pre-wrap;"><?= h($flash['error']) ?></div>
  <?php endif; ?>

  <!-- Status Panel -->
  <div class="card mb-4">
    <div class="card-header"><strong>Status</strong></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <div class="border rounded p-3 h-100">
            <div class="fw-bold">GD Extension</div>
            <div><?= $gdAvailable ? '<span class="text-success">Available</span>' : '<span class="text-danger">Missing</span>' ?></div>
            <small class="text-muted">Used for resize & icon generation.</small>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-3 h-100">
            <div class="fw-bold">Imagick Extension</div>
            <div><?= $imagickAvailable ? '<span class="text-success">Available</span>' : '<span class="text-muted">Optional</span>' ?></div>
            <small class="text-muted">Optional alternative to GD.</small>
          </div>
        </div>
        <div class="col-md-6">
          <div class="border rounded p-3 h-100">
            <div class="fw-bold">Paths</div>
            <div><code><?= h($rebrandDir) ?></code></div>
            <div><code><?= h($iconsDir) ?></code></div>
            <small class="text-muted">Ensure these are writable by the web server.</small>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Section: Site Settings (multi-site aware) -->
  <div class="card mb-4">
    <div class="card-header"><strong>Site Settings</strong> — Edit only <code>site_name</code> and <code>site_url</code></div>
    <div class="card-body">
      <?php if (empty($sites)): ?>
        <div class="alert alert-warning mb-0">No rows found in <code>settings</code> table.</div>
      <?php else: ?>
        <form class="row g-3 align-items-end" method="get" action="<?= h($usUrlRoot) ?>usersc/plugins/rebrand/admin/settings.php">
          <div class="col-md-4">
            <label class="form-label">Select Settings Row (id)</label>
            <select class="form-select" name="site_id" onchange="this.form.submit()">
              <?php foreach ($sites as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $selectedSiteId === (int)$s['id'] ? 'selected' : '' ?>>
                  ID <?= (int)$s['id'] ?> — <?= h($s['site_name']) ?> (<?= h($s['site_url'] ?? '') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-8">
            <small class="text-muted">Assume future multi-site: each row represents a different site; currently most installs have a single row.</small>
          </div>
        </form>

        <hr>

        <?php if ($currentSite): ?>
          <form method="post" action="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php" class="mb-3">
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

            <div class="col-md-12">
            <label class="form-label">Copyright (footer text)</label>
            <input type="text" class="form-control" name="copyright"
                    value="<?= h($currentSite['copyright'] ?? '') ?>" maxlength="255" placeholder="© Your Company">
            </div>

            <div class="mt-3 d-flex gap-2">
              <button class="btn btn-primary">Save Site Settings</button>
              <button formaction="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php"
                      name="action" value="site_settings_revert"
                      class="btn btn-outline-danger"
                      onclick="return confirm('Revert site_name and site_url to the last backup for this row?');">
                Revert Last Backup
              </button>
            </div>
            <small class="text-muted d-block mt-2">
              Only <code>site_name</code> and <code>site_url</code> are changed. A timestamped backup is recorded before saving.
            </small>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Section: Logo -->
  <div class="card mb-4">
    <div class="card-header"><strong>Logo</strong> — Preview & Upload</div>
    <div class="card-body">
      <div class="row g-3 align-items-center">
        <div class="col-md-4">
          <div class="border rounded p-3 text-center">
            <div class="mb-2 fw-bold">Current Logo</div>
            <div style="min-height:90px">
              <img src="<?= h($logoUrl) ?>" alt="Logo Preview" style="max-width: 100%; height: auto;">
            </div>
            <small class="text-muted d-block mt-2"><?= h($logoPath) ?>?v=<?= (int)$assetVersion ?></small>
          </div>
        </div>
        <div class="col-md-4">
          <div class="border rounded p-3 text-center">
            <div class="mb-2 fw-bold">Dark Variant (optional)</div>
            <div style="min-height:90px">
              <?php if ($logoDarkUrl): ?>
                <img src="<?= h($logoDarkUrl) ?>" alt="Dark Logo Preview" style="max-width: 100%; height: auto;">
              <?php else: ?>
                <div class="text-muted">None</div>
              <?php endif; ?>
            </div>
            <small class="text-muted d-block mt-2"><?= $logoDarkUrl ? h($logoDarkPath) . '?v=' . (int)$assetVersion : '' ?></small>
          </div>
        </div>
        <div class="col-md-4">
          <div class="border rounded p-3 h-100">
            <div class="fw-bold mb-2">Upload / Replace</div>
            <form action="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php" method="post" enctype="multipart/form-data">
              <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
              <input type="hidden" name="action" value="upload_logo">
              <div class="mb-2">
                <label class="form-label">Logo (PNG/JPG)</label>
                <input type="file" name="logo_file" class="form-control" accept=".png,.jpg,.jpeg" required>
              </div>
              <div class="mb-2 form-check">
                <input class="form-check-input" type="checkbox" value="1" id="resize_logo" name="resize_logo" style="shadow: 0 0 3px rgba(0,0,0,0.5);">
                <label class="form-check-label" for="resize_logo">Resize on upload (keep aspect)</label>
              </div>
              <div class="row">
                <div class="col">
                  <label class="form-label">Max Width (px)</label>
                  <input type="number" class="form-control" name="logo_max_w" min="16" max="2048" placeholder="e.g., 512">
                </div>
                <div class="col">
                  <label class="form-label">Max Height (px)</label>
                  <input type="number" class="form-control" name="logo_max_h" min="16" max="2048" placeholder="e.g., 256">
                </div>
              </div>
              <div class="mt-3 d-flex gap-2">
                <button class="btn btn-primary">Save Logo</button>
                <div class="form-check ms-2">
                  <input class="form-check-input" type="checkbox" value="1" id="is_dark" name="is_dark" style="shadow: 0 0 3px rgba(0,0,0,0.5);">
                  <label class="form-check-label" for="is_dark">This upload is the Dark Variant</label>
                </div>
              </div>
              <small class="text-muted d-block mt-2">Atomic write; bumps asset_version; updates menu/logo references.</small>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Section: Favicons -->
  <div class="card mb-4">
    <div class="card-header"><strong>Favicons & App Icons</strong> — Single-file or Offline Generator</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <div class="border rounded p-3 text-center h-100">
            <div class="fw-bold mb-2">Classic Favicon</div>
            <div>
              <img src="<?= h($faviconIco) ?>" alt="favicon.ico" style="width:32px;height:32px;image-rendering:pixelated">
            </div>
            <small class="text-muted d-block mt-2">/favicon.ico?v=<?= (int)$assetVersion ?></small>

            <hr>
            <form action="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php" method="post" enctype="multipart/form-data">
              <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
              <input type="hidden" name="action" value="upload_favicon_single">
              <label class="form-label">Upload favicon.ico</label>
              <input type="file" name="favicon_ico" class="form-control mb-2" accept=".ico">
              <button class="btn btn-secondary w-100">Replace favicon.ico</button>
            </form>
          </div>
        </div>

        <div class="col-md-4">
          <div class="border rounded p-3 text-center h-100">
            <div class="fw-bold mb-2">PNG Set Preview</div>
            <div class="d-flex align-items-center justify-content-center gap-4">
              <img src="<?= h($faviconPng16) ?>" alt="16x16" width="16" height="16" style="image-rendering:pixelated">
              <img src="<?= h($faviconPng32) ?>" alt="32x32" width="32" height="32" style="image-rendering:pixelated">
            </div>
            <small class="text-muted d-block mt-2"><?= h($faviconRootRel) ?>/favicon-*.png?v=<?= (int)$assetVersion ?></small>
            <div class="mt-2">
              <span class="badge bg-light text-dark">Sizes: 180 / 192 / 256 / 384 / 512</span>
            </div>
          </div>
        </div>

        <div class="col-md-4">
          <div class="border rounded p-3 h-100">
            <div class="fw-bold mb-2">Generate Offline</div>
            <?php if (!$gdAvailable && !$imagickAvailable): ?>
              <div class="alert alert-warning">
                Neither GD nor Imagick is available. Offline generation is disabled.
              </div>
            <?php endif; ?>
            <form action="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php" method="post" enctype="multipart/form-data">
              <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
              <input type="hidden" name="action" value="generate_icons_offline">
              <div class="mb-2">
                <label class="form-label">Master Image (PNG ≥ 1024×1024 recommended)</label>
                <input type="file" name="master_png" class="form-control" accept=".png" <?= ($gdAvailable || $imagickAvailable) ? '' : 'disabled' ?>>
              </div>
              <div class="mb-2 form-check">
                <input class="form-check-input" type="checkbox" name="include_maskable" id="include_maskable" value="1" style="shadow: 0 0 3px rgba(0,0,0,0.5);">
                <label class="form-check-label" for="include_maskable">Include maskable icon variants</label>
              </div>
              <div class="mb-2">
                <label class="form-label">Theme Color (for pinned/tab; hex)</label>
                <input type="text" name="theme_color" class="form-control" placeholder="#111111">
              </div>
              <button class="btn btn-primary w-100" <?= ($gdAvailable || $imagickAvailable) ? '' : 'disabled' ?>>Generate Icons</button>
              <small class="text-muted d-block mt-2">Writes ICO + PNG set under <code><?= h($faviconRootRel) ?></code>, stores head snippet (PWA lines commented), bumps asset_version.</small>
            </form>
          </div>
        </div>
      </div>

      <hr class="my-4">

      <div class="row g-3">
        <div class="col-md-8">
          <div class="border rounded p-3">
            <div class="fw-bold mb-2">Head Snippet (generated or custom)</div>
            <textarea class="form-control" rows="10" readonly><?= h($faviconHtml) ?></textarea>
            <small class="text-muted d-block mt-2">
              PWA/manifest lines are intentionally <strong>commented</strong>. You can enable them in the future.
            </small>
          </div>
        </div>
        <div class="col-md-4">
          <div class="border rounded p-3 h-100">
            <div class="fw-bold mb-2">Apply to &lt;head&gt; (head_tags.php)</div>
            <form action="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php" method="post" class="mb-2">
              <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
              <input type="hidden" name="action" value="apply_head_tags">
              <button class="btn btn-success w-100">Apply / Update</button>
            </form>
            <form action="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php" method="post" class="mb-2">
              <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
              <input type="hidden" name="action" value="diff_head_tags">
              <button class="btn btn-outline-secondary w-100">Show Diff</button>
            </form>
            <form action="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php" method="post" onsubmit="return confirm('Revert head_tags.php from last backup?');">
              <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
              <input type="hidden" name="action" value="revert_head_tags">
              <button class="btn btn-outline-danger w-100">Revert from Backup</button>
            </form>
            <small class="text-muted d-block mt-2">Edits <code>usersc/includes/head_tags.php</code> between plugin markers. Backups kept with timestamps.</small>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Section: Menu Integration (Logo & Socials in Menu Tables) -->
  <div class="card mb-4">
    <div class="card-header"><strong>Menu Integration</strong> — Logo Block & Social Links in Menu Tables</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-8">
          <div class="border rounded p-3 h-100">
            <div class="fw-bold mb-2">Target Menu Item IDs</div>
            <form action="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php" method="post" class="mb-3">
              <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
              <input type="hidden" name="action" value="save_menu_targets">
              <label class="form-label">JSON Array (e.g., <code>[1, 2]</code>)</label>
              <textarea class="form-control" name="menu_target_ids" rows="3" placeholder="[ ]"><?= h(json_encode($menuTargetIds)) ?></textarea>
              <div class="mt-2 d-flex gap-2">
                <button class="btn btn-outline-primary">Save Targets</button>
                <a class="btn btn-outline-secondary" href="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php?action=discover_menu_candidates<?= $csrf ? '&csrf=' . urlencode($csrf) : '' ?>">Discover Candidates</a>
              </div>
              <small class="text-muted d-block mt-2">These are the menu rows we’ll patch (inside our markers) to render the logo/socials.</small>
            </form>

            <div class="d-flex gap-2">
              <form action="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php" method="post">
                <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
                <input type="hidden" name="action" value="apply_menu_patch">
                <button class="btn btn-success">Apply / Update Menu Content</button>
              </form>
              <form action="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php" method="post">
                <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
                <input type="hidden" name="action" value="diff_menu_patch">
                <button class="btn btn-outline-secondary">Show Diff</button>
              </form>
              <form action="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php" method="post" onsubmit="return confirm('Revert the last backup of the patched menu rows?');">
                <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
                <input type="hidden" name="action" value="revert_menu_patch">
                <button class="btn btn-outline-danger">Revert from Backup</button>
              </form>
            </div>
            <small class="text-muted d-block mt-2">Row-level backups are created before each write.</small>
          </div>
        </div>

        <div class="col-md-4">
          <div class="border rounded p-3 h-100">
            <div class="fw-bold mb-2">Social Links</div>
            <?php
              $platforms = [
                'x' => 'X (Twitter)',
                'facebook' => 'Facebook',
                'linkedin' => 'LinkedIn',
                'github' => 'GitHub',
                'youtube' => 'YouTube',
                'instagram' => 'Instagram',
              ];
            ?>
            <form action="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php" method="post">
              <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
              <input type="hidden" name="action" value="save_social_links">
              <?php foreach ($platforms as $key => $label):
                $cfg = $socialLinks[$key] ?? ['enabled' => false, 'url' => '', 'order' => 0];
              ?>
                <div class="border rounded p-2 mb-2">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="on_<?= h($key) ?>" name="social[<?= h($key) ?>][enabled]" value="1" <?= !empty($cfg['enabled']) ? 'checked' : '' ?> style ="shadow: 0 0 3px rgba(0,0,0,0.5);">
                    <label class="form-check-label" for="on_<?= h($key) ?>"><?= h($label) ?></label>
                  </div>
                  <label class="form-label mt-1">URL</label>
                  <input type="url" class="form-control" name="social[<?= h($key) ?>][url]" placeholder="https://..." value="<?= h($cfg['url'] ?? '') ?>">
                  <label class="form-label mt-1">Order</label>
                  <input type="number" class="form-control" name="social[<?= h($key) ?>][order]" value="<?= (int)($cfg['order'] ?? 0) ?>">
                </div>
              <?php endforeach; ?>
              <button class="btn btn-primary w-100">Save Social Links</button>
            </form>
            <small class="text-muted d-block mt-2">Only http/https URLs are accepted. Icons render in the menu block inside our markers.</small>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php
// Fetch menus so we can pick specific IDs (put this near the top where other queries are done)
$menusList = $db->query("SELECT id, menu_name FROM us_menus ORDER BY id ASC")->results();
$defaultFindPath   = '{{root}}users/images/logo.png'; // legacy stock path
$defaultReplaceRel = $logoPath ?: 'users/images/rebrand/logo.png'; // current plugin logo path
$defaultReplaceUrl = '{{root}}' . ltrim($defaultReplaceRel, '/');
?>

<!-- Section: Logo Path Search & Replace -->
<div class="card mb-4">
  <div class="card-header"><strong>Logo Path Search &amp; Replace</strong> — edit selected menu(s) only</div>
  <div class="card-body">
    <p class="text-muted mb-3">
      This updates the <code>brand_html</code> field of the selected rows in <code>us_menus</code>. A row-level backup is created before any write.
      Works with the encoded HTML in that column automatically.
    </p>

    <form action="<?= $usUrlRoot ?>usersc/plugins/rebrand/admin/process.php" method="post" class="mb-2">
      <?php if ($csrf): ?><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php endif; ?>
      <input type="hidden" name="action" value="menu_search_replace">

      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Menus to edit (hold Ctrl/Cmd for multi)</label>
          <select class="form-select" name="menu_ids[]" multiple size="6" required>
            <?php foreach ($menusList as $m): ?>
              <option value="<?= (int)$m->id ?>" <?= in_array((int)$m->id, [1,2], true) ? 'selected' : '' ?>>
                ID <?= (int)$m->id ?> — <?= h($m->menu_name ?? '') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="text-muted d-block mt-1">Tip: default selects IDs 1 and 2 (Main + Dashboard).</small>
        </div>

        <div class="col-md-4">
          <label class="form-label">Find (raw or with {{root}})</label>
          <input type="text" class="form-control" name="find" required
                 value="<?= h($defaultFindPath) ?>">
          <small class="text-muted">We’ll match both the raw string and its HTML-encoded form in <code>brand_html</code>.</small>
        </div>

        <div class="col-md-4">
          <label class="form-label">Replace with</label>
          <input type="text" class="form-control" name="replace"
                 value="<?= h($defaultReplaceUrl) ?>">
          <small class="text-muted">Default uses your plugin logo with <code>{{root}}</code>. Version is auto-appended.</small>
        </div>
      </div>

      <div class="form-check mt-3">
        <input class="form-check-input" type="checkbox" id="sr_append_ver" name="append_version" value="1" checked>
        <label class="form-check-label" for="sr_append_ver">Append cache-buster <code>?v=asset_version</code> to the replacement URL</label>
      </div>

      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-outline-secondary" name="dry_run" value="1">Dry-run (show counts only)</button>
        <button class="btn btn-primary">Replace in Selected Menus</button>
      </div>
    </form>

    <small class="text-muted">
      This tool does a simple string replace. If you’d like the richer marker-based block, use “Apply / Update Menu Content” above.
    </small>
  </div>
</div>

  <!-- Footer / Help -->
  <div class="text-muted mb-5">
    <div>Edits to <code>head_tags.php</code> and menu rows are always made between <code>&lt;!-- ReBrand START/END --&gt;</code> markers and backed up with timestamps.</div>
    <div>Need to roll back? Use the Revert buttons in each section or uninstall with “Disable &amp; Keep Data”.</div>
  </div>
</div>
