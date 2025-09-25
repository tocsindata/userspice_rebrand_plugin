<?php
/**
 * ReBrand — settings.php
 * Shown inside: users/admin.php?view=plugins_config&plugin=rebrand
 * Renders boxed sections (Bootstrap cards) for:
 *  - Site Settings Editor
 *  - Brand Assets (Manual Upload)
 *  - Head Tags / Meta Manager
 *
 * No header/footer includes here — Plugin Manager provides the chrome.
 */
ini_set('display_errors', '1'); ini_set('display_startup_errors', '1'); error_reporting(E_ALL);

if (!isset($user) || (int)($user->data()->id ?? 0) !== 1) {
  die('Admin only.');
}

// bring in guarded helpers like rb_yes_no(), paths, etc.
require_once __DIR__.'/../functions/rebrand_functions_core.php';

// Helpers / paths
$iconsDirFs  = rtrim($abs_us_root.$us_url_root, '/').'/users/images/rebrand/icons';
$iconsDirUrl = $us_url_root.'users/images/rebrand/icons/';
@mkdir($iconsDirFs, 0755, true);

// Version file
$verFile = __DIR__.'/../storage/versions/asset_version.json';
$assetVersion = 1;
if (is_file($verFile)) {
  $raw = @file_get_contents($verFile);
  $dec = json_decode($raw ?: '1', true);
  if (is_int($dec)) $assetVersion = $dec;
}

// Fetch current site settings (fallback-safe)
$db = DB::getInstance();
$settingsRow = null;
try {
  $settingsRow = $db->query("SELECT * FROM settings LIMIT 1")->first();
} catch (Exception $e) {
  $settingsRow = (object)[
    'site_name'    => '',
    'site_url'     => '',
    'copyright'    => '',
    'contact_email'=> '',
  ];
}
$site_name     = $settingsRow->site_name    ?? '';
$site_url      = $settingsRow->site_url     ?? '';
$copyright     = $settingsRow->copyright    ?? '';
$contact_email = $settingsRow->contact_email?? '';

// Detect existing brand assets
$logoExisting = (is_file("$iconsDirFs/logo.svg") ? 'logo.svg' : (is_file("$iconsDirFs/logo.png") ? 'logo.png' : null));
$detected = [
  'favicon_ico'   => is_file("$iconsDirFs/favicon.ico"),
  'favicon_16'    => is_file("$iconsDirFs/favicon-16x16.png"),
  'favicon_32'    => is_file("$iconsDirFs/favicon-32x32.png"),
  'apple_touch'   => is_file("$iconsDirFs/apple-touch-icon.png"),
  'android_192'   => is_file("$iconsDirFs/android-chrome-192x192.png"),
  'android_512'   => is_file("$iconsDirFs/android-chrome-512x512.png"),
  'maskable_512'  => is_file("$iconsDirFs/maskable-512x512.png"),
  'og_image'      => is_file("$iconsDirFs/og-image.png"),
  'safari_pinned' => is_file("$iconsDirFs/safari-pinned-tab.svg"),
  'manifest'      => is_file("$iconsDirFs/site.webmanifest"),
];

// head_tags.php status
$headFile = rtrim($abs_us_root.$us_url_root, '/').'/users/includes/head_tags.php';
$headMtime = is_file($headFile) ? date('Y-m-d H:i:s', filemtime($headFile)) : null;
?>

<!-- ============================
     Site Settings Editor
============================= -->
<div class="card border-secondary-subtle shadow-sm mb-4">
  <div class="card-header d-flex align-items-center">
    <i class="bi bi-gear me-2"></i> Site Settings
  </div>
  <div class="card-body">
    <form method="post" action="<?=$us_url_root?>users/admin.php?view=plugins_config&plugin=rebrand&do=save_settings">
      <input type="hidden" name="csrf" value="<?=Token::generate()?>">

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Site Name</label>
          <input type="text" name="site_name" class="form-control" maxlength="150" value="<?=htmlspecialchars($site_name)?>">
          <div class="form-text">Shown in titles and nav areas.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Site URL</label>
          <input type="url" name="site_url" class="form-control" maxlength="255" value="<?=htmlspecialchars($site_url)?>">
          <div class="form-text">Example: https://example.com/ (must reflect your live base URL).</div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Contact Email</label>
          <input type="email" name="contact_email" class="form-control" maxlength="150" value="<?=htmlspecialchars($contact_email)?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Copyright</label>
          <input type="text" name="copyright" class="form-control" maxlength="255" value="<?=htmlspecialchars($copyright)?>">
          <div class="form-text">Example: &copy; <?=date('Y')?> Your Company.</div>
        </div>
      </div>

      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary" type="submit">Save</button>
        <button class="btn btn-outline-secondary" type="submit" name="revert" value="1">Revert to Last Backup</button>
        <button class="btn btn-secondary" type="submit" name="export" value="1">Export JSON</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================
     Brand Assets (Manual Upload)
============================= -->
<div class="card border-secondary-subtle shadow-sm mb-4">
  <div class="card-header d-flex align-items-center">
    <i class="bi bi-images me-2"></i> Brand Assets (Manual Upload)
    <span class="ms-auto small text-muted">Cache Version: <?= (int)$assetVersion ?></span>
  </div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data"
          action="<?=$us_url_root?>users/admin.php?view=plugins_config&plugin=rebrand&do=upload_assets">
      <input type="hidden" name="csrf" value="<?=Token::generate()?>">

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Favicon (.ico) <span class="text-danger">*</span></label>
          <input type="file" name="favicon_ico" accept=".ico" class="form-control">
          <div class="form-text">Required. Ideally contains 16/32/48 sizes.</div>
          <?php if ($detected['favicon_ico']): ?>
            <div class="mt-2 small">
              <strong>Current:</strong>
              <a href="<?=$iconsDirUrl?>favicon.ico?v=<?=$assetVersion?>" target="_blank">favicon.ico</a>
              <span class="text-muted"> (updated <?=date('Y-m-d H:i:s', filemtime("$iconsDirFs/favicon.ico"))?>)</span>
            </div>
          <?php else: ?>
            <div class="mt-2 small text-muted"><em>No file uploaded yet.</em></div>
          <?php endif; ?>
        </div>

        <div class="col-md-6">
          <label class="form-label">Main Logo (PNG or SVG) <span class="text-danger">*</span></label>
          <input type="file" name="logo" accept=".png,.svg" class="form-control">
          <div class="form-text">PNG ≥ 512px wide (transparent) or SVG. Saved as logo.png or logo.svg.</div>
          <?php if ($logoExisting): ?>
            <div class="mt-2 small">
              <strong>Current:</strong>
              <a href="<?=$iconsDirUrl.$logoExisting?>?v=<?=$assetVersion?>" target="_blank"><?=$logoExisting?></a>
              <span class="text-muted"> (updated <?=date('Y-m-d H:i:s', filemtime("$iconsDirFs/$logoExisting"))?>)</span>
            </div>
          <?php else: ?>
            <div class="mt-2 small text-muted"><em>No file uploaded yet.</em></div>
          <?php endif; ?>
        </div>

        <div class="col-md-4">
          <label class="form-label">Apple Touch Icon (180×180 PNG)</label>
          <input type="file" name="apple_touch" accept=".png" class="form-control">
          <?php if ($detected['apple_touch']): ?>
            <div class="mt-2 small"><a target="_blank" href="<?=$iconsDirUrl?>apple-touch-icon.png?v=<?=$assetVersion?>">apple-touch-icon.png</a></div>
          <?php endif; ?>
        </div>

        <div class="col-md-4">
          <label class="form-label">Android 192×192 (PNG)</label>
          <input type="file" name="android_192" accept=".png" class="form-control">
          <?php if ($detected['android_192']): ?>
            <div class="mt-2 small"><a target="_blank" href="<?=$iconsDirUrl?>android-chrome-192x192.png?v=<?=$assetVersion?>">android-chrome-192x192.png</a></div>
          <?php endif; ?>
        </div>

        <div class="col-md-4">
          <label class="form-label">Android 512×512 (PNG)</label>
          <input type="file" name="android_512" accept=".png" class="form-control">
          <?php if ($detected['android_512']): ?>
            <div class="mt-2 small"><a target="_blank" href="<?=$iconsDirUrl?>android-chrome-512x512.png?v=<?=$assetVersion?>">android-chrome-512x512.png</a></div>
          <?php endif; ?>
        </div>

        <div class="col-md-4">
          <label class="form-label">Maskable 512×512 (PNG)</label>
          <input type="file" name="maskable_512" accept=".png" class="form-control">
          <?php if ($detected['maskable_512']): ?>
            <div class="mt-2 small"><a target="_blank" href="<?=$iconsDirUrl?>maskable-512x512.png?v=<?=$assetVersion?>">maskable-512x512.png</a></div>
          <?php endif; ?>
        </div>

        <div class="col-md-4">
          <label class="form-label">Favicon 16×16 (PNG)</label>
          <input type="file" name="favicon_16" accept=".png" class="form-control">
          <?php if ($detected['favicon_16']): ?>
            <div class="mt-2 small"><a target="_blank" href="<?=$iconsDirUrl?>favicon-16x16.png?v=<?=$assetVersion?>">favicon-16x16.png</a></div>
          <?php endif; ?>
        </div>

        <div class="col-md-4">
          <label class="form-label">Favicon 32×32 (PNG)</label>
          <input type="file" name="favicon_32" accept=".png" class="form-control">
          <?php if ($detected['favicon_32']): ?>
            <div class="mt-2 small"><a target="_blank" href="<?=$iconsDirUrl?>favicon-32x32.png?v=<?=$assetVersion?>">favicon-32x32.png</a></div>
          <?php endif; ?>
        </div>

        <div class="col-md-6">
          <label class="form-label">Open Graph Image 1200×630 (PNG/JPG)</label>
          <input type="file" name="og_image" accept=".png,.jpg,.jpeg" class="form-control">
          <?php if ($detected['og_image']): ?>
            <div class="mt-2 small"><a target="_blank" href="<?=$iconsDirUrl?>og-image.png?v=<?=$assetVersion?>">og-image.png</a></div>
          <?php endif; ?>
        </div>

        <div class="col-md-6">
          <label class="form-label">Safari Pinned Tab (SVG)</label>
          <input type="file" name="safari_pinned" accept=".svg" class="form-control">
          <?php if ($detected['safari_pinned']): ?>
            <div class="mt-2 small"><a target="_blank" href="<?=$iconsDirUrl?>safari-pinned-tab.svg?v=<?=$assetVersion?>">safari-pinned-tab.svg</a></div>
          <?php endif; ?>
        </div>

        <div class="col-md-6">
          <label class="form-label">site.webmanifest</label>
          <input type="file" name="manifest" accept=".webmanifest,.json" class="form-control">
          <?php if ($detected['manifest']): ?>
            <div class="mt-2 small"><a target="_blank" href="<?=$iconsDirUrl?>site.webmanifest?v=<?=$assetVersion?>">site.webmanifest</a></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary">Save Assets</button>
        <button name="bump_version" value="1" class="btn btn-secondary" type="submit">Bump Cache Version</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================
     Head Tags / Meta Manager
============================= -->
<div class="card border-secondary-subtle shadow-sm mb-4">
  <div class="card-header d-flex align-items-center">
    <i class="bi bi-braces me-2"></i> Head Tags / Meta Manager
    <span class="ms-auto small text-muted">
      <?php if ($headMtime): ?>
        head_tags.php last updated: <?=$headMtime?>
      <?php else: ?>
        head_tags.php not found; will create on save
      <?php endif; ?>
    </span>
  </div>
  <div class="card-body">
    <form method="post" action="<?=$us_url_root?>users/admin.php?view=plugins_config&plugin=rebrand&do=patch_head">
      <input type="hidden" name="csrf" value="<?=Token::generate()?>">

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Meta Description</label>
          <textarea name="meta_description" class="form-control" rows="3" maxlength="300" placeholder="Short site description (≤ 300 chars)"></textarea>
          <div class="form-text">Used for SEO and social if OG description omitted.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Meta Author</label>
          <input type="text" name="meta_author" class="form-control" maxlength="120" placeholder="Company or author name">
        </div>

        <div class="col-md-4">
          <label class="form-label">Robots</label>
          <input type="text" name="meta_robots" class="form-control" maxlength="120" placeholder="index, follow">
          <div class="form-text">Leave blank to omit.</div>
        </div>

        <div class="col-md-4">
          <label class="form-label">Theme Color (hex)</label>
          <input type="text" name="theme_color" class="form-control" maxlength="7" placeholder="#111111">
          <div class="form-text">Used by mobile/OS UI chrome.</div>
        </div>

        <div class="col-md-4">
          <label class="form-label">Twitter Card Type</label>
          <select name="twitter_card" class="form-select">
            <option value="">(omit)</option>
            <option value="summary">summary</option>
            <option value="summary_large_image">summary_large_image</option>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">OG:Title (optional)</label>
          <input type="text" name="og_title" class="form-control" maxlength="140" placeholder="Overrides default page title">
        </div>

        <div class="col-md-6">
          <label class="form-label">OG:Site Name (optional)</label>
          <input type="text" name="og_site_name" class="form-control" maxlength="80" placeholder="Brand / site name">
        </div>

        <div class="col-12">
          <label class="form-label">Extra Head HTML (optional)</label>
          <textarea name="extra_head_html" class="form-control" rows="4" placeholder="Any additional &lt;meta&gt; or &lt;link&gt; tags you want injected verbatim."></textarea>
          <div class="form-text">No &lt;script&gt; tags here; keep it to meta/link.</div>
        </div>
      </div>

      <hr class="my-4">

      <div class="row g-3">
        <div class="col-12">
          <div class="small mb-2"><strong>Detected assets</strong> (tags will be emitted only if present):</div>
          <ul class="small mb-0">
            <li>favicon.ico: <?=rb_yes_no($detected['favicon_ico'])?></li>
            <li>favicon-16x16.png: <?=rb_yes_no($detected['favicon_16'])?></li>
            <li>favicon-32x32.png: <?=rb_yes_no($detected['favicon_32'])?></li>
            <li>apple-touch-icon.png: <?=rb_yes_no($detected['apple_touch'])?></li>
            <li>android 192/512:
              <?= $detected['android_192'] ? '<span class="text-success">192</span>' : '<span class="text-muted">192</span>' ?>
              /
              <?= $detected['android_512'] ? '<span class="text-success">512</span>' : '<span class="text-muted">512</span>' ?>
            </li>
            <li>maskable-512x512.png: <?=rb_yes_no($detected['maskable_512'])?></li>
            <li>og-image.png: <?=rb_yes_no($detected['og_image'])?></li>
            <li>safari-pinned-tab.svg: <?=rb_yes_no($detected['safari_pinned'])?></li>
            <li>site.webmanifest: <?=rb_yes_no($detected['manifest'])?></li>
          </ul>
        </div>
      </div>

      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary">Save &amp; Write head_tags.php</button>
        <a class="btn btn-outline-secondary" target="_blank" href="<?=$us_url_root?>users/includes/head_tags.php">View current head_tags.php</a>
      </div>
    </form>
  </div>
</div>
