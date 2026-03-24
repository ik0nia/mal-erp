<?php
/**
 * Plugin Name: Malinco ERP Bridge
 * Plugin URI:  https://erp.malinco.ro
 * Description: Comunicare bidirecțională ERP ↔ WooCommerce: meta produse, furnizori, parametri custom, preț/stoc/disponibilitate automată.
 * Version:     2.0.0
 * Author:      Malinco ERP
 * Requires WC: 5.0
 * Requires PHP: 8.1
 */

if (! defined('ABSPATH')) {
    exit;
}

define('MERP_VERSION',    '2.1.0');
define('MERP_OPTION_KEY', 'malinco_erp_api_key');
define('MERP_NS',         'malinco-erp/v1');

// Logica de disponibilitate/preț activă doar dacă e pornită din setări
define('MERP_AVAILABILITY_ENABLED', (bool) get_option('merp_availability_enabled', false));

// ---------------------------------------------------------------------------
// Constante meta — Availability & Pricing
// Ajustați să coincidă cu cheile scrise de ERP în realitate.
// ---------------------------------------------------------------------------

// Meta scrise de ERP / sincronizare WinMentor
define('MERP_WM_STOCK',   '_wm_stock');        // int   — stoc WinMentor
define('MERP_WM_PRICE',   '_wm_price');        // float — preț WinMentor

// Meta scrisă de feed furnizor
define('MERP_SUPPLIER_PRICE', '_supplier_price'); // float — preț feed; 0/lipsă = fără feed

// Meta menținute de plugin
define('MERP_EVER_IN_STOCK',      '_ever_in_stock');      // 0|1
define('MERP_PRICING_SOURCE',     '_pricing_source');     // "winmentor"|"supplier"
define('MERP_AVAILABILITY_STATE', '_availability_state'); // debug
define('MERP_STOCK_LABEL',        '_stock_label');        // "in_stock"|"depozit"|"supplier_depozit"

// Override-uri la nivel de produs
define('MERP_LEAD_TIME_OVERRIDE_DAYS', '_lead_time_override_days');
define('MERP_LEAD_TIME_OVERRIDE_TEXT', '_lead_time_override_text');
define('MERP_VISIBILITY_OVERRIDE',     '_visibility_manual_override'); // 0|1

// Meta pe CPT furnizor
define('MERP_FURNIZOR_META',      '_furnizor');       // ID post CPT pe produs
define('MERP_FURNIZOR_LEAD_DAYS', '_lead_time_days'); // pe CPT furnizor
define('MERP_FURNIZOR_LEAD_TEXT', '_lead_time_text'); // pe CPT furnizor
define('MERP_FURNIZOR_CPT',       'furnizor');        // slug CPT furnizor

// ---------------------------------------------------------------------------
// REST API
// ---------------------------------------------------------------------------

add_action('rest_api_init', function () {

    // GET /version
    register_rest_route(MERP_NS, '/version', [
        'methods'             => 'GET',
        'callback'            => 'merp_get_version',
        'permission_callback' => 'merp_auth',
    ]);

    // POST /product/meta
    register_rest_route(MERP_NS, '/product/meta', [
        'methods'             => 'POST',
        'callback'            => 'merp_update_product_meta',
        'permission_callback' => 'merp_auth',
    ]);

    // POST /product/bulk-meta
    register_rest_route(MERP_NS, '/product/bulk-meta', [
        'methods'             => 'POST',
        'callback'            => 'merp_bulk_update_meta',
        'permission_callback' => 'merp_auth',
    ]);

    // GET /product/meta
    register_rest_route(MERP_NS, '/product/meta', [
        'methods'             => 'GET',
        'callback'            => 'merp_read_product_meta',
        'permission_callback' => 'merp_auth',
    ]);

    // POST /product/recalculate — recalculează disponibilitate + preț
    register_rest_route(MERP_NS, '/product/recalculate', [
        'methods'             => 'POST',
        'callback'            => 'merp_recalculate_products',
        'permission_callback' => 'merp_auth',
    ]);

    // POST /cache/flush
    register_rest_route(MERP_NS, '/cache/flush', [
        'methods'             => 'POST',
        'callback'            => 'merp_flush_cache',
        'permission_callback' => 'merp_auth',
    ]);

    // GET /options/{key}
    register_rest_route(MERP_NS, '/options/(?P<key>[a-zA-Z0-9_\-]+)', [
        'methods'             => 'GET',
        'callback'            => 'merp_get_option',
        'permission_callback' => 'merp_auth',
    ]);

    // POST /self-update
    register_rest_route(MERP_NS, '/self-update', [
        'methods'             => 'POST',
        'callback'            => 'merp_self_update',
        'permission_callback' => 'merp_auth',
    ]);
});

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

function merp_auth(WP_REST_Request $request): bool
{
    $stored = get_option(MERP_OPTION_KEY, '');
    if ($stored === '') {
        return false;
    }

    $sent = (string) ($request->get_header('X-ERP-Api-Key') ?? '');

    return hash_equals($stored, $sent);
}

// ---------------------------------------------------------------------------
// REST Handlers — Bridge
// ---------------------------------------------------------------------------

function merp_get_version(): WP_REST_Response
{
    return new WP_REST_Response([
        'version' => MERP_VERSION,
        'status'  => 'ok',
        'site'    => get_bloginfo('url'),
    ]);
}

function merp_update_product_meta(WP_REST_Request $request): WP_REST_Response
{
    $params = $request->get_json_params();
    $sku    = sanitize_text_field($params['sku']    ?? '');
    $wooId  = (int) ($params['woo_id'] ?? 0);
    $meta   = $params['meta'] ?? [];

    if (! is_array($meta) || empty($meta)) {
        return new WP_REST_Response(['success' => false, 'error' => 'Lipsesc câmpurile meta'], 400);
    }

    $productId = merp_resolve_product_id($wooId, $sku);

    if (! $productId) {
        return new WP_REST_Response(['success' => false, 'error' => 'Produs negăsit'], 404);
    }

    foreach ($meta as $key => $value) {
        update_post_meta($productId, sanitize_key($key), wp_unslash($value));
    }

    return new WP_REST_Response([
        'success'    => true,
        'product_id' => $productId,
        'updated'    => array_keys($meta),
    ]);
}

function merp_bulk_update_meta(WP_REST_Request $request): WP_REST_Response
{
    $params = $request->get_json_params();
    $items  = $params['items'] ?? [];

    if (! is_array($items)) {
        return new WP_REST_Response(['success' => false, 'error' => 'Format invalid'], 400);
    }

    $results = ['updated' => 0, 'failed' => 0, 'errors' => []];

    foreach ($items as $item) {
        $sku   = sanitize_text_field($item['sku']    ?? '');
        $wooId = (int) ($item['woo_id'] ?? 0);
        $meta  = $item['meta'] ?? [];

        $productId = merp_resolve_product_id($wooId, $sku);

        if (! $productId) {
            $results['failed']++;
            $results['errors'][] = ['sku' => $sku, 'woo_id' => $wooId, 'error' => 'Negăsit'];
            continue;
        }

        foreach ($meta as $key => $value) {
            update_post_meta($productId, sanitize_key($key), wp_unslash($value));
        }

        $results['updated']++;
    }

    return new WP_REST_Response(['success' => true, 'results' => $results]);
}

function merp_read_product_meta(WP_REST_Request $request): WP_REST_Response
{
    $sku   = sanitize_text_field($request->get_param('sku')    ?? '');
    $wooId = (int) ($request->get_param('woo_id') ?? 0);

    $productId = merp_resolve_product_id($wooId, $sku);

    if (! $productId) {
        return new WP_REST_Response(['success' => false, 'error' => 'Produs negăsit'], 404);
    }

    $allMeta = get_post_meta($productId);
    $filter  = sanitize_text_field($request->get_param('filter') ?? '');
    $result  = [];

    foreach ($allMeta as $key => $values) {
        if ($filter && strpos($key, $filter) === false) {
            continue;
        }
        $result[$key] = count($values) === 1 ? $values[0] : $values;
    }

    return new WP_REST_Response([
        'success'    => true,
        'product_id' => $productId,
        'meta'       => $result,
    ]);
}

function merp_recalculate_products(WP_REST_Request $request): WP_REST_Response
{
    $params      = $request->get_json_params();
    $product_ids = $params['product_ids'] ?? [];

    if (! is_array($product_ids) || empty($product_ids)) {
        return new WP_REST_Response(['success' => false, 'error' => 'product_ids lipsă'], 400);
    }

    $processed = 0;
    $skipped   = 0;

    foreach ($product_ids as $id) {
        $id = (int) $id;
        if ($id > 0 && get_post_type($id) === 'product') {
            merp_recalculate($id);
            $processed++;
        } else {
            $skipped++;
        }
    }

    return new WP_REST_Response([
        'success'   => true,
        'processed' => $processed,
        'skipped'   => $skipped,
    ]);
}

function merp_flush_cache(WP_REST_Request $request): WP_REST_Response
{
    $flushed = [];

    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients();
        $flushed[] = 'woocommerce_product_transients';
    }

    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
        $flushed[] = 'wp_object_cache';
    }

    if (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
        $flushed[] = 'w3tc';
    }
    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
        $flushed[] = 'wp_rocket';
    }
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
        $flushed[] = 'wp_super_cache';
    }

    return new WP_REST_Response(['success' => true, 'flushed' => $flushed]);
}

function merp_get_option(WP_REST_Request $request): WP_REST_Response
{
    $key   = sanitize_key($request->get_param('key'));
    $value = get_option($key, null);

    if ($value === null) {
        return new WP_REST_Response(['success' => false, 'error' => 'Opțiune negăsită'], 404);
    }

    return new WP_REST_Response(['success' => true, 'key' => $key, 'value' => $value]);
}

function merp_self_update(WP_REST_Request $request): WP_REST_Response
{
    $files = $request->get_file_params();

    if (empty($files['plugin']) || $files['plugin']['error'] !== UPLOAD_ERR_OK) {
        return new WP_REST_Response(['success' => false, 'error' => 'Fișier lipsă sau eroare upload'], 400);
    }

    $tmpFile  = $files['plugin']['tmp_name'];
    $mimeType = mime_content_type($tmpFile);

    if (! in_array($mimeType, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
        return new WP_REST_Response(['success' => false, 'error' => 'Nu este un fișier ZIP valid'], 400);
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();

    $result = unzip_file($tmpFile, WP_PLUGIN_DIR);

    if (is_wp_error($result)) {
        return new WP_REST_Response(['success' => false, 'error' => $result->get_error_message()], 500);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Plugin actualizat cu succes']);
}

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function merp_resolve_product_id(int $wooId, string $sku): int
{
    if ($wooId > 0 && get_post($wooId)) {
        return $wooId;
    }

    if ($sku !== '') {
        $id = wc_get_product_id_by_sku($sku);
        return $id ?: 0;
    }

    return 0;
}

// ---------------------------------------------------------------------------
// Availability & Pricing — Recalculare centrală
// ---------------------------------------------------------------------------

function merp_recalculate(int $product_id): void
{
    static $running = [];
    if (isset($running[$product_id])) {
        return;
    }
    $running[$product_id] = true;

    try {
        $product = wc_get_product($product_id);
        if (! $product || $product->is_type('variation')) {
            return;
        }

        $wm_stock       = (int)   get_post_meta($product_id, MERP_WM_STOCK, true);
        $wm_price       = (float) get_post_meta($product_id, MERP_WM_PRICE, true);
        $supplier_price = (float) get_post_meta($product_id, MERP_SUPPLIER_PRICE, true);
        $ever_in_stock  = (int)   get_post_meta($product_id, MERP_EVER_IN_STOCK, true);

        // ---- A: Preț ----
        if ($wm_stock > 0) {
            // A1 — în stoc WinMentor
            if ($wm_price > 0) {
                $product->set_regular_price((string) $wm_price);
            }
            $pricing_source = 'winmentor';

            if (! $ever_in_stock) {
                update_post_meta($product_id, MERP_EVER_IN_STOCK, 1);
                $ever_in_stock = 1;
            }
        } elseif ($product->backorders_allowed() && $ever_in_stock) {
            // A2 — fără stoc, backorders, a fost vreodată în stoc
            if ($supplier_price > 0) {
                $product->set_regular_price((string) $supplier_price);
            }
            $pricing_source = 'supplier';
        } else {
            // A3 — niciodată în stoc
            if ($supplier_price > 0) {
                $product->set_regular_price((string) $supplier_price);
            }
            $pricing_source = 'supplier';
        }

        update_post_meta($product_id, MERP_PRICING_SOURCE, $pricing_source);

        // ---- B: Status stoc ----
        if ($wm_stock > 0) {
            $product->set_stock_status('instock');
        } else {
            $product->set_stock_status($product->backorders_allowed() ? 'onbackorder' : 'outofstock');
        }

        // ---- C: Etichetă stoc ----
        if ($wm_stock > 0) {
            $stock_label = 'in_stock';
        } elseif ($ever_in_stock) {
            $stock_label = 'depozit';
        } else {
            $stock_label = 'supplier_depozit';
        }
        update_post_meta($product_id, MERP_STOCK_LABEL,        $stock_label);
        update_post_meta($product_id, MERP_AVAILABILITY_STATE, $stock_label);

        $product->save();

        // ---- E: Publicare automată ----
        if (! (int) get_post_meta($product_id, MERP_VISIBILITY_OVERRIDE, true)) {
            $post           = get_post($product_id);
            $current_status = $post ? $post->post_status : '';
            $new_status     = ($wm_stock <= 0 && $supplier_price <= 0) ? 'draft' : 'publish';

            if ($new_status !== $current_status && in_array($current_status, ['publish', 'draft', 'private'], true)) {
                wp_update_post(['ID' => $product_id, 'post_status' => $new_status]);

                error_log(sprintf(
                    '[MERP] Product %d (%s): %s → %s | wm_stock=%d supplier_price=%.4f',
                    $product_id,
                    $product->get_sku() ?: 'no-sku',
                    $current_status,
                    $new_status,
                    $wm_stock,
                    $supplier_price
                ));
            }
        }
    } finally {
        unset($running[$product_id]);
    }
}

// ---------------------------------------------------------------------------
// Availability — activ doar când e pornit din setări
// ---------------------------------------------------------------------------

if (MERP_AVAILABILITY_ENABLED) :

add_action('save_post_product', function (int $post_id): void {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    merp_recalculate($post_id);
}, 20);

add_action('updated_post_meta', function ($meta_id, int $post_id, string $meta_key): void {
    static $trigger_keys = [MERP_WM_STOCK, MERP_WM_PRICE, MERP_SUPPLIER_PRICE];
    if (in_array($meta_key, $trigger_keys, true) && get_post_type($post_id) === 'product') {
        merp_recalculate($post_id);
    }
}, 10, 3);

add_action('added_post_meta', function ($meta_id, int $post_id, string $meta_key): void {
    static $trigger_keys = [MERP_WM_STOCK, MERP_WM_PRICE, MERP_SUPPLIER_PRICE];
    if (in_array($meta_key, $trigger_keys, true) && get_post_type($post_id) === 'product') {
        merp_recalculate($post_id);
    }
}, 10, 3);

// ---------------------------------------------------------------------------
// Availability — Frontend (etichete stoc + timp livrare)
// ---------------------------------------------------------------------------

add_filter('woocommerce_get_availability_text', function (string $text, WC_Product $product): string {
    $label = (string) get_post_meta($product->get_id(), MERP_STOCK_LABEL, true);
    return match ($label) {
        'in_stock'         => 'În stoc',
        'depozit'          => 'Stoc depozit',
        'supplier_depozit' => 'În stoc depozit furnizor',
        default            => $text,
    };
}, 10, 2);

add_filter('woocommerce_get_availability_class', function (string $class, WC_Product $product): string {
    $label = (string) get_post_meta($product->get_id(), MERP_STOCK_LABEL, true);
    return match ($label) {
        'in_stock'         => 'in-stock',
        'depozit'          => 'available-on-backorder',
        'supplier_depozit' => 'available-on-backorder',
        default            => $class,
    };
}, 10, 2);

function merp_get_lead_time_text(int $product_id): string
{
    $override_text = trim((string) get_post_meta($product_id, MERP_LEAD_TIME_OVERRIDE_TEXT, true));
    if ($override_text !== '') {
        return 'Livrare estimată: ' . $override_text;
    }

    $override_days = (int) get_post_meta($product_id, MERP_LEAD_TIME_OVERRIDE_DAYS, true);
    if ($override_days > 0) {
        return 'Livrare estimată: ' . $override_days . ' ' . ($override_days === 1 ? 'zi' : 'zile');
    }

    $furnizor_id = (int) get_post_meta($product_id, MERP_FURNIZOR_META, true);
    if ($furnizor_id > 0) {
        $text = trim((string) get_post_meta($furnizor_id, MERP_FURNIZOR_LEAD_TEXT, true));
        if ($text !== '') {
            return 'Livrare estimată: ' . $text;
        }
        $days = (int) get_post_meta($furnizor_id, MERP_FURNIZOR_LEAD_DAYS, true);
        if ($days > 0) {
            return 'Livrare estimată: ' . $days . ' ' . ($days === 1 ? 'zi' : 'zile');
        }
    }

    return '';
}

// Pagina produsului — timp livrare sub etichetă stoc
add_action('woocommerce_single_product_summary', function (): void {
    global $product;
    if (! $product) {
        return;
    }
    $wm_stock = (int) get_post_meta($product->get_id(), MERP_WM_STOCK, true);
    if ($wm_stock > 0) {
        return;
    }
    $text = merp_get_lead_time_text($product->get_id());
    if ($text !== '') {
        echo '<p class="merp-lead-time">' . esc_html($text) . '</p>';
    }
}, 31);

// Catalog / loop — etichetă stoc + timp livrare
add_action('woocommerce_after_shop_loop_item_title', function (): void {
    global $product;
    if (! $product) {
        return;
    }

    $label      = (string) get_post_meta($product->get_id(), MERP_STOCK_LABEL, true);
    $label_text = match ($label) {
        'in_stock'         => 'În stoc',
        'depozit'          => 'Stoc depozit',
        'supplier_depozit' => 'În stoc depozit furnizor',
        default            => '',
    };
    if ($label_text !== '') {
        echo '<p class="merp-stock-label">' . esc_html($label_text) . '</p>';
    }

    $wm_stock = (int) get_post_meta($product->get_id(), MERP_WM_STOCK, true);
    if ($wm_stock <= 0) {
        $lt = merp_get_lead_time_text($product->get_id());
        if ($lt !== '') {
            echo '<p class="merp-lead-time">' . esc_html($lt) . '</p>';
        }
    }
}, 15);

add_action('wp_head', function (): void {
    if (! is_woocommerce()) {
        return;
    }
    echo '<style>
.merp-stock-label { font-size:.85em; color:#555; margin:2px 0 0; }
.merp-lead-time   { font-size:.82em; color:#777; margin:2px 0 0; font-style:italic; }
</style>';
});

// ---------------------------------------------------------------------------
// Availability — Admin UI pe product edit
// ---------------------------------------------------------------------------

add_action('woocommerce_product_options_general_product_data', function (): void {
    global $post;
    $pid = $post->ID;

    $wm_stock       = get_post_meta($pid, MERP_WM_STOCK, true);
    $wm_price       = get_post_meta($pid, MERP_WM_PRICE, true);
    $supplier_price = get_post_meta($pid, MERP_SUPPLIER_PRICE, true);
    $ever           = (int) get_post_meta($pid, MERP_EVER_IN_STOCK, true);
    $label          = (string) get_post_meta($pid, MERP_STOCK_LABEL, true);
    $source         = (string) get_post_meta($pid, MERP_PRICING_SOURCE, true);
    $ld_text        = get_post_meta($pid, MERP_LEAD_TIME_OVERRIDE_TEXT, true);
    $ld_days        = get_post_meta($pid, MERP_LEAD_TIME_OVERRIDE_DAYS, true);
    $vis_override   = get_post_meta($pid, MERP_VISIBILITY_OVERRIDE, true);

    $fmt = static function (?string $v): string {
        return $v !== '' && $v !== null && (float) $v > 0
            ? number_format((float) $v, 4) . ' RON'
            : '—';
    };
    $label_map = [
        'in_stock'         => 'În stoc',
        'depozit'          => 'Stoc depozit',
        'supplier_depozit' => 'În stoc depozit furnizor',
    ];
    ?>
    <div class="options_group">
        <p class="form-field" style="padding:6px 12px;">
            <strong>Disponibilitate Malinco ERP</strong>
            <span style="display:block;background:#f8f8f8;border:1px solid #ddd;padding:8px 10px;margin-top:6px;font-size:.85em;line-height:1.9;border-radius:3px;">
                Stoc WinMentor: <b><?php echo esc_html($wm_stock !== '' ? $wm_stock : '—'); ?></b>
                &nbsp;|&nbsp;
                Preț WinMentor: <b><?php echo esc_html($fmt($wm_price)); ?></b><br>
                Preț furnizor: <b><?php echo esc_html($fmt($supplier_price)); ?></b>
                &nbsp;|&nbsp;
                Vreodată în stoc: <b><?php echo $ever ? '<span style="color:green">Da</span>' : 'Nu'; ?></b><br>
                Etichetă stoc: <b><?php echo esc_html($label_map[$label] ?? ($label ?: '—')); ?></b>
                &nbsp;|&nbsp;
                Sursă preț: <b><?php echo esc_html($source ?: '—'); ?></b>
            </span>
        </p>

        <?php
        woocommerce_wp_text_input([
            'id'          => MERP_LEAD_TIME_OVERRIDE_TEXT,
            'label'       => 'Timp livrare (text override)',
            'value'       => $ld_text,
            'placeholder' => 'Ex: 1–5 zile lucrătoare',
            'description' => 'Suprascrie timpul furnizorului. Are prioritate față de câmpul numeric.',
            'desc_tip'    => true,
        ]);

        woocommerce_wp_text_input([
            'id'          => MERP_LEAD_TIME_OVERRIDE_DAYS,
            'label'       => 'Timp livrare (zile override)',
            'type'        => 'number',
            'value'       => $ld_days,
            'description' => 'Folosit dacă câmpul text e gol.',
            'desc_tip'    => true,
            'custom_attributes' => ['min' => '0', 'step' => '1'],
        ]);

        woocommerce_wp_checkbox([
            'id'          => MERP_VISIBILITY_OVERRIDE,
            'label'       => 'Override vizibilitate manual',
            'description' => 'Bifat = pluginul nu schimbă automat Draft/Publish.',
            'value'       => $vis_override,
            'cbvalue'     => '1',
        ]);
        ?>
    </div>
    <?php
});

add_action('woocommerce_process_product_meta', function (int $pid): void {
    $ld_text = sanitize_text_field($_POST[MERP_LEAD_TIME_OVERRIDE_TEXT] ?? '');
    update_post_meta($pid, MERP_LEAD_TIME_OVERRIDE_TEXT, $ld_text);

    $ld_days = max(0, (int) ($_POST[MERP_LEAD_TIME_OVERRIDE_DAYS] ?? 0));
    update_post_meta($pid, MERP_LEAD_TIME_OVERRIDE_DAYS, $ld_days ?: '');

    $vis = isset($_POST[MERP_VISIBILITY_OVERRIDE]) ? '1' : '0';
    update_post_meta($pid, MERP_VISIBILITY_OVERRIDE, $vis);
});

// ---------------------------------------------------------------------------
// Availability — Admin UI pe CPT furnizor
// ---------------------------------------------------------------------------

add_action('add_meta_boxes', function (): void {
    if (post_type_exists(MERP_FURNIZOR_CPT)) {
        add_meta_box(
            'merp_furnizor_lead_time',
            'Timp de livrare (ERP)',
            'merp_furnizor_meta_box',
            MERP_FURNIZOR_CPT,
            'side'
        );
    }
});

function merp_furnizor_meta_box(WP_Post $post): void
{
    wp_nonce_field('merp_furnizor_save', 'merp_furnizor_nonce');
    $days = get_post_meta($post->ID, MERP_FURNIZOR_LEAD_DAYS, true);
    $text = get_post_meta($post->ID, MERP_FURNIZOR_LEAD_TEXT, true);
    ?>
    <table style="width:100%;border-collapse:collapse;">
        <tr>
            <th style="padding:5px 0;text-align:left;font-weight:600;">Zile livrare</th>
            <td style="padding:5px 0;">
                <input type="number"
                       name="<?php echo esc_attr(MERP_FURNIZOR_LEAD_DAYS); ?>"
                       value="<?php echo esc_attr($days); ?>"
                       style="width:70px;" min="0" step="1">
            </td>
        </tr>
        <tr>
            <th style="padding:5px 0;text-align:left;font-weight:600;">Text livrare</th>
            <td style="padding:5px 0;">
                <input type="text"
                       name="<?php echo esc_attr(MERP_FURNIZOR_LEAD_TEXT); ?>"
                       value="<?php echo esc_attr($text); ?>"
                       style="width:100%;" placeholder="Ex: 1–5 zile">
                <p style="font-size:.82em;color:#666;margin:3px 0 0;">Suprascrie câmpul numeric.</p>
            </td>
        </tr>
    </table>
    <?php
}

add_action('save_post_' . MERP_FURNIZOR_CPT, function (int $post_id): void {
    if (
        ! isset($_POST['merp_furnizor_nonce'])
        || ! wp_verify_nonce($_POST['merp_furnizor_nonce'], 'merp_furnizor_save')
        || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        || ! current_user_can('edit_post', $post_id)
    ) {
        return;
    }

    $days = max(0, (int) ($_POST[MERP_FURNIZOR_LEAD_DAYS] ?? 0));
    update_post_meta($post_id, MERP_FURNIZOR_LEAD_DAYS, $days ?: '');

    $text = sanitize_text_field($_POST[MERP_FURNIZOR_LEAD_TEXT] ?? '');
    update_post_meta($post_id, MERP_FURNIZOR_LEAD_TEXT, $text);
});

endif; // MERP_AVAILABILITY_ENABLED

// ---------------------------------------------------------------------------
// WP-CLI — recalculare bulk
// ---------------------------------------------------------------------------

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('merp recalculate', function (array $args, array $assoc_args): void {
        $limit = (int) ($assoc_args['limit'] ?? 0);

        $query = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'fields'         => 'ids',
        ]);

        $ids   = $query->posts;
        $total = count($ids);
        WP_CLI::line("Produse de recalculat: {$total}");

        $bar = WP_CLI\Utils\make_progress_bar('Recalculare', $total);
        foreach ($ids as $id) {
            merp_recalculate((int) $id);
            $bar->tick();
        }
        $bar->finish();
        WP_CLI::success("{$total} produse recalculate.");
    }, [
        'shortdesc' => 'Recalculează disponibilitate și preț pentru toate produsele.',
        'synopsis'  => [[
            'type'        => 'assoc',
            'name'        => 'limit',
            'description' => 'Limitează numărul de produse (0 = toate).',
            'optional'    => true,
            'default'     => '0',
        ]],
    ]);
}

// ---------------------------------------------------------------------------
// Admin Settings Page
// ---------------------------------------------------------------------------

add_action('admin_menu', function () {
    add_options_page(
        'Malinco ERP Bridge',
        'ERP Bridge',
        'manage_options',
        'malinco-erp-bridge',
        'merp_settings_page'
    );
});

function merp_settings_page(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['merp_save'])) {
        check_admin_referer('merp_settings');
        $newKey = sanitize_text_field($_POST['merp_api_key'] ?? '');
        if ($newKey !== '') {
            update_option(MERP_OPTION_KEY, $newKey);
        }
        update_option('merp_availability_enabled', isset($_POST['merp_availability_enabled']) ? '1' : '0');
        echo '<div class="notice notice-success"><p>Setări salvate.</p></div>';
    }

    $currentKey          = get_option(MERP_OPTION_KEY, '');
    $availabilityEnabled = (bool) get_option('merp_availability_enabled', false);
    ?>
    <div class="wrap">
        <h1>Malinco ERP Bridge <span style="font-size:0.6em;background:#eee;padding:2px 8px;border-radius:4px;">v<?php echo esc_html(MERP_VERSION); ?></span></h1>

        <form method="post">
            <?php wp_nonce_field('merp_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="merp_api_key">API Key (secret)</label></th>
                    <td>
                        <input type="text" id="merp_api_key" name="merp_api_key"
                               value="<?php echo esc_attr($currentKey); ?>" class="regular-text" />
                        <p class="description">Trebuie să coincidă cu <code>WOO_PLUGIN_API_KEY</code> din ERP → Setări aplicație.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="merp_availability_enabled">Logică disponibilitate/preț</label></th>
                    <td>
                        <label>
                            <input type="checkbox" id="merp_availability_enabled" name="merp_availability_enabled" value="1"
                                   <?php checked($availabilityEnabled); ?> />
                            Activează recalcularea automată preț, stoc și publicare
                        </label>
                        <p class="description">Dezactivat = bridge-ul funcționează normal, dar logica de disponibilitate nu rulează.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Salvează', 'primary', 'merp_save'); ?>
        </form>

        <hr>
        <h2>Endpoint-uri disponibile</h2>
        <p>Base URL: <code><?php echo esc_html(rest_url(MERP_NS . '/')); ?></code></p>
        <p>Autentificare: header <code>X-ERP-Api-Key: {cheia_ta}</code></p>
        <table class="widefat" style="max-width:750px;">
            <thead><tr><th>Metodă</th><th>Endpoint</th><th>Descriere</th></tr></thead>
            <tbody>
                <tr><td><code>GET</code></td><td><code>/version</code></td><td>Versiune plugin + status</td></tr>
                <tr><td><code>GET</code></td><td><code>/product/meta?sku=&amp;woo_id=&amp;filter=</code></td><td>Citește meta unui produs</td></tr>
                <tr><td><code>POST</code></td><td><code>/product/meta</code></td><td>Actualizează meta unui produs</td></tr>
                <tr><td><code>POST</code></td><td><code>/product/bulk-meta</code></td><td>Actualizare meta pentru mai multe produse</td></tr>
                <tr><td><code>POST</code></td><td><code>/product/recalculate</code></td><td>Recalculează disponibilitate + preț (după sync ERP)</td></tr>
                <tr><td><code>POST</code></td><td><code>/cache/flush</code></td><td>Golește cache WooCommerce + plugin-uri cache</td></tr>
                <tr><td><code>GET</code></td><td><code>/options/{key}</code></td><td>Citește o opțiune WordPress</td></tr>
                <tr><td><code>POST</code></td><td><code>/self-update</code></td><td>Actualizare plugin din ERP (upload ZIP)</td></tr>
            </tbody>
        </table>

        <hr>
        <h2>Meta chei configurate</h2>
        <table class="widefat" style="max-width:750px;">
            <thead><tr><th>Constantă</th><th>Cheie meta</th><th>Descriere</th></tr></thead>
            <tbody>
                <tr><td><code>MERP_WM_STOCK</code></td><td><code><?php echo esc_html(MERP_WM_STOCK); ?></code></td><td>Stoc WinMentor (int)</td></tr>
                <tr><td><code>MERP_WM_PRICE</code></td><td><code><?php echo esc_html(MERP_WM_PRICE); ?></code></td><td>Preț WinMentor (float)</td></tr>
                <tr><td><code>MERP_SUPPLIER_PRICE</code></td><td><code><?php echo esc_html(MERP_SUPPLIER_PRICE); ?></code></td><td>Preț feed furnizor (float)</td></tr>
                <tr><td><code>MERP_FURNIZOR_META</code></td><td><code><?php echo esc_html(MERP_FURNIZOR_META); ?></code></td><td>ID post CPT furnizor pe produs</td></tr>
            </tbody>
        </table>
        <p style="color:#666;font-size:.9em;">Constantele pot fi redefinite într-un must-use plugin dacă cheile diferă.</p>
    </div>
    <?php
}
