<?php
/**
 * Plugin Name: Malinco — Star BT (plată în rate)
 * Description: Promovare unificată „Plată în 3 sau 6 rate fără dobândă cu cardul Star BT" pe homepage, pagina produs, coș și finalizare comandă. Un singur switch (WooCommerce → Setări → Star BT) sau dezactivarea plugin-ului o ascunde de peste tot.
 * Version: 1.0.0
 * Author: Ikonia Agency
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) exit;

define('MALINCO_STAR_BT_VER', '1.0.0');
define('MALINCO_STAR_BT_URL', plugin_dir_url(__FILE__));

/* ── Config helpers ──────────────────────────────────────────────────────── */
function malinco_star_bt_enabled(): bool {
    $on = get_option('malinco_star_bt_enabled', 'yes') === 'yes';
    return (bool) apply_filters('malinco_star_bt_enabled', $on);
}
function malinco_star_bt_min(): float {
    return (float) apply_filters('malinco_star_bt_min_amount', (float) get_option('malinco_star_bt_min', 100));
}
function malinco_star_bt_rates(): array {
    $r = array_map('intval', (array) apply_filters('malinco_star_bt_rates', [3, 6]));
    $r = array_values(array_unique(array_filter($r)));
    sort($r);
    return $r ?: [3, 6];
}
function malinco_star_bt_shield(): string { return MALINCO_STAR_BT_URL . 'assets/bt-shield.svg'; }
function malinco_star_bt_card(): string   { return MALINCO_STAR_BT_URL . 'assets/star-card.png'; }
function malinco_star_bt_regulament(): string {
    return (string) apply_filters('malinco_star_bt_regulament',
        (string) get_option('malinco_star_bt_regulament', 'https://www.starbt.ro/storage/app/media/Regulamentul-oficial-al-programului-de-plata-in-rate-fara-dobanda.pdf'));
}
/* Link „Regulament" reutilizabil */
function malinco_star_bt_reg_link(string $label = 'Regulament'): string {
    $u = malinco_star_bt_regulament();
    return $u ? ' <a class="btstar-reg" href="' . esc_url($u) . '" target="_blank" rel="noopener nofollow">' . esc_html($label) . '</a>' : '';
}

/* Logo mic (scut BT + wordmark „Star") reutilizabil */
function malinco_star_bt_logo(): string {
    return '<span class="btstar__logo">'
        . '<img src="' . esc_url(malinco_star_bt_shield()) . '" alt="Banca Transilvania" class="btstar__shield" width="21" height="24">'
        . '<span class="btstar__word">Star</span></span>';
}

/* Linie „de la X/lună (6 rate) · Y/lună (3 rate)" pentru o sumă dată */
function malinco_star_bt_rate_line(float $amount): string {
    $rates = malinco_star_bt_rates();
    rsort($rates); // cel mai mare nr de rate (rata cea mai mică) primul
    $parts = [];
    foreach ($rates as $n) {
        $parts[] = '<span class="msbt-amt">' . wc_price($amount / $n) . '</span>/lună <span class="msbt-n">(' . $n . ' rate)</span>';
    }
    return implode(' &middot; ', $parts);
}

/* ── CSS partajat (o singură dată) ───────────────────────────────────────── */
function malinco_star_bt_css(): void {
    static $done = false; if ($done) return; $done = true;
    echo '<style id="malinco-star-bt-css">'
    /* logo comun */
    . '.btstar__logo{display:inline-flex;align-items:center;gap:8px}'
    . '.btstar__shield{height:38px;width:auto;display:block}'
    . '.btstar__word{font-size:21px;font-weight:800;color:#4a2a7a;letter-spacing:.2px}'
    . '.msbt-amt{font-weight:800;color:#2a1a45;white-space:nowrap}'
    . '.msbt-amt .woocommerce-Price-amount{color:inherit}'
    . '.btstar-reg{text-decoration:underline;font-weight:600;color:inherit;white-space:nowrap}'
    /* ── Pagina produs (badge) ── */
    . '.btstar{display:flex;align-items:center;gap:14px;margin:16px 0 6px;padding:14px 16px;'
      . 'border:1px solid #e7ddf3;border-radius:12px;background:linear-gradient(135deg,#faf7ff,#fff)}'
    . '.btstar__badge{flex:0 0 auto}.btstar__logo-img{height:44px;width:auto;display:block}'
    . '.btstar .btstar__shield{height:42px}.btstar .btstar__word{font-size:23px}'
    . '.btstar__txt{flex:1 1 auto;min-width:0}'
    . '.btstar__title{font-weight:800;color:#2a1a45;font-size:15px;line-height:1.3}.btstar__title b{color:#4a2a7a}'
    . '.btstar__or{display:block;font-weight:500;color:#6a5a85;font-size:12.5px;margin-top:2px}.btstar__or b{color:#4a2a7a;font-weight:700}'
    . '.btstar__rates{color:#4a3a63;font-size:13px;margin-top:3px}.btstar__rates .msbt-n{color:#7a6a95;font-weight:600}'
    . '.btstar__note{color:#9a8fb0;font-size:11.5px;margin-top:3px}'
    . '@media(max-width:480px){.btstar{gap:10px;padding:12px}.btstar .btstar__word{font-size:20px}.btstar__title{font-size:14px}}'
    /* ── Homepage band ── */
    . '.btstar-home{position:relative;left:50%;right:50%;width:100vw;margin-left:-50vw;margin-right:-50vw;'
      . 'background:radial-gradient(120% 140% at 100% 0,#6a37a3 0%,#4a2477 42%,#33184f 100%);color:#fff;overflow:hidden}'
    . '.btstar-home::before{content:"";position:absolute;inset:0;background:radial-gradient(60% 90% at 88% 50%,rgba(255,210,0,.16),transparent 60%);pointer-events:none}'
    . '.btstar-home__inner{max-width:1440px;margin:0 auto;padding:44px 40px;display:grid;grid-template-columns:1fr 360px;align-items:center;gap:40px;position:relative;z-index:1}'
    . '.btstar-home__eyebrow{display:inline-flex;align-items:center;gap:9px;background:rgba(255,255,255,.12);border:1px solid rgba(255,210,0,.45);color:#ffd200;font-size:12px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;padding:7px 14px;border-radius:999px}'
    . '.btstar-home__eyebrow img{display:block;filter:drop-shadow(0 1px 2px rgba(0,0,0,.3))}'
    . '.btstar-home__title{font-size:clamp(24px,3vw,38px);line-height:1.12;font-weight:800;margin:16px 0 10px;color:#fff}'
    . '.btstar-home__title em{font-style:normal;color:#ffd200}'
    . '.btstar-home__sub{font-size:15px;line-height:1.55;color:#e7dcf5;max-width:600px;margin:0 0 18px}.btstar-home__sub strong{color:#ffd200;font-weight:800}'
    . '.btstar-home__perks{list-style:none;display:flex;flex-wrap:wrap;gap:10px;margin:0 0 22px;padding:0}'
    . '.btstar-home__perks li{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.16);border-radius:10px;padding:9px 14px;font-size:13px;font-weight:600;color:#f1e9fb}.btstar-home__perks li span{color:#ffd200;font-weight:800}'
    . '.btstar-home__cta{display:inline-flex;align-items:center;gap:9px;background:#ffd200;color:#33184f;font-weight:800;font-size:15px;padding:13px 24px;border-radius:10px;box-shadow:0 8px 22px rgba(255,210,0,.28);transition:transform .15s,box-shadow .15s}'
    . '.btstar-home__cta:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(255,210,0,.4)}'
    . '.btstar-home__note{font-size:11.5px;color:#b9a8d6;margin:14px 0 0;max-width:560px}'
    . '.btstar-home__media{display:flex;justify-content:center;align-items:center}'
    . '.btstar-home__media img{width:100%;max-width:340px;height:auto;filter:drop-shadow(0 24px 48px rgba(0,0,0,.45));transform:rotate(-3deg)}'
    . '@media(max-width:900px){.btstar-home__inner{grid-template-columns:1fr;padding:32px 20px;gap:24px;text-align:center}.btstar-home__sub,.btstar-home__note{margin-left:auto;margin-right:auto}.btstar-home__perks{justify-content:center}.btstar-home__media{order:-1}.btstar-home__media img{max-width:230px;transform:none}}'
    /* ── Coș ── */
    . '.msbt-cart{display:flex;align-items:center;gap:16px;margin:18px 0;padding:16px 18px;border-radius:12px;'
      . 'background:radial-gradient(120% 140% at 100% 0,#5a2d8f,#3a1c66);color:#fff;flex-wrap:wrap}'
    . '.msbt-cart .btstar__logo{background:#fff;padding:6px 10px;border-radius:8px}'
    . '.msbt-cart__txt{flex:1 1 240px;min-width:0}'
    . '.msbt-cart__title{font-weight:800;font-size:15px;line-height:1.3}.msbt-cart__title b{color:#ffd200}'
    . '.msbt-cart__rates{font-size:13px;color:#e7dcf5;margin-top:4px}.msbt-cart__rates .msbt-amt{color:#ffd200}.msbt-cart__rates .msbt-n{color:#c9b8e6}'
    . '.msbt-cart__note{font-size:11px;color:#c1b0dd;margin-top:5px}'
    /* ── Checkout (banner deasupra „Comanda ta") ── */
    . '.msbt-cart--checkout{margin:0 0 20px}'
    . '</style>';
}

/* ── 1. Pagina produs — badge (nume păstrat pt. apelul din temă) ─────────── */
function malinco_bt_star_rate_badge(): void {
    if (!malinco_star_bt_enabled() || !function_exists('wc_get_price_to_display')) return;
    $product = $GLOBALS['product'] ?? null;
    if (!$product instanceof WC_Product) $product = wc_get_product(get_the_ID());
    if (!$product instanceof WC_Product) return;
    $price = (float) wc_get_price_to_display($product);
    if ($price <= 0) return;
    $min   = malinco_star_bt_min();
    $rates = malinco_star_bt_rates(); rsort($rates);

    malinco_star_bt_css();
    // Se afișează când preț × cantitate ≥ prag. Sub prag e ascuns; JS recalculează live la schimbarea cantității.
    $hidden = $price < $min ? ' style="display:none"' : '';
    echo '<div class="btstar" data-btstar-badge data-unit="' . esc_attr((string) $price) . '" data-min="' . esc_attr((string) $min) . '" data-rates="' . esc_attr(implode(',', $rates)) . '" data-sym="' . esc_attr(html_entity_decode(get_woocommerce_currency_symbol())) . '"' . $hidden . '>'
        . '<div class="btstar__badge">' . malinco_star_bt_logo() . '</div>'
        . '<div class="btstar__txt">'
        . '<div class="btstar__title">Plătește în <b>3 sau 6 rate</b> fără dobândă<span class="btstar__or">sau folosește punctele <b>STAR</b> pentru plata cumpărăturilor</span></div>'
        . '<div class="btstar__rates">' . malinco_star_bt_rate_line($price) . '</div>'
        . '<div class="btstar__note">*Fără dobândă, valabil cu <b>Star Card</b> de la Banca Transilvania — disponibil exclusiv online.' . malinco_star_bt_reg_link() . '</div>'
        . '</div></div>';
}

/* JS enqueue-uit (fișier extern — NU se strip-uiește de optimizator ca scriptul inline) pe pagina produs */
add_action('wp_enqueue_scripts', function () {
    if (function_exists('is_product') && is_product() && malinco_star_bt_enabled()) {
        wp_enqueue_script('malinco-star-bt-qty', MALINCO_STAR_BT_URL . 'assets/qty.js', [], MALINCO_STAR_BT_VER, true);
    }
});

/* ── 2. Homepage — shortcode [malinco_bt_star_home] ──────────────────────── */
add_shortcode('malinco_bt_star_home', 'malinco_star_bt_home_band');
function malinco_star_bt_home_band(): string {
    if (!malinco_star_bt_enabled()) return '';
    $shop = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/produse/');
    ob_start();
    malinco_star_bt_css(); ?>
<section class="btstar-home" aria-label="Plată în rate fără dobândă cu cardul Star BT">
  <div class="btstar-home__inner">
    <div class="btstar-home__text">
      <span class="btstar-home__eyebrow"><img src="<?php echo esc_url(malinco_star_bt_shield()); ?>" alt="Banca Transilvania" width="20" height="22"> Rate fără dobândă · Star BT</span>
      <h2 class="btstar-home__title">Cumpără acum, plătește în <em>3 sau 6 rate</em> — fără dobândă</h2>
      <p class="btstar-home__sub">Cu cardul <strong>Star</strong> de la Banca Transilvania plătești în 3 sau 6 rate fără dobândă — sau folosești <strong>punctele STAR</strong> pentru plata cumpărăturilor.</p>
      <ul class="btstar-home__perks">
        <li><span>0%</span> dobândă</li>
        <li><span>3 / 6</span> rate</li>
        <li><span>1 punct = 1 leu</span> puncte Star</li>
      </ul>
      <a class="btstar-home__cta" href="<?php echo esc_url($shop); ?>">Vezi produsele<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
      <p class="btstar-home__note">*Disponibil <strong>exclusiv online</strong>, cu Star Card de la Banca Transilvania. Numărul de rate se alege pe pagina de plată securizată BT.<?php echo malinco_star_bt_reg_link(); ?></p>
    </div>
    <div class="btstar-home__media"><img src="<?php echo esc_url(malinco_star_bt_card()); ?>" alt="Card Star Forte de la Banca Transilvania" width="661" height="819" loading="lazy" decoding="async"></div>
  </div>
</section>
    <?php
    return (string) ob_get_clean();
}

/* ── 3. Coș — banner după tabelul de produse ─────────────────────────────── */
add_action('woocommerce_after_cart', 'malinco_star_bt_cart_promo');
function malinco_star_bt_cart_promo(): void {
    if (!malinco_star_bt_enabled() || !WC()->cart) return;
    $total = (float) WC()->cart->get_total('edit');
    malinco_star_bt_css();
    echo '<div class="msbt-cart">' . malinco_star_bt_logo()
        . '<div class="msbt-cart__txt">'
        . '<div class="msbt-cart__title">Plătește în <b>3, 6 rate fără dobândă</b> cu <b>STAR Card</b> sau folosește punctele <b>STAR</b> pentru plata cumpărăturilor</div>';
    if ($total >= malinco_star_bt_min()) {
        echo '<div class="msbt-cart__rates">' . malinco_star_bt_rate_line($total) . '</div>';
    }
    echo '<div class="msbt-cart__note">Achită comanda online, în rate fără dobândă, folosind <b>Star Card</b>.' . malinco_star_bt_reg_link() . '</div>'
        . '</div></div>';
}

/* ── 4. Finalizare comandă — banner deasupra „Comanda ta" (server-rendered,
 *      NU în zona reîncărcată prin AJAX → se vede garantat la încărcare) ───── */
add_action('woocommerce_checkout_before_order_review_heading', 'malinco_star_bt_checkout_promo');
function malinco_star_bt_checkout_promo(): void {
    if (!malinco_star_bt_enabled() || !WC()->cart) return;
    $total = (float) WC()->cart->get_total('edit');
    malinco_star_bt_css();
    echo '<div class="msbt-cart msbt-cart--checkout">' . malinco_star_bt_logo()
        . '<div class="msbt-cart__txt">'
        . '<div class="msbt-cart__title"><b>Card online / Rate STAR Card.</b> Plătește în 3, 6 rate fără dobândă sau folosește punctele <b>STAR</b> pentru plata cumpărăturilor</div>';
    if ($total >= malinco_star_bt_min()) {
        echo '<div class="msbt-cart__rates">' . malinco_star_bt_rate_line($total) . '</div>';
    }
    echo '<div class="msbt-cart__note">*Disponibil exclusiv online. Ratele se aleg pe pagina de plată securizată BT.' . malinco_star_bt_reg_link() . '</div>'
        . '</div></div>';
}

/* ── Setări WooCommerce (tab „Star BT") ──────────────────────────────────── */
add_filter('woocommerce_settings_tabs_array', function ($tabs) {
    $tabs['malinco_star_bt'] = 'Star BT rate';
    return $tabs;
}, 60);
function malinco_star_bt_settings_fields(): array {
    return [
        ['title' => 'Promovare „Plată în rate Star BT"', 'type' => 'title',
         'desc' => 'Afișează promovarea plății în 3/6 rate fără dobândă pe homepage, pagina produs, coș și finalizare comandă. Debifează pentru a o ascunde de peste tot.',
         'id' => 'malinco_star_bt_sec'],
        ['title' => 'Activează promovarea', 'id' => 'malinco_star_bt_enabled', 'type' => 'checkbox',
         'desc' => 'Promovare Star BT activă pe tot site-ul', 'default' => 'yes'],
        ['title' => 'Prag minim (lei)', 'id' => 'malinco_star_bt_min', 'type' => 'number',
         'desc' => 'Sub această sumă nu se afișează calculul ratelor', 'default' => '100', 'css' => 'width:100px'],
        ['type' => 'sectionend', 'id' => 'malinco_star_bt_sec'],
    ];
}
add_action('woocommerce_settings_tabs_malinco_star_bt', function () {
    woocommerce_admin_fields(malinco_star_bt_settings_fields());
});
add_action('woocommerce_update_options_malinco_star_bt', function () {
    woocommerce_update_options(malinco_star_bt_settings_fields());
});
