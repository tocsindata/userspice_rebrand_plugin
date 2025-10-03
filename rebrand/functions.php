<?php


// 1) Ensure the brand area for menu id=1 includes our mount <div id="rebrandsocial">
if (!function_exists('rebrand_social_mount_in_brand')) {
  function rebrand_social_mount_in_brand() {
    // Only let UID 1 mutate DB state
    global $user, $abs_us_root, $us_url_root;
    if (!isset($user) || (int)($user->data()->id ?? 0) !== 1) return;

    $db = DB::getInstance();

    // Prepare brand HTML (logo + mount) — this ONLY updates us_menus.id = 1
    $siteName = '';
    if (function_exists('getSettings')) {
      $settings = getSettings();
      $siteName = isset($settings->site_name) ? (string)$settings->site_name : '';
    } else {
      $q = $db->query("SELECT site_name FROM settings LIMIT 1");
      if ($q->count()) $siteName = (string)$q->first()->site_name;
    }

    $logoFs  = rtrim($abs_us_root, '/').$us_url_root.'users/images/logo.png';
    $logoWeb = $us_url_root.'users/images/logo.png';
    $v       = @file_exists($logoFs) ? @filemtime($logoFs) : time();

    $raw = '<a href="'.$us_url_root.'"><img src="'.$logoWeb.'?v='.$v.
           '" alt="'.htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8').'" class="img-fluid" /></a>'.
           '<div id="rebrandsocial"></div>';

    // If your navbar prints brand_html raw, store encoded to be safe
    $brandHtml = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');

    // Update only if different
    $q = $db->query("SELECT brand_html FROM us_menus WHERE id = ?", [1]);
    if ($q->count() && (string)$q->first()->brand_html !== $brandHtml) {
      $db->query("UPDATE us_menus SET brand_html = ? WHERE id = 1", [$brandHtml]);
    }
  }
}

// 2) Hydrate #rebrandsocial with items from the separate 'rebrand_social' menu (READ-ONLY)
if (!function_exists('rebrand_social_hydrate')) {
  function rebrand_social_hydrate() {
    $db = DB::getInstance();

    // Lookup the id of the rebrand_social menu (read-only)
    $menuId = 0;
    $q = $db->query("SELECT id FROM us_menus WHERE menu_name = ?", ['rebrand_social']);
    if ($q->count()) $menuId = (int)$q->first()->id;
    if ($menuId === 0) return;

    // Pull enabled items in order (READ ONLY from us_menu_items)
    $items = $db->query(
      "SELECT label, link, icon_class, link_target
         FROM us_menu_items
        WHERE menu = ? AND disabled = 0
     ORDER BY display_order ASC",
      [$menuId]
    )->results();

    // Build icon links
    $html = '';
    foreach ($items as $row) {
      $label = htmlspecialchars($row->label ?? '', ENT_QUOTES, 'UTF-8');
      $href  = htmlspecialchars($row->link ?? '#', ENT_QUOTES, 'UTF-8');
      $icon  = htmlspecialchars($row->icon_class ?? '', ENT_QUOTES, 'UTF-8');
      $tgt   = htmlspecialchars($row->link_target ?? '', ENT_QUOTES, 'UTF-8');
      $rel   = ($tgt === '_blank') ? ' rel="noopener"' : '';

      $html .= '<a class="rebrand-social-link" href="'.$href.'" title="'.$label.
               '" target="'.$tgt.'"'.$rel.'><i class="'.$icon.' fa-2x" aria-hidden="true"></i>'.
               '<span class="visually-hidden">'.$label.'</span></a> ';
    }

    // Safely inject via JSON (no addslashes issues)
    $payload = json_encode($html, JSON_UNESCAPED_SLASHES);
    if ($payload === false) return;

    echo "<script>
      document.addEventListener('DOMContentLoaded', function () {
        var mount = document.getElementById('rebrandsocial');
        if (mount) { mount.innerHTML = {$payload}; }
      });
    </script>";
  }
}


if (!function_exists('rebrand_sync_plugin_row')) {
  /**
   * Ensure us_plugins row is present and consistent for the rebrand plugin.
   * $status should be one of: 'installed', 'active', 'disabled' (your convention).
   */
  function rebrand_sync_plugin_row(string $status): void {
    // Load plugin name/version the canonical way
    require_once __DIR__ . '/plugin_info.php'; // defines $plugin_name, $plugin_version

    // UserSpice DB instance (no globals)
    $db = DB::getInstance();

    // Prepare updates JSON from plugin_version
    $updatesJson = null;
    if (!empty($plugin_version)) {
      // us_plugins.updates is a JSON array of versions
      $updatesJson = json_encode([$plugin_version], JSON_UNESCAPED_SLASHES);
    }

    // Upsert the row
    $existing = $db->query("SELECT id, updates FROM us_plugins WHERE plugin = ?", [$plugin_name])->first();

    $now = date('Y-m-d H:i:s');

    if ($existing && isset($existing->id)) {
      // Prefer to overwrite with current version array
      $db->update('us_plugins', (int)$existing->id, [
        'status'     => $status,
        'updates'    => $updatesJson,
        'last_check' => $now,
      ]);
    } else {
      $db->insert('us_plugins', [
        'plugin'     => $plugin_name,
        'status'     => $status,
        'updates'    => $updatesJson,
        'last_check' => $now,
      ]);
    }
  }
}