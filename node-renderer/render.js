#!/usr/bin/env node
/**
 * Malinco Social Media Image Renderer
 * Fonturi: Montserrat (titluri) + Open Sans (corp text)
 * Background removal: elimină fundalul alb/aproape-alb de pe poza produsului
 */

const { createCanvas, loadImage, registerFont } = require('canvas');
const { StaticCanvas: FabricStaticCanvas, FabricImage } = require('fabric/node');
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

    // ── 1. Fundal cu gradient spre alb + textură ─────────────────────────────
    // Gradient liniar: alb sus-stânga → crem ușor jos-dreapta
    const bg = ctx.createLinearGradient(0, 0, W * 0.7, H - BOTTOM_H - RAINBOW_H);
    bg.addColorStop(0,    '#FFFFFF');
    bg.addColorStop(0.45, '#F8F5F2');
    bg.addColorStop(1,    '#EDE8E2');
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, W, H - BOTTOM_H - RAINBOW_H);

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

// ── Render din canvas_json (editorul vizual Fabric.js) ───────────────────────
/**
 * Randează o imagine folosind canvas_json salvat de editorul vizual.
 * Înlocuiește slot-urile dinamice (title, subtitle, label, product_image,
 * brand_logo, malinco_logo) cu conținutul real al postării.
 */
async function renderWithFabric(config, outPath) {
    const canvasData = typeof config.canvas_json === 'string'
        ? JSON.parse(config.canvas_json)
        : JSON.parse(JSON.stringify(config.canvas_json));

    // ── Pas 0: fix compatibilitate Fabric.js v5 → v7 ─────────────────────────
    // Câmpul data.type din editorul vizual ('text', 'image', etc.) e metadata internă;
    // Fabric.js v7 îl confundă cu tipul unui obiect Fabric și încearcă să-l instanțieze.
    for (const obj of canvasData.objects || []) {
        if (obj.data && obj.data.type !== undefined) {
            delete obj.data.type; // eliminăm câmpul conflictual
        }
    }

    // ── Pas 1: modificăm JSON înainte de încărcare (text + product_image) ──────
    for (const obj of canvasData.objects || []) {
        const sr = obj.data?.slot_role;
        if (!sr || sr === 'none') continue;

        if (obj.type === 'textbox' || obj.type === 'text') {
            if (sr === 'title'    && config.title)    obj.text = config.title;
            if (sr === 'subtitle' && config.subtitle) obj.text = config.subtitle;
            if (sr === 'label'    && config.label)    obj.text = config.label;
        } else if (obj.type === 'image' && sr === 'product_image' && config.product_image) {
            // Înlocuim src-ul placeholder cu URL-ul real (HTTP) sau data-URL (cale locală)
            const pi = config.product_image;
            if (pi.startsWith('http://') || pi.startsWith('https://') || pi.startsWith('data:')) {
                obj.src         = pi;
                obj.crossOrigin = 'anonymous';
            } else if (fs.existsSync(pi)) {
                // Cale locală → convertim la data-URL (JSDOM nu suportă căi locale)
                const ext  = pi.split('.').pop().toLowerCase();
                const mime = ext === 'png' ? 'image/png' : 'image/jpeg';
                obj.src = 'data:' + mime + ';base64,' + fs.readFileSync(pi).toString('base64');
            }
        }
    }

    // ── Pas 2: încărcăm canvas-ul Fabric ──────────────────────────────────────
    const canvas = new FabricStaticCanvas(null, { width: 1080, height: 1080 });
    await canvas.loadFromJSON(canvasData);

    // ── Pas 3: înlocuim rect-urile placeholder cu imagini reale ───────────────
    // (brand_logo și malinco_logo sunt rect-uri de tip bounding-box în editor)
    // Notă: JSDOM (folosit de fabric/node) nu acceptă căi locale de fișier ca src;
    // trebuie să convertim imaginile locale în data-URL-uri înainte de FabricImage.fromURL.
    const rects = canvas.getObjects().filter(o => {
        const sr = o.data && o.data.slot_role;
        return o.type === 'rect' && sr && sr !== 'none';
    });

    function toDataUrl(filePath) {
        const ext = filePath.split('.').pop().toLowerCase();
        const mime = ext === 'png' ? 'image/png' : 'image/jpeg';
        return 'data:' + mime + ';base64,' + fs.readFileSync(filePath).toString('base64');
    }

    for (const rect of rects) {
        const sr  = rect.data.slot_role;
        let imgSrc = null;

        if (sr === 'brand_logo'   && config.brand_logo   && fs.existsSync(config.brand_logo))   imgSrc = toDataUrl(config.brand_logo);
        if (sr === 'malinco_logo' && config.malinco_logo && fs.existsSync(config.malinco_logo)) imgSrc = toDataUrl(config.malinco_logo);

        if (!imgSrc) {
            rect.set('opacity', 0); // ascundem placeholder-ul gol
            continue;
        }

        const dW   = rect.getScaledWidth();
        const dH   = rect.getScaledHeight();
        const left = rect.left  || 0;
        const top  = rect.top   || 0;
        const zIdx = canvas._objects.indexOf(rect);

        try {
            const img   = await FabricImage.fromURL(imgSrc);
            const scale = Math.min(dW / img.width, dH / img.height);
            img.set({
                left:    left + (dW - img.width  * scale) / 2,
                top:     top  + (dH - img.height * scale) / 2,
                scaleX:  scale,
                scaleY:  scale,
                originX: 'left',
                originY: 'top',
            });
            canvas.remove(rect);
            // Re-inserăm la aceeași poziție în stivă (z-index păstrat)
            canvas._objects.splice(zIdx >= 0 ? zIdx : canvas._objects.length, 0, img);
            img.canvas = canvas;
        } catch (e) {
            process.stderr.write('WARN renderWithFabric slot ' + sr + ': ' + e.message + '\n');
            rect.set('opacity', 0);
        }
    }

    canvas.renderAll();

    // ── Export JPEG ───────────────────────────────────────────────────────────
    const dataUrl = canvas.toDataURL({ format: 'jpeg', quality: 0.93 });
    const base64  = dataUrl.replace(/^data:image\/jpeg;base64,/, '');
    fs.mkdirSync(path.dirname(outPath), { recursive: true });
    fs.writeFileSync(outPath, Buffer.from(base64, 'base64'));

    canvas.dispose();
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
