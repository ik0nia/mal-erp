#!/usr/bin/env node
/**
 * Malinco Social Media Renderer — HTML/CSS via Puppeteer
 * Layout: Split Editorial (text left dark panel / product right clean panel)
 *         cu variante de culoare și stil per post.
 */

const puppeteer = require('puppeteer-core');
const fs        = require('fs');
const path      = require('path');

const CHROME   = '/usr/bin/google-chrome-stable';
const FONT_DIR = path.join(__dirname, 'fonts');

function fontBase64(filename) {
    const p = path.join(FONT_DIR, filename);
    if (!fs.existsSync(p)) return null;
    return fs.readFileSync(p).toString('base64');
}

function imageToDataUrl(imgPath) {
    if (!imgPath || !fs.existsSync(imgPath)) return null;
    const ext  = path.extname(imgPath).toLowerCase().replace('.', '');
    const mime = ext === 'png' ? 'image/png' : ext === 'webp' ? 'image/webp' : 'image/jpeg';
    return `data:${mime};base64,${fs.readFileSync(imgPath).toString('base64')}`;
}

function titleFontSize(title) {
    const len = (title || '').length;
    if (len <= 8)  return '96px';
    if (len <= 12) return '82px';
    if (len <= 18) return '70px';
    if (len <= 24) return '60px';
    if (len <= 32) return '50px';
    return '42px';
}

// ── Construiește HTML ────────────────────────────────────────────────────────
function buildHtml(config) {
    const style      = config.style_variant || 'dark_premium';
    const title      = (config.title || '').toUpperCase();
    const subtitle   = config.subtitle || '';
    const label      = (config.label || '').toUpperCase();
    const advantages = (config.advantages || []).slice(0, 3);

    const productImg  = imageToDataUrl(config.product_image);
    const malincoLogo = imageToDataUrl(config.malinco_logo);
    const bgImage     = imageToDataUrl(config.background_image);

    const tfs = titleFontSize(title);

    // ── Fonturi ──────────────────────────────────────────────────────────────
    const mb  = fontBase64('Montserrat-Bold.ttf');
    const ms  = fontBase64('Montserrat-SemiBold.ttf');
    const mr  = fontBase64('Montserrat-Regular.ttf');
    const osr = fontBase64('OpenSans-Regular.ttf');
    const osb = fontBase64('OpenSans-Bold.ttf');
    const ff  = [
        mb  ? `@font-face{font-family:'Montserrat';font-weight:700;src:url('data:font/truetype;base64,${mb}');}` : '',
        ms  ? `@font-face{font-family:'Montserrat';font-weight:600;src:url('data:font/truetype;base64,${ms}');}` : '',
        mr  ? `@font-face{font-family:'Montserrat';font-weight:400;src:url('data:font/truetype;base64,${mr}');}` : '',
        osr ? `@font-face{font-family:'Open Sans';font-weight:400;src:url('data:font/truetype;base64,${osr}');}` : '',
        osb ? `@font-face{font-family:'Open Sans';font-weight:700;src:url('data:font/truetype;base64,${osb}');}` : '',
    ].join('');

    // ── Teme per stil ────────────────────────────────────────────────────────
    const themes = {
        // Panel stânga întunecat, produs pe alb — clasic B2B premium
        dark_premium: {
            leftBg:       '#111111',
            leftBg2:      '#1a0506',          // gradient secundar spre roșu întunecat
            rightBg:      '#ffffff',
            rightBg2:     '#f5f0eb',
            accentColor:  '#C41E3A',
            titleColor:   '#ffffff',
            subtitleColor:'#C41E3A',
            bodyColor:    'rgba(255,255,255,0.62)',
            labelColor:   '#C41E3A',
            splitWidth:   490,
            ghostOpacity: 0.042,
        },
        split_layout: {
            leftBg:       '#C41E3A',
            leftBg2:      '#8B1228',
            rightBg:      '#ffffff',
            rightBg2:     '#faf5f0',
            accentColor:  '#ffffff',
            titleColor:   '#ffffff',
            subtitleColor:'rgba(255,255,255,0.88)',
            bodyColor:    'rgba(255,255,255,0.72)',
            labelColor:   'rgba(255,255,255,0.72)',
            splitWidth:   480,
            ghostOpacity: 0.07,
        },
        minimal_light: {
            leftBg:       '#222222',
            leftBg2:      '#111111',
            rightBg:      '#ffffff',
            rightBg2:     '#f5f5f5',
            accentColor:  '#C41E3A',
            titleColor:   '#ffffff',
            subtitleColor:'#C41E3A',
            bodyColor:    'rgba(255,255,255,0.62)',
            labelColor:   '#C41E3A',
            splitWidth:   505,
            ghostOpacity: 0.038,
        },
        geometric: {
            leftBg:       '#0a0a0a',
            leftBg2:      '#1c0508',
            rightBg:      '#fdfaf7',
            rightBg2:     '#f0e8de',
            accentColor:  '#C41E3A',
            titleColor:   '#ffffff',
            subtitleColor:'#C41E3A',
            bodyColor:    'rgba(255,255,255,0.58)',
            labelColor:   '#C41E3A',
            splitWidth:   478,
            ghostOpacity: 0.052,
        },
    };

    const t = themes[style] || themes['dark_premium'];

    // ── Avantaje HTML ────────────────────────────────────────────────────────
    const advHtml = advantages.map(a => `
        <div style="display:flex;align-items:center;gap:13px;margin-bottom:11px;">
            <div style="width:6px;height:6px;min-width:6px;border-radius:50%;
                background:${t.accentColor};"></div>
            <span style="font-family:'Open Sans',sans-serif;font-size:18px;
                line-height:1.3;color:${t.bodyColor};">${a}</span>
        </div>`).join('');

    // ── Logo HTML ────────────────────────────────────────────────────────────
    const logoHtml = malincoLogo
        ? `<img style="height:80px;width:auto;filter:brightness(0) invert(1);
            display:block;position:relative;z-index:1;" src="${malincoLogo}" />`
        : `<span style="font-family:'Montserrat',sans-serif;font-weight:700;
            font-size:44px;color:white;letter-spacing:0.15em;position:relative;z-index:1;">MALINCO</span>`;

    // ── Produs HTML ──────────────────────────────────────────────────────────
    const productHtml = productImg
        ? `<img style="max-width:96%;max-height:88%;object-fit:contain;
            display:block;position:relative;z-index:2;
            filter:drop-shadow(0 6px 24px rgba(0,0,0,0.10)) drop-shadow(0 1px 6px rgba(0,0,0,0.06));
            mix-blend-mode:multiply;" src="${productImg}" />`
        : '';

    // ── Calcule geometrie split ──────────────────────────────────────────────
    // Panoul stânga are clip-path: wider at top, narrower at bottom
    // Right edge: (panW, 0) → (panW - offset, 910)
    const panW   = t.splitWidth + 55;   // lățimea totală a div-ului panel
    const offset = 55;                   // cât se îngustează spre jos
    const ptR    = panW;                 // x margine dreapta sus
    const pbR    = panW - offset;        // x margine dreapta jos
    const sw     = 26;                   // lățimea barei roșii
    const sw2    = 5;                    // lățimea liniei secundare subțiri
    const gap    = 8;                    // distanța dintre bara principală și linia secundară

    // clip-path paralelogram aliniat exact cu marginea panoului
    const stripeClip  = `polygon(${ptR - sw/2}px 0, ${ptR + sw/2}px 0, ${pbR + sw/2}px 910px, ${pbR - sw/2}px 910px)`;
    const stripe2Clip = `polygon(${ptR + sw/2 + gap}px 0, ${ptR + sw/2 + gap + sw2}px 0, ${pbR + sw/2 + gap + sw2}px 910px, ${pbR + sw/2 + gap}px 910px)`;

    // ── SVG decorative left panel ────────────────────────────────────────────
    const ghostWord = (title.split(' ')[0] || 'MALINCO').substring(0, 7);
    const geoSvg = style === 'geometric' ? `
        <svg style="position:absolute;inset:0;width:100%;height:100%;pointer-events:none;" viewBox="0 0 ${panW} 910" xmlns="http://www.w3.org/2000/svg">
            <circle cx="${panW}" cy="160" r="280" fill="none" stroke="#C41E3A" stroke-width="1" opacity="0.14"/>
            <circle cx="${panW}" cy="160" r="180" fill="none" stroke="#C41E3A" stroke-width="0.8" opacity="0.09"/>
            <circle cx="-10" cy="820" r="220" fill="rgba(196,30,58,0.07)"/>
        </svg>` : '';

    return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
${ff}
*{margin:0;padding:0;box-sizing:border-box;}
body{width:1080px;height:1080px;overflow:hidden;background:#111;}
</style>
</head>
<body>
<div style="width:1080px;height:1080px;position:relative;overflow:hidden;">

    <!-- ═══ PANEL STÂNGA: wider sus, narrower jos ════════════════════════ -->
    <div style="position:absolute;top:0;left:0;width:${panW}px;height:910px;
        background:linear-gradient(155deg,${t.leftBg} 0%,${t.leftBg2} 100%);
        clip-path:polygon(0 0,${ptR}px 0,${pbR}px 910px,0 910px);
        z-index:2;overflow:hidden;">

        <!-- Textură diagonală subtilă -->
        <div style="position:absolute;inset:0;background:repeating-linear-gradient(
            -45deg,transparent,transparent 55px,
            rgba(255,255,255,0.016) 55px,rgba(255,255,255,0.016) 56px);"></div>

        <!-- Decor SVG geometric -->
        ${geoSvg}

        <!-- Ghost word mare în fundal (sus, nu suprapune CTA) -->
        <div style="position:absolute;top:30px;left:-20px;
            font-family:'Montserrat',sans-serif;font-weight:700;
            font-size:190px;color:white;opacity:${t.ghostOpacity};
            line-height:1;letter-spacing:-0.04em;
            white-space:nowrap;pointer-events:none;
            transform:rotate(-5deg);">${ghostWord}</div>

        <!-- Stripe accent stânga -->
        <div style="position:absolute;top:0;left:0;width:6px;height:910px;
            background:linear-gradient(to bottom,${t.accentColor},rgba(196,30,58,0.25));"></div>

    </div>

    <!-- ═══ PANEL DREAPTA: produs pe fundal curat ════════════════════════ -->
    <div style="position:absolute;top:0;right:0;width:${1080 - pbR + 5}px;height:910px;
        background:radial-gradient(ellipse at 45% 40%,${t.rightBg} 30%,${t.rightBg2} 100%);
        z-index:1;display:flex;align-items:center;justify-content:center;overflow:hidden;">

        <!-- Spotlight cald pe produs -->
        <div style="position:absolute;top:50%;left:50%;transform:translate(-55%,-52%);
            width:480px;height:480px;border-radius:50%;
            background:radial-gradient(circle,rgba(255,245,235,0.60) 0%,transparent 68%);
            pointer-events:none;z-index:1;"></div>

        <!-- Shadow subtil la baza produsului -->
        <div style="position:absolute;bottom:0;left:0;right:0;height:120px;
            background:linear-gradient(to top,rgba(0,0,0,0.06),transparent);
            pointer-events:none;z-index:1;"></div>

        <!-- Background AI dacă există -->
        ${bgImage ? `<div style="position:absolute;inset:0;background:url('${bgImage}') center/cover no-repeat;opacity:0.10;mix-blend-mode:multiply;"></div>` : ''}

        <!-- Produs -->
        ${productHtml}

    </div>

    <!-- ═══ BARA ROȘIE DIAGONALĂ — aliniată exact cu marginea panoului ════ -->
    <div style="position:absolute;top:0;left:0;width:1080px;height:910px;
        clip-path:${stripeClip};
        background:linear-gradient(to bottom,#C41E3A,#9a1530);
        z-index:5;"></div>
    <!-- Linie subțire secundară -->
    <div style="position:absolute;top:0;left:0;width:1080px;height:910px;
        clip-path:${stripe2Clip};
        background:rgba(196,30,58,0.28);
        z-index:5;"></div>

    <!-- ═══ CONȚINUT TEXT (deasupra panoului stânga) ═════════════════════ -->
    <div style="position:absolute;top:0;left:0;width:${t.splitWidth - 20}px;height:910px;
        padding:0 48px 0 38px;
        display:flex;flex-direction:column;justify-content:center;
        z-index:6;">

        <!-- Label -->
        ${label ? `
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;">
            <div style="width:22px;height:2px;background:${t.labelColor};flex-shrink:0;"></div>
            <span style="font-family:'Montserrat',sans-serif;font-weight:700;font-size:11px;
                letter-spacing:0.30em;color:${t.labelColor};text-transform:uppercase;">${label}</span>
        </div>` : ''}

        <!-- Titlu principal -->
        <div style="font-family:'Montserrat',sans-serif;font-weight:700;
            font-size:${tfs};line-height:0.88;letter-spacing:-0.025em;
            color:${t.titleColor};word-break:break-word;margin-bottom:16px;">
            ${title}
        </div>

        <!-- Subtitlu -->
        ${subtitle ? `
        <div style="font-family:'Montserrat',sans-serif;font-weight:600;
            font-size:21px;color:${t.subtitleColor};letter-spacing:0.01em;
            margin-bottom:22px;line-height:1.3;">${subtitle}</div>` : ''}

        <!-- Separator -->
        <div style="width:44px;height:3px;background:${t.accentColor};
            margin-bottom:22px;border-radius:2px;"></div>

        <!-- Avantaje -->
        ${advHtml ? `<div style="margin-bottom:30px;">${advHtml}</div>` : ''}

        <!-- CTA buton pill -->
        <div style="display:inline-flex;align-items:center;gap:10px;
            border:1.5px solid ${t.accentColor};border-radius:3px;
            padding:11px 22px;width:fit-content;">
            <span style="font-family:'Montserrat',sans-serif;font-weight:700;
                font-size:11px;letter-spacing:0.22em;color:${t.accentColor};
                text-transform:uppercase;">Descoperă acum</span>
            <svg width="14" height="10" viewBox="0 0 14 10" fill="none">
                <path d="M1 5h12M8 1l5 4-5 4" stroke="${t.accentColor}" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>

    </div>

    <!-- ═══ BARA MALINCO JOS ══════════════════════════════════════════════ -->
    <div style="position:absolute;bottom:0;left:0;width:1080px;height:170px;
        background:linear-gradient(135deg,#C41E3A 0%,#9a1530 100%);
        display:flex;align-items:center;justify-content:center;z-index:10;overflow:hidden;">
        <!-- Shimmer diagonal -->
        <div style="position:absolute;inset:0;background:repeating-linear-gradient(
            -55deg,transparent,transparent 28px,
            rgba(255,255,255,0.04) 28px,rgba(255,255,255,0.04) 56px);"></div>
        ${logoHtml}
    </div>

</div>
</body>
</html>`;
}

// ── Render principal ─────────────────────────────────────────────────────────
async function render(config) {
    const html    = buildHtml(config);
    const outPath = config.output;

    fs.mkdirSync(path.dirname(outPath), { recursive: true });

    const browser = await puppeteer.launch({
        executablePath: CHROME,
        args: ['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage','--disable-gpu','--font-render-hinting=none'],
        headless: true,
    });

    try {
        const page = await browser.newPage();
        await page.setViewport({ width: 1080, height: 1080, deviceScaleFactor: 1 });
        await page.setContent(html, { waitUntil: 'networkidle0' });
        await page.evaluate(() => document.fonts.ready);

        const ext = path.extname(outPath).toLowerCase();
        if (ext === '.png') {
            await page.screenshot({ path: outPath, type: 'png' });
        } else {
            await page.screenshot({ path: outPath, type: 'jpeg', quality: 93 });
        }
    } finally {
        await browser.close();
    }

    process.stdout.write(outPath + '\n');
}

// ── Entry point ──────────────────────────────────────────────────────────────
let rawJson = process.argv[2] || fs.readFileSync('/dev/stdin', 'utf8');
try {
    const config = JSON.parse(rawJson);
    render(config).catch(err => { process.stderr.write('ERROR: ' + err.message + '\n'); process.exit(1); });
} catch (e) {
    process.stderr.write('ERROR JSON: ' + e.message + '\n');
    process.exit(1);
}
