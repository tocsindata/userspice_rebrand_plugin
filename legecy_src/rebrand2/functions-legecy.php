<?php
// file: public_html/usersc/plugins/rebrand/functions.php

function getRebrandData($domain = null)
{
  if (is_null($domain)) {
    if (defined('__SITE_DOMAIN__')) {
      $domain = __SITE_DOMAIN__;
    } else {
      $domain = $_SERVER['HTTP_HOST'];
    }
  }

  $db = DB::getInstance();
  $record = $db->query("SELECT * FROM plg_tocsinrebrand WHERE domain = ?", [$domain])->first();

  if (!$record) return false;

  return [
    'domain' => $record->domain,
    'path' => $record->path,
    'alt_text' => $record->alt_text,
    'width' => $record->width,
    'height' => $record->height,
    'icon_placement' => $record->icon_placement ?? 'header-under-logo',
    'social_links' => json_decode($record->social_links ?? '[]', true),
    'logo_css' => $record->logo_css ?? '',
  ];
}

function renderBrandingIcons($domain = null, $position = 'header-under-logo')
{
  $data = getRebrandData($domain);
  if (!$data || empty($data['social_links'])) return;

  $placement = $data['icon_placement'] ?? 'header-under-logo';
  if (!in_array($placement, ['both', $position])) return;

  echo '<div class="branding-icons d-flex flex-wrap justify-content-center mt-2">';
  foreach ($data['social_links'] as $item) {
    if (empty($item['url']) || empty($item['icon'])) continue;

    $url = htmlspecialchars($item['url']);
    $icon = htmlspecialchars($item['icon']);
    $label = isset($item['label']) ? htmlspecialchars($item['label']) : '';
    $size = isset($item['size']) ? htmlspecialchars($item['size']) : '';
    $color = isset($item['color']) ? htmlspecialchars($item['color']) : '';

    $style = $color ? "style=\"color: $color\"" : "";

    echo '<a href="' . $url . '" target="_blank" rel="noopener" class="me-2 mb-2" title="' . $label . '">';
    echo '<i class="' . $icon . ' ' . $size . '" ' . $style . '></i>';
    echo '</a>';
  }
  echo '</div>';
}

function renderBrandingHeader($domain = null)
{
  $data = getRebrandData($domain);
  if (!$data) return;

  $img_url = $data['path'];
  $alt = htmlspecialchars($data['alt_text']);

  // Convert px to vw/vh if width/height present
  $vw = is_numeric($data['width']) ? round(($data['width'] / 1920) * 100, 2) . 'vw' : 'auto';
  $vh = is_numeric($data['height']) ? round(($data['height'] / 1080) * 100, 2) . 'vh' : 'auto';
  $style = "max-width:100%; height:auto;";
  if ($vw !== 'auto') $style = "width:$vw; $style";
  if ($vh !== 'auto') $style .= " max-height:$vh;";

  echo '<div class="us_brand full_screen text-center">';
  echo '<a href="/"><img src="' . $img_url . '" alt="' . $alt . '" style="' . $style . '"></a>';
  renderBrandingIcons($domain, 'header-under-logo');
  echo '</div>';
}
