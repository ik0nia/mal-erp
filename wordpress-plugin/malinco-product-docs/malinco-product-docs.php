<?php
/**
 * Plugin Name: Malinco — Documente produse
 * Description: Sistem custom pentru documentele produselor (fișe tehnice, certificate, declarații). Tabel dedicat, migrare din woo-product-attachment, afișare în cont pe produsele cumpărate, upload din ERP prin API.
 * Version: 1.0.0
 * Author: Malinco
 */

if (!defined('ABSPATH')) { exit; }

define('MPDOCS_VERSION', '1.0.0');
define('MPDOCS_TABLE', 'malinco_product_docs');
define('MPDOCS_API_KEY_OPTION', 'malinco_erp_api_key'); // aceeași cheie ca malinco-erp-bridge

/* ─────────────────────────── Tabel ─────────────────────────── */

function mpdocs_table(): string {
    global $wpdb;
    return $wpdb->prefix . MPDOCS_TABLE;
}

function mpdocs_install(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = mpdocs_table();
    dbDelta("CREATE TABLE {$t} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        kind VARCHAR(20) NOT NULL DEFAULT 'file',
        attachment_id BIGINT UNSIGNED NULL,
        url TEXT NULL,
        description TEXT NULL,
        position INT NOT NULL DEFAULT 0,
        source VARCHAR(20) NOT NULL DEFAULT 'manual',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY product_id (product_id)
    ) {$wpdb->get_charset_collate()};");
}
register_activation_hook(__FILE__, 'mpdocs_install');
add_action('after_setup_theme', function () {
    if (get_option('mpdocs_installed') !== MPDOCS_VERSION) {
        mpdocs_install();
        update_option('mpdocs_installed', MPDOCS_VERSION);
    }
});

/* ─────────────────── Migrare din woo-product-attachment ─────────────────── */

/**
 * Migrează documentele unui produs din meta wcpoa_* în tabelul nostru.
 * Non-distructiv: nu atinge datele wcpoa. Idempotent: șterge întâi rândurile 'migrated' ale produsului.
 */
function mpdocs_migrate_product(int $product_id): int {
    global $wpdb;
    $ids   = get_post_meta($product_id, 'wcpoa_attachments_id', true);
    if (!is_array($ids) || empty($ids)) { return 0; }
    $names = get_post_meta($product_id, 'wcpoa_attachment_name', true);
    $urls  = get_post_meta($product_id, 'wcpoa_attachment_url', true);
    $exts  = get_post_meta($product_id, 'wcpoa_attachment_ext_url', true);
    $descs = get_post_meta($product_id, 'wcpoa_attachment_description', true);

    $t = mpdocs_table();
    $wpdb->delete($t, ['product_id' => $product_id, 'source' => 'migrated']);

    // Array-urile wcpoa pot avea lungimi diferite → iterăm pe lungimea maximă (altfel pierdem sloturi).
    $maxlen = max(
        is_array($ids)   ? count($ids)   : 0,
        is_array($urls)  ? count($urls)  : 0,
        is_array($exts)  ? count($exts)  : 0,
        is_array($names) ? count($names) : 0
    );

    $n = 0;
    for ($i = 0; $i < $maxlen; $i++) {
        $raw_url = is_array($urls) ? trim((string) ($urls[$i] ?? '')) : '';
        $ext_url = is_array($exts) ? trim((string) ($exts[$i] ?? '')) : '';
        $kind = ''; $attachment_id = null; $url = null;

        if ($raw_url !== '' && ctype_digit($raw_url)) {
            $kind = 'file'; $attachment_id = (int) $raw_url;
        } elseif ($ext_url !== '') {
            $kind = 'url'; $url = esc_url_raw($ext_url);
        } else {
            continue; // slot gol
        }

        $title = is_array($names) ? trim((string) ($names[$i] ?? '')) : '';
        if ($title === '') { $title = 'Document'; }
        $desc = is_array($descs) ? trim((string) ($descs[$i] ?? '')) : '';

        $wpdb->insert($t, [
            'product_id'    => $product_id,
            'title'         => $title,
            'kind'          => $kind,
            'attachment_id' => $attachment_id,
            'url'           => $url,
            'description'   => $desc,
            'position'      => (int) $i,
            'source'        => 'migrated',
        ]);
        $n++;
    }
    return $n;
}

/** Migrează toate produsele. Returnează [produse, documente]. */
function mpdocs_migrate_all(): array {
    global $wpdb;
    $pids = $wpdb->get_col("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key='wcpoa_attachments_id'");
    $prod = 0; $docs = 0;
    foreach ($pids as $pid) {
        $c = mpdocs_migrate_product((int) $pid);
        if ($c > 0) { $prod++; $docs += $c; }
    }
    return [$prod, $docs];
}

/**
 * Descarcă local (pe CDN) documentele din linkuri externe wcpoa și le atașează.
 * Politică: livrăm totul de la noi. Idempotent (șterge întâi rândurile 'harvested').
 * Returnează [descarcate, esuate].
 */
function mpdocs_harvest_external(): array {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $pids = $wpdb->get_col("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key='wcpoa_attachment_ext_url'");
    $t = mpdocs_table();
    $done = 0; $fail = 0;
    foreach ($pids as $pid) {
        $pid   = (int) $pid;
        $exts  = get_post_meta($pid, 'wcpoa_attachment_ext_url', true);
        if (!is_array($exts)) { continue; }
        $names = get_post_meta($pid, 'wcpoa_attachment_name', true);
        $wpdb->delete($t, ['product_id' => $pid, 'source' => 'harvested']);
        foreach ($exts as $i => $e) {
            $e = trim((string) $e);
            if ($e === '') { continue; }
            $tmp = download_url($e, 30);
            if (is_wp_error($tmp)) { $fail++; continue; }
            $fn = basename((string) parse_url($e, PHP_URL_PATH));
            if ($fn === '' || strpos($fn, '.') === false) { $fn = 'fisa-tehnica.pdf'; }
            $title = is_array($names) ? trim((string) ($names[$i] ?? '')) : '';
            if ($title === '' || mb_strtolower($title) === 'descarca fisa') { $title = 'Fișă tehnică'; }
            $aid = media_handle_sideload(['name' => sanitize_file_name(rawurldecode($fn)), 'tmp_name' => $tmp], $pid, $title);
            if (is_wp_error($aid)) { @unlink($tmp); $fail++; continue; }
            $pos = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(position),-1)+1 FROM {$t} WHERE product_id=%d", $pid));
            $wpdb->insert($t, ['product_id' => $pid, 'title' => $title, 'kind' => 'file', 'attachment_id' => (int) $aid, 'position' => $pos, 'source' => 'harvested']);
            $done++;
        }
    }
    return [$done, $fail];
}

// Comandă WP-CLI: wp malinco-docs migrate
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('malinco-docs harvest', function () {
        list($d, $f) = mpdocs_harvest_external();
        WP_CLI::success("Descărcate local pe CDN: {$d} | eșuate: {$f}.");
    });
    WP_CLI::add_command('malinco-docs migrate', function () {
        list($p, $d) = mpdocs_migrate_all();
        WP_CLI::success("Migrat: {$p} produse, {$d} documente.");
    });
    WP_CLI::add_command('malinco-docs stats', function () {
        global $wpdb; $t = mpdocs_table();
        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}");
        $prods = (int) $wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM {$t}");
        WP_CLI::log("Tabel: {$rows} documente pe {$prods} produse.");
    });
}

/* ─────────────────────────── Citire ─────────────────────────── */

/**
 * Documentele unui produs, cu URL rezolvat. Array de ['title','url','description','ext','kind'].
 */
function mpdocs_for_product(int $product_id): array {
    global $wpdb;
    $t = mpdocs_table();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t} WHERE product_id = %d ORDER BY position ASC, id ASC", $product_id
    ));
    $out = [];
    foreach ($rows as $r) {
        $url = ($r->kind === 'file' && $r->attachment_id)
            ? wp_get_attachment_url((int) $r->attachment_id)
            : (string) $r->url;
        if (!$url) { continue; }
        $ext = strtoupper(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: '');
        $out[] = [
            'id'          => (int) $r->id,
            'title'       => (string) $r->title,
            'url'         => $url,
            'description' => (string) $r->description,
            'ext'         => $ext,
            'kind'        => (string) $r->kind,
        ];
    }
    return $out;
}

/** ID-urile produselor cumpărate de un client (comenzi finalizate/în procesare). */
function mpdocs_customer_product_ids(int $user_id): array {
    if (!$user_id) { return []; }
    $orders = wc_get_orders([
        'customer_id' => $user_id,
        'status'      => ['completed', 'processing', 'on-hold'],
        'limit'       => -1,
        'return'      => 'ids',
    ]);
    $pids = [];
    foreach ($orders as $oid) {
        $o = wc_get_order($oid);
        if (!$o) { continue; }
        foreach ($o->get_items() as $item) {
            $pid = $item->get_product_id();
            if ($pid) { $pids[$pid] = true; }
        }
    }
    return array_keys($pids);
}

/* ─────────────────── Iconiță după extensie ─────────────────── */
function mpdocs_ext_badge(string $ext): string {
    $ext = $ext ?: 'DOC';
    $colors = ['PDF' => '#d42b2b', 'DOC' => '#2563eb', 'DOCX' => '#2563eb', 'XLS' => '#059669', 'XLSX' => '#059669', 'ZIP' => '#7c3aed', 'JPG' => '#f59e0b', 'PNG' => '#f59e0b'];
    $c = $colors[$ext] ?? '#64748b';
    return '<span style="display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:44px;padding:0 6px;background:' . $c . ';color:#fff;font-size:10px;font-weight:800;border-radius:6px;letter-spacing:.3px;">' . esc_html($ext) . '</span>';
}

/* ─────────────────── Afișare în CONT (produse cumpărate) ─────────────────── */

add_filter('woocommerce_account_menu_items', function ($items) {
    if (isset($items['downloads'])) { $items['downloads'] = 'Documente'; }
    return $items;
}, 30);

add_action('init', function () {
    remove_action('woocommerce_account_downloads_endpoint', 'woocommerce_account_downloads');
    add_action('woocommerce_account_downloads_endpoint', 'mpdocs_render_account');
});

function mpdocs_render_account(): void {
    $uid    = get_current_user_id();
    $pids   = mpdocs_customer_product_ids($uid);
    $blocks = [];
    foreach ($pids as $pid) {
        $docs = mpdocs_for_product((int) $pid);
        if ($docs) { $blocks[$pid] = $docs; }
    }

    if (!$blocks) {
        echo '<div class="woocommerce-info" style="margin:0;">Momentan nu ai documente disponibile. Fișele tehnice, certificatele și declarațiile apar automat aici pentru produsele comandate care au fișiere atașate.</div>';
        return;
    }

    echo '<p style="font-size:14px;color:#6b7280;line-height:1.7;margin:0 0 20px;">Documentele produselor pe care le-ai comandat — fișe tehnice, certificate, declarații de performanță. Le poți descărca oricând.</p>';
    echo '<div class="mpdocs-account" style="display:flex;flex-direction:column;gap:16px;">';
    foreach ($blocks as $pid => $docs) {
        $p     = wc_get_product($pid);
        if (!$p) { continue; }
        $thumb = $p->get_image_id() ? wp_get_attachment_image_url($p->get_image_id(), 'thumbnail') : wc_placeholder_img_src('thumbnail');
        echo '<div class="mpdocs-prod" style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">';
        echo '<a href="' . esc_url(get_permalink($pid)) . '" style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e5e7eb;text-decoration:none;">';
        echo '<img src="' . esc_url($thumb) . '" alt="" width="44" height="44" style="width:44px;height:44px;border-radius:8px;object-fit:cover;flex:0 0 44px;">';
        echo '<span style="font-weight:700;color:#111827;font-size:14px;line-height:1.35;">' . esc_html($p->get_name()) . '</span></a>';
        echo '<div style="padding:6px 8px;">';
        foreach ($docs as $d) {
            echo '<a href="' . esc_url($d['url']) . '" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:8px;text-decoration:none;color:#374151;">';
            echo mpdocs_ext_badge($d['ext']);
            echo '<span style="flex:1;font-weight:600;font-size:14px;">' . esc_html($d['title']);
            if ($d['description']) { echo '<span style="display:block;font-weight:400;font-size:12px;color:#9ca3af;">' . esc_html($d['description']) . '</span>'; }
            echo '</span>';
            echo '<span style="color:#d42b2b;font-weight:700;font-size:13px;white-space:nowrap;">Descarcă &darr;</span>';
            echo '</a>';
        }
        echo '</div></div>';
    }
    echo '</div>';
}

/* ─────────────────── Afișare pe PAGINA PRODUS (cu switch) ─────────────────── */

add_action('malinco_product_extra_sections', function ($product_id) {
    if (get_option('mpdocs_frontend') !== '1') { return; } // OFF până retragem woo-product-attachment
    $docs = mpdocs_for_product((int) $product_id);
    if (!$docs) { return; }
    echo '<section class="prod-sec mpdocs-product"><h2 class="prod-sec-title">Documente &amp; fișe tehnice</h2><div style="display:flex;flex-direction:column;gap:8px;">';
    foreach ($docs as $d) {
        echo '<a href="' . esc_url($d['url']) . '" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;color:#374151;">';
        echo mpdocs_ext_badge($d['ext']);
        echo '<span style="flex:1;font-weight:600;">' . esc_html($d['title']) . '</span>';
        echo '<span style="color:#d42b2b;font-weight:700;">Descarcă &darr;</span></a>';
    }
    echo '</div></section>';
}, 10);

/* ─────────────────── REST API pentru ERP (upload documente) ─────────────────── */

add_action('rest_api_init', function () {
    register_rest_route('malinco-pdocs/v1', '/attach', [
        'methods'             => 'POST',
        'permission_callback' => 'mpdocs_rest_auth',
        'callback'            => 'mpdocs_rest_attach',
    ]);
    register_rest_route('malinco-pdocs/v1', '/product/(?P<id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => 'mpdocs_rest_auth',
        'callback'            => 'mpdocs_rest_list',
    ]);
    register_rest_route('malinco-pdocs/v1', '/doc/(?P<id>\d+)', [
        'methods'             => 'DELETE',
        'permission_callback' => 'mpdocs_rest_auth',
        'callback'            => 'mpdocs_rest_delete',
    ]);
});

function mpdocs_rest_auth(WP_REST_Request $request): bool {
    $stored = (string) get_option(MPDOCS_API_KEY_OPTION, '');
    $sent   = (string) ($request->get_header('X-ERP-Api-Key') ?? '');
    return $stored !== '' && hash_equals($stored, $sent);
}

function mpdocs_rest_attach(WP_REST_Request $request) {
    global $wpdb;
    $pid = (int) $request->get_param('product_id');
    if (!$pid && $request->get_param('sku')) {
        $pid = (int) wc_get_product_id_by_sku(sanitize_text_field((string) $request->get_param('sku')));
    }
    if (!$pid || get_post_type($pid) !== 'product') {
        return new WP_REST_Response(['error' => 'Produs inexistent'], 404);
    }
    $title = sanitize_text_field((string) ($request->get_param('title') ?: 'Document'));
    $desc  = sanitize_textarea_field((string) ($request->get_param('description') ?: ''));
    $ext_url = esc_url_raw((string) $request->get_param('url'));
    $file_b64 = (string) $request->get_param('file_base64');
    $filename = sanitize_file_name((string) $request->get_param('filename'));

    $t   = mpdocs_table();
    $pos = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(position),-1)+1 FROM {$t} WHERE product_id=%d", $pid));

    if ($file_b64 !== '') {
        $data = base64_decode($file_b64, true);
        if ($data === false) { return new WP_REST_Response(['error' => 'base64 invalid'], 400); }
        if (!$filename) { $filename = 'document.pdf'; }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $upload = wp_upload_bits($filename, null, $data);
        if (!empty($upload['error'])) { return new WP_REST_Response(['error' => $upload['error']], 500); }
        $ft  = wp_check_filetype($filename);
        $aid = wp_insert_attachment([
            'post_mime_type' => $ft['type'] ?: 'application/octet-stream',
            'post_title'     => $title,
            'post_status'    => 'inherit',
        ], $upload['file'], $pid);
        if (is_wp_error($aid)) { return new WP_REST_Response(['error' => $aid->get_error_message()], 500); }
        wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $upload['file']));
        $wpdb->insert($t, ['product_id' => $pid, 'title' => $title, 'kind' => 'file', 'attachment_id' => $aid, 'description' => $desc, 'position' => $pos, 'source' => 'erp']);
        return new WP_REST_Response(['ok' => true, 'doc_id' => (int) $wpdb->insert_id, 'attachment_id' => (int) $aid, 'url' => wp_get_attachment_url($aid)], 200);
    }

    if ($ext_url !== '') {
        // Politică: livrăm totul de la noi → descărcăm fișierul extern local pe CDN (nu hotlink).
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url($ext_url, 30);
        if (!is_wp_error($tmp)) {
            $fn = $filename ?: (basename((string) parse_url($ext_url, PHP_URL_PATH)) ?: 'document.pdf');
            $aid = media_handle_sideload(['name' => sanitize_file_name($fn), 'tmp_name' => $tmp], $pid, $title);
            if (is_wp_error($aid)) { @unlink($tmp); }
            else {
                $wpdb->insert($t, ['product_id' => $pid, 'title' => $title, 'kind' => 'file', 'attachment_id' => (int) $aid, 'description' => $desc, 'position' => $pos, 'source' => 'erp']);
                return new WP_REST_Response(['ok' => true, 'doc_id' => (int) $wpdb->insert_id, 'attachment_id' => (int) $aid, 'url' => wp_get_attachment_url($aid), 'self_hosted' => true], 200);
            }
        }
        // Fallback dacă descărcarea eșuează: păstrăm linkul ca să nu pierdem referința.
        $wpdb->insert($t, ['product_id' => $pid, 'title' => $title, 'kind' => 'url', 'url' => $ext_url, 'description' => $desc, 'position' => $pos, 'source' => 'erp']);
        return new WP_REST_Response(['ok' => true, 'doc_id' => (int) $wpdb->insert_id, 'url' => $ext_url, 'self_hosted' => false], 200);
    }

    return new WP_REST_Response(['error' => 'Lipsă file_base64+filename sau url'], 400);
}

function mpdocs_rest_list(WP_REST_Request $request): WP_REST_Response {
    $pid = (int) $request['id'];
    return new WP_REST_Response(['product_id' => $pid, 'docs' => mpdocs_for_product($pid)], 200);
}

function mpdocs_rest_delete(WP_REST_Request $request): WP_REST_Response {
    global $wpdb;
    $id = (int) $request['id'];
    $ok = $wpdb->delete(mpdocs_table(), ['id' => $id]);
    return new WP_REST_Response(['ok' => (bool) $ok], $ok ? 200 : 404);
}
