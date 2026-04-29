#!/usr/bin/env node
/**
 * Malinco Social Media Image Renderer
 * Fonturi: Montserrat (titluri) + Open Sans (corp text)
 * Background removal: elimină fundalul alb/aproape-alb de pe poza produsului
 */

const { createCanvas, loadImage, registerFont } = require('canvas');
const fs   = require('fs');
const path = require('path');

// ── Înregistrare fonturi ──────────────────────────────────────────────────────
// TTF full-range (latin + latin-ext) — include diacritice românești (ă â î ș ț)
const FD = path.join(__dirname, 'fonts');
registerFont(`${FD}/Montserrat-Bold.ttf`,     { family: 'Montserrat', weight: 'bold' });
registerFont(`${FD}/Montserrat-SemiBold.ttf`, { family: 'Montserrat', weight: '600' });
registerFont(`${FD}/Montserrat-Regular.ttf`,  { family: 'Montserrat', weight: 'normal' });
registerFont(`${FD}/OpenSans-Bold.ttf`,       { family: 'Open Sans',  weight: 'bold' });
registerFont(`${FD}/OpenSans-Regular.ttf`,    { family: 'Open Sans',  weight: 'normal' });

// ── Constante (overridable din config) ────────────────────────────────────────
// Valorile default — pot fi suprascrise prin config.primary_color etc.
const DEFAULT_RED     = '#a52a3f';
const DEFAULT_DARK_BG = '#a52a3f';

// ── Helpers culoare ───────────────────────────────────────────────────────────
function shadeColor(hex, amount) {
    const n = parseInt(hex.replace('#', ''), 16);
    const r = Math.min(255, ((n >> 16) & 0xff) + amount);
    const g = Math.min(255, ((n >>  8) & 0xff) + amount);
    const b = Math.min(255, ((n)       & 0xff) + amount);
    return '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('');
}

// ── Background removal ────────────────────────────────────────────────────────
/**
 * Elimină fundalul alb/aproape-alb dintr-o imagine canvas.
 * Flood-fill din cele 4 colțuri + threshold global pentru pixeli izolați.
 */
function removeWhiteBackground(imgCanvas, threshold = 235) {
    const w   = imgCanvas.width;
    const h   = imgCanvas.height;
    const ctx = imgCanvas.getContext('2d');
    const img = ctx.getImageData(0, 0, w, h);
    const d   = img.data;

    function isNearWhite(i) {
        return d[i] >= threshold && d[i+1] >= threshold && d[i+2] >= threshold;
    }

    function idx(x, y) { return (y * w + x) * 4; }

    // Flood fill din colțuri
    const visited = new Uint8Array(w * h);
    const queue   = [];

    const corners = [[0,0],[w-1,0],[0,h-1],[w-1,h-1]];
    for (const [cx, cy] of corners) {
        const i = idx(cx, cy);
        if (isNearWhite(i) && !visited[cy*w+cx]) {
            queue.push([cx, cy]);
            visited[cy*w+cx] = 1;
        }
    }

    while (queue.length > 0) {
        const [x, y] = queue.pop();
        const i = idx(x, y);
        d[i+3] = 0; // transparent

        const neighbors = [[x+1,y],[x-1,y],[x,y+1],[x,y-1]];
        for (const [nx, ny] of neighbors) {
            if (nx >= 0 && nx < w && ny >= 0 && ny < h && !visited[ny*w+nx]) {
                const ni = idx(nx, ny);
                if (isNearWhite(ni)) {
                    visited[ny*w+nx] = 1;
                    queue.push([nx, ny]);
                }
            }
        }
    }

    ctx.putImageData(img, 0, 0);
    return imgCanvas;
}

async function loadProductImage(src, removeBackground = true) {
    const raw = await loadImage(src);

    if (!removeBackground) return raw;

    // Desenăm pe un canvas temporar pentru pixel manipulation
    const tmp = createCanvas(raw.width, raw.height);
    tmp.getContext('2d').drawImage(raw, 0, 0);
    return removeWhiteBackground(tmp);
}

// ── Helpers text ──────────────────────────────────────────────────────────────
function wrapText(ctx, text, x, y, maxWidth, lineHeight, maxLines = 5) {
    if (!text) return 0;
    const words = text.split(' ');
    let line  = '';
    const lines = [];

    for (const word of words) {
        const test = line ? line + ' ' + word : word;
        if (ctx.measureText(test).width > maxWidth && line !== '') {
            lines.push(line);
            line = word;
            if (lines.length >= maxLines) break;
        } else {
            line = test;
        }
    }
    if (line && lines.length < maxLines) lines.push(line);

    for (let i = 0; i < lines.length; i++) {
        ctx.fillText(lines[i], x, y + i * lineHeight);
    }
    return lines.length;
}

function countWrapLines(ctx, text, maxWidth, maxLines = 5) {
    if (!text) return 0;
    const words = text.split(' ');
    let line = '', count = 0;
    for (const word of words) {
        const test = line ? line + ' ' + word : word;
        if (ctx.measureText(test).width > maxWidth && line !== '') {
            count++;
            line = word;
            if (count >= maxLines) return maxLines;
        } else {
            line = test;
        }
    }
    if (line) count++;
    return Math.min(count, maxLines);
}

// Letter-spacing manual
function fillTextSpaced(ctx, text, x, y, spacing = 3) {
    let cx = x;
    for (const ch of text) {
        ctx.fillText(ch, cx, y);
        cx += ctx.measureText(ch).width + spacing;
    }
    return cx - x;
}

// Rounded rectangle path
function roundRectPath(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.lineTo(x + w - r, y);
    ctx.arcTo(x + w, y,     x + w, y + r,     r);
    ctx.lineTo(x + w, y + h - r);
    ctx.arcTo(x + w, y + h, x + w - r, y + h, r);
    ctx.lineTo(x + r, y + h);
    ctx.arcTo(x,     y + h, x,     y + h - r, r);
    ctx.lineTo(x,     y + r);
    ctx.arcTo(x,     y,     x + r, y,         r);
    ctx.closePath();
}

// ── Crop canvas la bounding box conținut non-transparent ──────────────────────
function cropToContent(srcCanvas) {
    const w   = srcCanvas.width;
    const h   = srcCanvas.height;
    const ctx = srcCanvas.getContext('2d');
    const d   = ctx.getImageData(0, 0, w, h).data;

    let minX = w, minY = h, maxX = 0, maxY = 0;
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            if (d[(y * w + x) * 4 + 3] > 10) { // alpha > 10
                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;
            }
        }
    }
    if (maxX <= minX || maxY <= minY) return srcCanvas; // nimic de cropped

    const pad = 4;
    minX = Math.max(0, minX - pad);
    minY = Math.max(0, minY - pad);
    maxX = Math.min(w - 1, maxX + pad);
    maxY = Math.min(h - 1, maxY + pad);

    const cw = maxX - minX + 1;
    const ch = maxY - minY + 1;
    const dst = createCanvas(cw, ch);
    dst.getContext('2d').drawImage(srcCanvas, minX, minY, cw, ch, 0, 0, cw, ch);
    return dst;
}

// ── Camion ────────────────────────────────────────────────────────────────────
function drawTruck(ctx, x, y, h, bgColor) {
    const s = h / 46;
    ctx.fillStyle = '#FFFFFF';
    ctx.fillRect(x,         y + 12*s, 33*s, 24*s); // cargo
    ctx.fillRect(x + 32*s,  y + 19*s, 19*s, 17*s); // cab
    ctx.fillStyle = bgColor || DEFAULT_DARK_BG;
    ctx.fillRect(x + 34*s,  y + 21*s, 14*s, 9*s);  // windshield
    ctx.fillStyle = '#FFFFFF';
    ctx.beginPath(); ctx.arc(x + 9*s,  y + 38*s, 7*s, 0, Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(x + 40*s, y + 38*s, 7*s, 0, Math.PI*2); ctx.fill();
}

// ── Dungă curcubeu ────────────────────────────────────────────────────────────
function drawRainbowBar(ctx, y, W, h) {
    const g = ctx.createLinearGradient(0, 0, W, 0);
    g.addColorStop(0,    '#E60000');
    g.addColorStop(0.2,  '#FF9900');
    g.addColorStop(0.4,  '#33BB33');
    g.addColorStop(0.6,  '#2299EE');
    g.addColorStop(0.8,  '#8833CC');
    g.addColorStop(1,    '#FF3399');
    ctx.fillStyle = g;
    ctx.fillRect(0, y, W, h);
}

// ── Template Malinco ──────────────────────────────────────────────────────────
async function renderMalinco(ctx, W, H, config) {
    // Stil dinamic — preia din config sau fallback la default
    const RED     = config.primary_color || DEFAULT_RED;
    const DARK_BG = config.primary_color || DEFAULT_DARK_BG;
    const RAINBOW_H = 10;
    const BOTTOM_H  = Math.round(H * 0.150);
    const LOGO_H    = Math.round(H * 0.192);
    const CONTENT_Y = LOGO_H;
    const CONTENT_H = H - LOGO_H - BOTTOM_H - RAINBOW_H;
    const PAD       = Math.round(W * 0.058); // ~62px — spațiere generoasă

    // Pozițiile salvate din editorul vizual (la 1080px, scalate dacă W≠1080)
    const EP    = config.element_positions || {};
    const scale = W / 1080;
    function ep(id, fallback) {
        if (!EP[id]) return fallback;
        return {
            x: Math.round(EP[id].x * scale),
            y: Math.round(EP[id].y * scale),
            w: Math.round(EP[id].w * scale),
            h: Math.round(EP[id].h * scale),
        };
    }

    // ── 1. Fundal — imagine AI sau gradient default ───────────────────────────
    if (config.background_image && fs.existsSync(config.background_image)) {
        try {
            const bgImg = await loadImage(config.background_image);
            ctx.drawImage(bgImg, 0, 0, W, H - BOTTOM_H - RAINBOW_H);
        } catch(e) {}
    } else {
        const bg = ctx.createLinearGradient(0, 0, W * 0.7, H - BOTTOM_H - RAINBOW_H);
        bg.addColorStop(0,    '#FFFFFF');
        bg.addColorStop(0.45, '#F8F5F2');
        bg.addColorStop(1,    '#EDE8E2');
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, W, H - BOTTOM_H - RAINBOW_H);
    }

    // Textură: linii diagonale fine semi-transparente
    ctx.save();
    ctx.globalAlpha = 0.045;
    ctx.strokeStyle = '#7A6A60';
    ctx.lineWidth   = 1;
    const step = 28;
    for (let d = -H; d < W + H; d += step) {
        ctx.beginPath();
        ctx.moveTo(d, 0);
        ctx.lineTo(d + H, H - BOTTOM_H - RAINBOW_H);
        ctx.stroke();
    }
    ctx.restore();

    // Vigneta radială subtilă pe margini
    const vig = ctx.createRadialGradient(W * 0.5, (H - BOTTOM_H) * 0.45, W * 0.25, W * 0.5, (H - BOTTOM_H) * 0.45, W * 0.82);
    vig.addColorStop(0,   'rgba(255,255,255,0)');
    vig.addColorStop(1,   'rgba(180,160,150,0.13)');
    ctx.fillStyle = vig;
    ctx.fillRect(0, 0, W, H - BOTTOM_H - RAINBOW_H);

    // ── 2. Logo Malinco centrat sus ──────────────────────────────────────────
    if (config.malinco_logo && fs.existsSync(config.malinco_logo)) {
        try {
            const logo   = await loadImage(config.malinco_logo);
            const defLogoW = Math.round(W * (config.logo_scale || 0.36));
            const logoPos  = ep('malinco_logo', {
                x: Math.round((W - defLogoW) / 2),
                y: Math.round((LOGO_H - Math.round(logo.height * defLogoW / logo.width)) / 2),
                w: defLogoW,
                h: Math.round(logo.height * defLogoW / logo.width),
            });
            // Păstrăm aspect ratio
            const logoW = logoPos.w;
            const logoH = Math.round(logo.height * logoW / logo.width);
            ctx.drawImage(logo, logoPos.x, logoPos.y, logoW, logoH);
        } catch(e) {}
    }

    // ── 3. Visual hero — dreapta ─────────────────────────────────────────────
    const isBrandLayout  = config.layout === 'brand';
    const hasProductImg  = !!config.product_image;
    const hasBrandHeroImg = isBrandLayout && !!config.brand_logo && fs.existsSync(config.brand_logo);

    // Zona vizuală dreapta — din element_positions sau auto-calculat
    const _defVisual = { x: W - Math.round(W * 0.44) - Math.round(PAD * 0.3), y: CONTENT_Y, w: Math.round(W * 0.44), h: CONTENT_H };
    // slot_role='product_image' takes priority over legacy 'visual_area' key
    const visualPos  = ep(EP['product_image'] ? 'product_image' : 'visual_area', _defVisual);
    const IMG_AREA_W = visualPos.w;
    const IMG_AREA_X = visualPos.x;

    // Zona text stânga — din element_positions sau auto-calculat
    const hasRightVisual = hasProductImg || hasBrandHeroImg;
    const _defTextW  = hasRightVisual ? IMG_AREA_X - PAD - Math.round(PAD * 0.5) : W - PAD * 2 - Math.round(W * 0.03);
    const TEXT_MAX_W = EP['title_block'] ? EP['title_block'].w * scale : _defTextW;

    if (hasProductImg) {
        try {
            const img   = await loadProductImage(config.product_image, config.remove_bg !== false);
            const scale = Math.min(IMG_AREA_W / img.width, CONTENT_H / img.height);
            const dw    = img.width  * scale;
            const dh    = img.height * scale;
            ctx.drawImage(img,
                IMG_AREA_X + (IMG_AREA_W - dw) / 2,
                CONTENT_Y  + (CONTENT_H  - dh) / 2,
                dw, dh
            );
        } catch(e) {
            process.stderr.write('WARN produs: ' + e.message + '\n');
        }
    } else if (hasBrandHeroImg) {
        // Brand layout: logo-ul brandului mare pe dreapta ca element hero
        try {
            const rawBrand = await loadImage(config.brand_logo);
            const tmpB = createCanvas(rawBrand.width, rawBrand.height);
            tmpB.getContext('2d').drawImage(rawBrand, 0, 0);
            const brand  = cropToContent(removeWhiteBackground(tmpB, 230));

            // Scalăm să ocupe 70% din zona hero (mai mic decât un produs, mai mare decât bara de jos)
            const heroMaxW = Math.round(IMG_AREA_W * 0.82);
            const heroMaxH = Math.round(CONTENT_H  * 0.52);
            const bScale   = Math.min(heroMaxW / brand.width, heroMaxH / brand.height);
            const bW = brand.width  * bScale;
            const bH = brand.height * bScale;

            // Centrat vertical în zona hero, cu accent subtle circular
            const bX = IMG_AREA_X + (IMG_AREA_W - bW) / 2;
            const bY = CONTENT_Y  + (CONTENT_H  - bH) / 2;

            // Halo subtil alb în spate
            ctx.save();
            ctx.globalAlpha = 0.55;
            ctx.fillStyle   = '#FFFFFF';
            const haloPad = Math.round(W * 0.04);
            roundRectPath(ctx, bX - haloPad, bY - haloPad, bW + haloPad * 2, bH + haloPad * 2, Math.round(haloPad * 1.2));
            ctx.fill();
            ctx.restore();

            ctx.drawImage(brand, bX, bY, bW, bH);
        } catch(e) {
            process.stderr.write('WARN brand hero: ' + e.message + '\n');
        }
    }

    // ── 4. Bloc text — centrat vertical, design premium ──────────────────────
    const ACCENT_BAR_W  = Math.round(W * 0.008);
    const ACCENT_BAR_X  = PAD;
    const TXI           = ACCENT_BAR_X + ACCENT_BAR_W + Math.round(W * 0.022);
    const TEXT_W        = TEXT_MAX_W - ACCENT_BAR_W - Math.round(W * 0.022);

    const eyebrowSz = Math.round(W * 0.019);
    const titleSz   = Math.round(W * (config.title_size_pct   || 0.075));
    const subSz     = Math.round(W * (config.subtitle_size_pct || 0.029));
    const ctaSz     = Math.round(W * 0.022);

    const TITLE_LH  = Math.round(titleSz * 1.14);
    const SUB_LH    = Math.round(subSz   * 1.48);

    const titleText = config.title ? config.title.toUpperCase() : '';
    const eyebrow   = config.label || '';

    // Pre-calculăm linii titlu și subtitle pentru centrare verticală
    ctx.font = `bold ${titleSz}px "Montserrat"`;
    const nTitle = config.title    ? countWrapLines(ctx, titleText, TEXT_W, 4) : 0;
    ctx.font = `italic ${subSz}px "Open Sans"`;
    const nSub   = config.subtitle ? countWrapLines(ctx, config.subtitle, TEXT_W, 2) : 0;

    const eyebrowH  = eyebrow && (config.title || config.subtitle) ? eyebrowSz * 2.4 + Math.round(H * 0.026) + 3 : 0;
    const titleH    = nTitle * TITLE_LH;
    const gapH      = config.subtitle ? Math.round(H * 0.022) : 0;
    const subH      = nSub * SUB_LH;
    const brandLogoH = 0; // logo-ul e pe același rând cu CTA, nu în stack vertical
    const ctaH      = Math.round(ctaSz * 2.5) + Math.round(H * 0.030);

    const totalBlockH = eyebrowH + titleH + gapH + subH + brandLogoH + ctaH;
    let ty = CONTENT_Y + Math.round((CONTENT_H - totalBlockH) / 2) + Math.round(H * 0.02);

    // ── Text rendering — designer positions (slot_role) or centering algorithm ─
    //
    // hasDesignerLayout = true  → designer has assigned slot_roles in the visual
    //   editor; render each text element at its exact saved position.
    //   No vertical-centering math needed; no accent bar (designer adds their own).
    // hasDesignerLayout = false → legacy / unassigned templates; use existing
    //   vertical-centering algorithm (unchanged, backwards-compatible).
    //
    const hasDesignerLayout = !!(EP['title'] || EP['subtitle'] || EP['badge']);

    if (hasDesignerLayout) {
        if (eyebrow && EP['badge']) {
            const bp = ep('badge', null);
            ctx.fillStyle = RED;
            ctx.font      = `bold ${eyebrowSz}px "Montserrat"`;
            fillTextSpaced(ctx, eyebrow, bp.x, bp.y + eyebrowSz, 4);
        }
        if (config.title && EP['title']) {
            const tp = ep('title', null);
            ctx.fillStyle = '#111111';
            ctx.font      = `bold ${titleSz}px "Montserrat"`;
            wrapText(ctx, titleText, tp.x, tp.y + titleSz, tp.w || TEXT_W, TITLE_LH, 4);
        }
        if (config.subtitle && EP['subtitle']) {
            const sp = ep('subtitle', null);
            ctx.fillStyle = '#3a3a3a';
            ctx.font      = `italic ${subSz}px "Open Sans"`;
            wrapText(ctx, config.subtitle, sp.x, sp.y + subSz, sp.w || TEXT_W, SUB_LH, 2);
        }
    } else {
        // ── Eyebrow label cu letter-spacing (doar dacă există) ───────────────────
        if (eyebrow) {
            ctx.fillStyle = RED;
            ctx.font      = `bold ${eyebrowSz}px "Montserrat"`;
            fillTextSpaced(ctx, eyebrow, TXI, ty, 4);
            ty += Math.round(eyebrowSz * 2.4);

            // Linie scurtă sub eyebrow
            ctx.fillStyle = RED;
            ctx.fillRect(TXI, ty, Math.round(W * 0.072), 3);
            ty += 3 + Math.round(H * 0.026);
        }

        const accentBarTopY = ty; // marchează de unde începe bara verticală

        // ── Titlu Montserrat Bold, întunecat ──────────────────────────────────────
        if (config.title) {
            ctx.fillStyle = '#111111';
            ctx.font      = `bold ${titleSz}px "Montserrat"`;
            wrapText(ctx, titleText, TXI, ty, TEXT_W, TITLE_LH, 4);
            ty += nTitle * TITLE_LH;
        }

        // ── Bară verticală accent (de la eyebrow rule până sub titlu) ────────────
        ctx.fillStyle = RED;
        ctx.fillRect(ACCENT_BAR_X, accentBarTopY - 3, ACCENT_BAR_W, ty - accentBarTopY + 3 + Math.round(H * 0.010));

        ty += Math.round(H * 0.022);

        // ── Subtitle italic ───────────────────────────────────────────────────────
        if (config.subtitle) {
            ctx.fillStyle = '#3a3a3a';
            ctx.font      = `italic ${subSz}px "Open Sans"`;
            wrapText(ctx, config.subtitle, TXI, ty, TEXT_W, SUB_LH, 2);
            ty += nSub * SUB_LH + Math.round(H * 0.022);
        }
    }

    // ── Rând jos: logo brand (stânga) + CTA pill (dreapta) — același rând ────
    const ROW_H    = Math.round(H * 0.075);  // înălțimea rândului logo+CTA
    const ROW_Y    = CONTENT_Y + CONTENT_H - ROW_H - Math.round(H * 0.022);

    const ctaText  = config.cta_text || 'malinco.ro  →';
    const ctaSzRow = Math.round(W * 0.024);
    ctx.font       = `bold ${ctaSzRow}px "Montserrat"`;
    const ctaTextW = ctx.measureText(ctaText).width;
    const ctaPadX  = Math.round(W * 0.030);
    const ctaRectW = ctaTextW + ctaPadX * 2;
    const ctaRectH = ROW_H;
    const ctaR     = Math.round(ctaRectH * 0.28);
    const _defCtaX = W - PAD - ctaRectW;
    const ctaPos   = ep('cta_button', { x: _defCtaX, y: ROW_Y, w: ctaRectW, h: ROW_H });
    const ctaRectX = ctaPos.x;
    const ctaRectY = ctaPos.y;

    // Logo brand în rândul CTA — doar la layout produs (la brand layout e deja hero pe dreapta)
    if (!isBrandLayout && config.brand_logo && fs.existsSync(config.brand_logo)) {
        try {
            const rawBrand = await loadImage(config.brand_logo);
            const tmpB = createCanvas(rawBrand.width, rawBrand.height);
            tmpB.getContext('2d').drawImage(rawBrand, 0, 0);
            const brand  = cropToContent(removeWhiteBackground(tmpB, 230));
            const bMaxH  = ROW_H;
            const bMaxW  = ctaRectX - TXI - Math.round(W * 0.04);
            const bScale = Math.min(bMaxW / brand.width, bMaxH / brand.height);
            const bW     = brand.width  * bScale;
            const bH     = brand.height * bScale;
            ctx.drawImage(brand, TXI, ROW_Y + (ROW_H - bH) / 2, bW, bH);
        } catch(e) {}
    }

    // CTA pill
    ctx.fillStyle = RED;
    roundRectPath(ctx, ctaRectX, ctaRectY, ctaRectW, ctaRectH, ctaR);
    ctx.fill();

    ctx.fillStyle = '#FFFFFF';
    ctx.font      = `bold ${ctaSzRow}px "Montserrat"`;
    ctx.fillText(ctaText, ctaRectX + ctaPadX, ctaRectY + Math.round(ctaRectH * 0.64));

    // ── 5. Bară jos — redesign ───────────────────────────────────────────────
    const _defBarY  = H - BOTTOM_H - RAINBOW_H;
    const barPos    = ep('bottom_bar', { x: 0, y: _defBarY, w: W, h: BOTTOM_H });
    const barY      = barPos.y;

    // Fundal cu gradient ușor stânga → dreapta
    const barGrad = ctx.createLinearGradient(0, barY, W, barY);
    barGrad.addColorStop(0,   DARK_BG);
    barGrad.addColorStop(0.6, DARK_BG);
    barGrad.addColorStop(1,   shadeColor(DARK_BG, 18));
    ctx.fillStyle = barGrad;
    ctx.fillRect(0, barY, W, BOTTOM_H);

    // Dungi diagonale — dinamism grafic
    ctx.save();
    const slant = Math.round(BOTTOM_H * 0.75);
    const stripeStep = Math.round(W * 0.055);
    ctx.globalAlpha = 0.10;
    ctx.fillStyle = '#FFFFFF';
    for (let sx = -slant; sx < W * 0.42; sx += stripeStep * 2.8) {
        ctx.beginPath();
        ctx.moveTo(sx,            barY);
        ctx.lineTo(sx + stripeStep, barY);
        ctx.lineTo(sx + stripeStep + slant, barY + BOTTOM_H);
        ctx.lineTo(sx + slant,    barY + BOTTOM_H);
        ctx.closePath();
        ctx.fill();
    }
    ctx.restore();

    // Camion — opțional
    const showTruck = config.show_truck !== false;
    const truckH = Math.round(BOTTOM_H * 0.56);
    const truckX = Math.round(PAD * 1.1);
    const truckY = barY + Math.round((BOTTOM_H - truckH) / 2);
    if (showTruck) {
        drawTruck(ctx, truckX, truckY, truckH, DARK_BG);
    }

    // Separator vertical
    const sepX = showTruck ? truckX + Math.round(W * 0.105) : Math.round(PAD * 0.8);
    ctx.save();
    ctx.globalAlpha = 0.30;
    ctx.fillStyle = '#FFFFFF';
    ctx.fillRect(sepX, barY + Math.round(BOTTOM_H * 0.14), 2, Math.round(BOTTOM_H * 0.72));
    ctx.restore();

    // Bloc text — centrat în spațiul rămas
    const txtAreaX = sepX + Math.round(PAD * 0.75);
    const txtAreaW = W - txtAreaX - Math.round(PAD * 0.5);
    const txtCX    = txtAreaX + txtAreaW / 2;

    const barMainSz = Math.round(W * 0.027);
    const barSubSz  = Math.round(W * 0.019);

    ctx.textAlign = 'center';

    const bottomText    = config.bottom_text    || 'ASIGURĂM TRANSPORT ȘI DESCĂRCARE CU MACARA';
    const bottomSubtext = config.bottom_subtext || 'Sântandrei, Nr. 311, vis-a-vis de Primărie  |  www.malinco.ro  |  0359 444 999';

    ctx.fillStyle = '#FFFFFF';
    ctx.font      = `bold ${barMainSz}px "Montserrat"`;
    ctx.fillText(bottomText.toUpperCase(), txtCX, barY + Math.round(BOTTOM_H * 0.43));

    ctx.fillStyle = '#EDE8E2';
    ctx.font      = `bold ${barSubSz}px "Open Sans"`;
    ctx.fillText(bottomSubtext, txtCX, barY + Math.round(BOTTOM_H * 0.73));

    ctx.textAlign = 'left'; // reset

    // ── 6. Dungă curcubeu ────────────────────────────────────────────────────
    if (config.show_rainbow_bar !== false) {
        drawRainbowBar(ctx, H - RAINBOW_H, W, RAINBOW_H);
    }
}

// ── Render din canvas_json (Claude AI Fabric.js JSON) ────────────────────────
/**
 * Randează obiectele din canvas_json direct cu canvas npm (fără fabric/node).
 * Suportă: rect, circle, textbox/text, line, triangle.
 * Slot-urile dinamice (product_image, brand_logo, malinco_logo, title, subtitle)
 * sunt înlocuite cu conținutul real al postării.
 */
async function renderWithFabric(config, outPath) {
    const canvasData = typeof config.canvas_json === 'string'
        ? JSON.parse(config.canvas_json)
        : JSON.parse(JSON.stringify(config.canvas_json));

    const W = canvasData.width  || 1080;
    const H = canvasData.height || 1080;

    const canvas = createCanvas(W, H);
    const ctx    = canvas.getContext('2d');

    // Fundal AI (background_image) — desenat primul, sub toate obiectele
    if (config.background_image && fs.existsSync(config.background_image)) {
        try {
            const bgImg = await loadImage(config.background_image);
            // Cover: acoperim tot canvas-ul, centrat
            const scale = Math.max(W / bgImg.width, H / bgImg.height);
            const dw = bgImg.width  * scale;
            const dh = bgImg.height * scale;
            ctx.drawImage(bgImg, (W - dw) / 2, (H - dh) / 2, dw, dh);
        } catch(e) {
            process.stderr.write('WARN background_image: ' + e.message + '\n');
        }
    }

    for (const obj of (canvasData.objects || [])) {
        await drawFabricObject(ctx, obj, config);
    }

    fs.mkdirSync(path.dirname(outPath), { recursive: true });
    fs.writeFileSync(outPath, canvas.toBuffer('image/jpeg', { quality: 0.93 }));
}

/**
 * Desenează un obiect Fabric.js pe ctx nativ canvas.
 * Suportă transformări (angle, scaleX/Y), opacity, slot_role-uri.
 */
async function drawFabricObject(ctx, obj, config) {
    const type    = obj.type || 'rect';
    const left    = obj.left   || 0;
    const top     = obj.top    || 0;
    const width   = (obj.width  || 0) * (obj.scaleX || 1);
    const height  = (obj.height || 0) * (obj.scaleY || 1);
    const opacity = obj.opacity !== undefined ? obj.opacity : 1;
    const angle   = obj.angle  || 0;
    const sr      = obj.data?.slot_role;

    if (opacity <= 0) return;

    ctx.save();
    ctx.globalAlpha = ctx.globalAlpha * opacity;

    // Transformare: Fabric.js rotește în jurul centrului obiectului
    if (angle !== 0) {
        const cx = left + width / 2;
        const cy = top  + height / 2;
        ctx.translate(cx, cy);
        ctx.rotate(angle * Math.PI / 180);
        ctx.translate(-cx, -cy);
    }

    // Rezolvăm fill-ul (string hex/rgb sau obiect gradient)
    function resolveFill(f, x, y, w, h) {
        if (!f || f === 'transparent') return null;
        if (typeof f === 'string') return f;
        // Gradient object din Fabric.js
        if (f.type === 'linear' && Array.isArray(f.colorStops)) {
            const x1 = x + (f.coords?.x1 || 0) * w;
            const y1 = y + (f.coords?.y1 || 0) * h;
            const x2 = x + (f.coords?.x2 || 1) * w;
            const y2 = y + (f.coords?.y2 || 0) * h;
            const g = ctx.createLinearGradient(x1, y1, x2, y2);
            for (const stop of f.colorStops) {
                g.addColorStop(stop.offset, stop.color);
            }
            return g;
        }
        if (f.type === 'radial' && Array.isArray(f.colorStops)) {
            const r1 = (f.coords?.r1 || 0) * Math.max(w, h);
            const r2 = (f.coords?.r2 || 1) * Math.max(w, h);
            const cx2 = x + (f.coords?.x2 || 0.5) * w;
            const cy2 = y + (f.coords?.y2 || 0.5) * h;
            const g = ctx.createRadialGradient(cx2, cy2, r1, cx2, cy2, r2);
            for (const stop of f.colorStops) {
                g.addColorStop(stop.offset, stop.color);
            }
            return g;
        }
        return null;
    }

    if (type === 'rect') {
        const rx = obj.rx || 0;
        const ry = obj.ry || rx;

        if (sr === 'product_image' && config.product_image) {
            // Slot produs — înlocuim cu imaginea reală
            try {
                const img = await loadProductImage(config.product_image, config.remove_bg !== false);
                const sc  = Math.min(width / img.width, height / img.height);
                const dw  = img.width  * sc;
                const dh  = img.height * sc;
                ctx.drawImage(img, left + (width - dw) / 2, top + (height - dh) / 2, dw, dh);
            } catch(e) {
                process.stderr.write('WARN product_image slot: ' + e.message + '\n');
            }
        } else if (sr === 'brand_logo' && config.brand_logo && fs.existsSync(config.brand_logo)) {
            try {
                const raw = await loadImage(config.brand_logo);
                const tmp = createCanvas(raw.width, raw.height);
                tmp.getContext('2d').drawImage(raw, 0, 0);
                const brand = cropToContent(removeWhiteBackground(tmp, 230));
                const sc    = Math.min(width / brand.width, height / brand.height);
                const dw    = brand.width  * sc;
                const dh    = brand.height * sc;
                ctx.drawImage(brand, left + (width - dw) / 2, top + (height - dh) / 2, dw, dh);
            } catch(e) {
                process.stderr.write('WARN brand_logo slot: ' + e.message + '\n');
            }
        } else if (sr === 'malinco_logo' && config.malinco_logo && fs.existsSync(config.malinco_logo)) {
            try {
                const logo = await loadImage(config.malinco_logo);
                const sc   = Math.min(width / logo.width, height / logo.height);
                const dw   = logo.width  * sc;
                const dh   = logo.height * sc;
                ctx.drawImage(logo, left + (width - dw) / 2, top + (height - dh) / 2, dw, dh);
            } catch(e) {
                process.stderr.write('WARN malinco_logo slot: ' + e.message + '\n');
            }
        } else {
            // Rect normal
            const fillStyle = resolveFill(obj.fill, left, top, width, height);
            if (fillStyle) {
                ctx.fillStyle = fillStyle;
                if (rx > 0 || ry > 0) {
                    roundRectPath(ctx, left, top, width, height, Math.max(rx, ry));
                    ctx.fill();
                } else {
                    ctx.fillRect(left, top, width, height);
                }
            }
            // Stroke opțional
            if (obj.stroke && obj.strokeWidth) {
                ctx.strokeStyle = obj.stroke;
                ctx.lineWidth   = obj.strokeWidth;
                if (rx > 0 || ry > 0) {
                    roundRectPath(ctx, left, top, width, height, Math.max(rx, ry));
                    ctx.stroke();
                } else {
                    ctx.strokeRect(left, top, width, height);
                }
            }
        }

    } else if (type === 'circle') {
        const radius = (obj.radius || 0) * (obj.scaleX || 1);
        const fillStyle = resolveFill(obj.fill, left, top, radius * 2, radius * 2);
        if (fillStyle) {
            ctx.fillStyle = fillStyle;
            ctx.beginPath();
            ctx.arc(left + radius, top + radius, radius, 0, Math.PI * 2);
            ctx.fill();
        }
        if (obj.stroke && obj.strokeWidth) {
            ctx.strokeStyle = obj.stroke;
            ctx.lineWidth   = obj.strokeWidth;
            ctx.beginPath();
            ctx.arc(left + radius, top + radius, radius, 0, Math.PI * 2);
            ctx.stroke();
        }

    } else if (type === 'triangle') {
        const fillStyle = resolveFill(obj.fill, left, top, width, height);
        if (fillStyle) {
            ctx.fillStyle = fillStyle;
            ctx.beginPath();
            ctx.moveTo(left + width / 2, top);
            ctx.lineTo(left + width, top + height);
            ctx.lineTo(left, top + height);
            ctx.closePath();
            ctx.fill();
        }
        if (obj.stroke && obj.strokeWidth) {
            ctx.strokeStyle = obj.stroke;
            ctx.lineWidth   = obj.strokeWidth;
            ctx.beginPath();
            ctx.moveTo(left + width / 2, top);
            ctx.lineTo(left + width, top + height);
            ctx.lineTo(left, top + height);
            ctx.closePath();
            ctx.stroke();
        }

    } else if (type === 'line') {
        if (obj.stroke) {
            ctx.strokeStyle = obj.stroke;
            ctx.lineWidth   = obj.strokeWidth || 1;
            // În Fabric.js, line: x1/y1/x2/y2 sunt relative față de centrul obiectului
            const cx = left + width / 2;
            const cy = top  + height / 2;
            ctx.beginPath();
            ctx.moveTo(cx + (obj.x1 || -width / 2), cy + (obj.y1 || 0));
            ctx.lineTo(cx + (obj.x2 || width / 2),  cy + (obj.y2 || 0));
            ctx.stroke();
        }

    } else if (type === 'textbox' || type === 'text' || type === 'i-text') {
        // Rezolvăm textul (slot_role suprascrie textul din JSON)
        let text = obj.text || '';
        if (sr === 'title'    && config.title)    text = config.title;
        if (sr === 'subtitle' && config.subtitle) text = config.subtitle;
        if (sr === 'label'    && config.label)    text = config.label;
        if (!text) { ctx.restore(); return; }

        const fontSize   = obj.fontSize   || 32;
        const fontFamily = obj.fontFamily || 'Montserrat';
        const fontWeight = obj.fontWeight || 'normal';
        const textFill   = typeof obj.fill === 'string' ? obj.fill : '#000000';
        const textAlign  = obj.textAlign  || 'left';
        const lineHeight = obj.lineHeight ? fontSize * obj.lineHeight : fontSize * 1.25;

        ctx.fillStyle = textFill;
        ctx.font      = `${fontWeight} ${fontSize}px "${fontFamily}"`;
        ctx.textAlign = textAlign;

        const textX = textAlign === 'center' ? left + width / 2
                    : textAlign === 'right'  ? left + width
                    : left;

        wrapText(ctx, text, textX, top + fontSize, width || 500, lineHeight, 10);
        ctx.textAlign = 'left'; // reset
    }

    ctx.restore();
}

// ── Main ─────────────────────────────────────────────────────────────────────
async function render(config) {
    const outPath = config.output;

    // Dacă template-ul are canvas_json (creat în editorul vizual) — îl folosim direct
    if (config.canvas_json) {
        await renderWithFabric(config, outPath);
        process.stdout.write(outPath + '\n');
        return;
    }

    // Fallback: randare hardcodată (template Malinco default fără canvas_json)
    const W = config.width  || 1080;
    const H = config.height || 1080;

    const canvas = createCanvas(W, H);
    const ctx    = canvas.getContext('2d');

    await renderMalinco(ctx, W, H, config);

    for (const el of (config.elements || [])) {
        if (el.type === 'rect') {
            ctx.fillStyle = el.color || '#000';
            ctx.fillRect(el.x || 0, el.y || 0, el.w || 100, el.h || 100);
        } else if (el.type === 'text') {
            ctx.fillStyle = el.color || '#000';
            ctx.font      = `${el.font || 'normal'} ${el.size || 32}px "Open Sans"`;
            ctx.fillText(el.text || '', el.x || 0, el.y || 0);
        }
    }

    fs.mkdirSync(path.dirname(outPath), { recursive: true });
    fs.writeFileSync(outPath, canvas.toBuffer('image/jpeg', { quality: 0.93 }));
    process.stdout.write(outPath + '\n');
}

let rawJson = '';
if (process.argv[2]) {
    rawJson = process.argv[2];
} else {
    rawJson = fs.readFileSync('/dev/stdin', 'utf8');
}

try {
    const config = JSON.parse(rawJson);
    render(config).catch(err => {
        process.stderr.write('ERROR: ' + err.message + '\n');
        process.exit(1);
    });
} catch (e) {
    process.stderr.write('ERROR JSON parse: ' + e.message + '\n');
    process.exit(1);
}
