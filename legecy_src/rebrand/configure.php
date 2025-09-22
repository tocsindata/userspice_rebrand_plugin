<?php
// file: public_html/usersc/plugins/rebrand/configure.php
if (!in_array($user->data()->id, $master_account)) {
  Redirect::to($us_url_root . 'users/admin.php');
} // only allow master accounts

include 'plugin_info.php';
pluginActive($plugin_name);
$db = DB::getInstance();
$logo_dir = $abs_us_root . $us_url_root . 'users/images/';
$logo_file = $logo_dir . 'logo.png';

$warnings = [];

if (!is_writable($logo_dir)) {
  $warnings[] = "The directory <code>$logo_dir</code> is not writable by the web server.";
}

if (file_exists($logo_file) && !is_writable($logo_file)) {
  $warnings[] = "The file <code>$logo_file</code> exists but is not writable.";
}

// Handle POST
// Handle POST
if (!empty($_POST)) {
  if (!Token::check(Input::get('csrf'))) {
    include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
  }

  $alt_text = Input::get('alt_text');
  $icon_position = Input::get('icon_position');
  $icon_size = Input::get('icon_size');
  $icon_color = Input::get('icon_color');
  $menu_id = (int)Input::get('menu_id');
  $logo_css = Input::get('logo_css', '', true); // Sanitized

  // Update plugin settings
  $settings = [
    'alt_text' => $alt_text,
    'icon_position' => $icon_position,
    'icon_size' => $icon_size,
    'icon_color' => $icon_color,
    'menu_id' => $menu_id,
    'logo_css' => $logo_css,
  ];

  if ($db->query("SELECT id FROM plg_tocsinrebrand_settings")->count()) {
    $db->update('plg_tocsinrebrand_settings', 1, $settings);
  } else {
    $db->insert('plg_tocsinrebrand_settings', $settings);
  }

  // Handle social icon updates
  if (isset($_POST['fa_icon_url'])) {
    $urls = $_POST['fa_icon_url'];
    $classes = $_POST['fa_icon_class'];
    $labels = $_POST['fa_icon_label'];
    $ids = $_POST['fa_icon_id'];

    foreach ($urls as $i => $url) {
      $url = trim($url);
      $class = trim($classes[$i] ?? '');
      $label = trim($labels[$i] ?? '');
      $id = (int)($ids[$i] ?? 0);

      if ($id > 0) {
        $db->update('plg_tocsinrebrand_icons', $id, [
          'url' => $url,
          'fa_class' => $class,
          'label' => $label,
        ]);
      } elseif (!empty($url)) {
        $db->insert('plg_tocsinrebrand_icons', [
          'url' => $url,
          'fa_class' => $class,
          'label' => $label,
        ]);
      }
    }
  }

  // Upload logo if requested
  $logo_path = $us_url_root . 'users/images/logo.png';
  $logo_abs_path = $abs_us_root . $logo_path;
  if (!empty($_FILES['logo_file']['tmp_name']) && isset($_POST['confirm_replace'])) {
    if (!move_uploaded_file($_FILES['logo_file']['tmp_name'], $logo_abs_path)) {
      Session::flash('msg', 'Upload failed: Unable to move logo.png');
      Redirect::to($us_url_root . 'users/admin.php?view=plugins_config&plugin=' . $plugin_name);
    }
  }

  // Update us_menus.brand_html
  $icon_html = '';
  if ($icon_position === 'under') {
    $icon_html .= "<div style='text-align:center;'>";
    $icons = $db->query("SELECT * FROM plg_tocsinrebrand_icons ORDER BY id")->results();
    foreach ($icons as $icon) {
      $style = $icon_color ? " style=\"color: {$icon_color}\"" : '';
      $size = $icon_size ? " {$icon_size}" : '';
      $icon_html .= "<a href=\"{$icon->url}\" target=\"_blank\"><i class=\"{$icon->fa_class}{$size}\"{$style}></i></a> ";
    }
    $icon_html .= "</div>";
  }

  $brand_html = "<a href=\"{{root}}\"><img src=\"{{root}}users/images/logo.png\" alt=\"" . htmlentities($alt_text) . "\" class=\"img-fluid\" /></a>" . $icon_html;
  $db->update('us_menus', $menu_id, ['brand_html' => htmlentities($brand_html)]);

  // Update `plg_tocsinrebrand` table (main data store)
  $domain = defined('__SITE_DOMAIN__') ? __SITE_DOMAIN__ : $_SERVER['HTTP_HOST'];
  $existing = $db->query("SELECT id FROM plg_tocsinrebrand WHERE domain = ?", [$domain])->first();

  $social_links = [];
  foreach ($urls as $i => $url) {
    if (trim($url)) {
      $social_links[] = [
        'url' => $url,
        'icon' => $classes[$i] ?? '',
        'label' => $labels[$i] ?? '',
        'size' => $icon_size,
        'color' => $icon_color,
      ];
    }
  }

  $data = [
    'domain' => $domain,
    'path' => $logo_path,
    'alt_text' => $alt_text,
    'width' => '200',  // Consider dynamic sizing later
    'height' => '200',
    'icon_placement' => $icon_position,
    'social_links' => json_encode($social_links),
    'logo_css' => $logo_css,
  ];

  if ($existing) {
    $db->update('plg_tocsinrebrand', $existing->id, $data);
  } else {
    $db->insert('plg_tocsinrebrand', $data);
  }

  if (!$db->error()) {
    Session::flash('msg', 'Branding updated successfully');
  } else {
    Session::flash('msg', 'Error updating branding: ' . $db->errorString());
  }

  Redirect::to($us_url_root . 'users/admin.php?view=plugins_config&plugin=' . $plugin_name);
}


$icons = $db->query("SELECT * FROM plg_tocsinrebrand_icons ORDER BY id")->results();
$menus = $db->query("SELECT id, menu_name FROM us_menus ORDER BY id")->results();
$settings = $db->query("SELECT * FROM plg_tocsinrebrand_settings")->first() ?? (object)[
  'alt_text' => '',
  'icon_position' => '',
  'icon_size' => '',
  'icon_color' => '',
  'menu_id' => '',
  'logo_css' => '',
];
?>
<div class="content mt-3">
  <div class="row">
    <div class="col-12">

<?php if (!empty($warnings)): ?>
  <div class="alert alert-warning">
    <?= implode("<br>", $warnings); ?>
  </div>
<?php endif; ?>

      <a href="<?= $us_url_root ?>users/admin.php?view=plugins">&larr; Plugin Manager</a>
      <h2 class="mt-3">Tocsin ReBrand Plugin</h2>
      <?php if (Session::exists('msg')): ?>
        <div class="alert alert-info"><?= Session::flash('msg') ?></div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data">
        <?= tokenHere(); ?>

        <div class="form-group">
          <label for="logo_file">Upload Logo (PNG):</label>
          <input type="file" name="logo_file" id="logo_file" class="form-control">
        </div>
        <div class="form-check">
          <input type="checkbox" name="confirm_replace" value="1" class="form-check-input" id="confirm_replace">
          <label class="form-check-label" for="confirm_replace">Overwrite existing logo.png</label>
        </div>

        <div class="form-group mt-3">
          <label for="alt_text">Alt Text:</label>
          <input type="text" name="alt_text" class="form-control" value="<?= $settings->alt_text ?>" required>
        </div>

        <div class="form-group">
          <label for="menu_id">Target Menu:</label>
          <select name="menu_id" class="form-control">
            <?php foreach ($menus as $menu): ?>
              <option value="<?= $menu->id ?>" <?= ($menu->id == $settings->menu_id) ? 'selected' : '' ?>>
                <?= $menu->id ?>: <?= $menu->menu_name ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="icon_position">Where should social icons appear?</label>
          <select name="icon_position" class="form-control">
            <option value="none" <?= $settings->icon_position === 'none' ? 'selected' : '' ?>>None</option>
            <option value="header" <?= $settings->icon_position === 'header' ? 'selected' : '' ?>>Header</option>
            <option value="footer" <?= $settings->icon_position === 'footer' ? 'selected' : '' ?>>Footer</option>
            <option value="both" <?= $settings->icon_position === 'both' ? 'selected' : '' ?>>Both</option>
            <option value="under" <?= $settings->icon_position === 'under' ? 'selected' : '' ?>>Under Logo</option>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group col-md-6">
            <label for="icon_size">Icon Size (fa-lg, fa-2x...):</label>
            <input type="text" name="icon_size" class="form-control" value="<?= $settings->icon_size ?>">
          </div>
          <div class="form-group col-md-6">
            <label for="icon_color">Icon Color (hex or class):</label>
            <input type="text" name="icon_color" class="form-control" value="<?= $settings->icon_color ?>">
          </div>
        </div>

        <div class="form-group mt-3">
          <label for="logo_css">Custom Logo CSS (scoped for logo only):</label>
          <textarea name="logo_css" class="form-control" rows="5"><?= htmlentities($logo_css) ?></textarea>
          <small class="form-text text-muted">This CSS will be injected in the &lt;head&gt; of every page. Avoid global selectors.</small>
        </div>

        <h5 class="mt-4">FontAwesome Social Icons</h5>
        <table class="table table-bordered" id="icon-table">
          <thead>
            <tr>
              <th>URL</th>
              <th>Icon Class</th>
              <th>Label</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($icons as $icon): ?>
              <tr>
                <td><input type="text" name="fa_icon_url[]" class="form-control" value="<?= $icon->url ?>"></td>
                <td><input type="text" name="fa_icon_class[]" class="form-control" value="<?= $icon->fa_class ?>"></td>
                <td>
                  <input type="text" name="fa_icon_label[]" class="form-control" value="<?= $icon->label ?>">
                  <input type="hidden" name="fa_icon_id[]" value="<?= $icon->id ?>">
                </td>
                <td><button type="button" class="btn btn-danger btn-sm remove-row">🗑</button></td>
              </tr>
            <?php endforeach; ?>
            <tr>
              <td><input type="text" name="fa_icon_url[]" class="form-control"></td>
              <td><input type="text" name="fa_icon_class[]" class="form-control"></td>
              <td><input type="text" name="fa_icon_label[]" class="form-control"><input type="hidden" name="fa_icon_id[]" value="0"></td>
              <td><button type="button" class="btn btn-success btn-sm add-row">➕</button></td>
            </tr>
          </tbody>
        </table>

        <button type="submit" class="btn btn-primary">Save Branding</button>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
  const table = document.getElementById("icon-table").querySelector("tbody");

  table.addEventListener("click", function (e) {
    if (e.target.classList.contains("add-row")) {
      const row = e.target.closest("tr").cloneNode(true);
      row.querySelectorAll("input").forEach(input => input.value = '');
      row.querySelector("input[name='fa_icon_id[]']").value = '0';
      table.appendChild(row);
    }
    if (e.target.classList.contains("remove-row")) {
      const row = e.target.closest("tr");
      if (table.rows.length > 2) row.remove();
    }
  });
});
</script>
