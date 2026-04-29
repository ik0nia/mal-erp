const { createCanvas, loadImage } = require('canvas');
const https = require('https');
const http = require('http');
const fs = require('fs');

function fetchImage(url) {
    return new Promise((resolve, reject) => {
        const client = url.startsWith('https') ? https : http;
        client.get(url, res => {
            const chunks = [];
            res.on('data', c => chunks.push(c));
            res.on('end', () => resolve(Buffer.concat(chunks)));
            res.on('error', reject);
        }).on('error', reject);
    });
}

async function drawRoundRect(ctx, x, y, w, h, r, fill) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.lineTo(x + w - r, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h - r);
    ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    ctx.lineTo(x, y + r);
    ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath();
    ctx.fillStyle = fill;
    ctx.fill();
}

async function render() {
    const S = 1080;
    const canvas = createCanvas(S, S);
    const ctx = canvas.getContext('2d');

    // FUNDAL gradient cald
    const bgGrad = ctx.createLinearGradient(0, 0, S, S);
    bgGrad.addColorStop(0, '#F8F7F5');
    bgGrad.addColorStop(1, '#EDEAE4');
    ctx.fillStyle = bgGrad;
    ctx.fillRect(0, 0, S, S);

    // Cerc decorativ subtil dreapta-jos
    ctx.beginPath();
    ctx.arc(S + 80, S - 60, 520, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(196, 30, 58, 0.05)';
    ctx.fill();
    ctx.beginPath();
    ctx.arc(S + 80, S - 60, 360, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(196, 30, 58, 0.04)';
    ctx.fill();

    // IMAGINEA PRODUSULUI (dreapta, cu umbra)
    const prodBuf = await fetchImage('https://erp.malinco.ro/storage/toya-images/yt-82122_a.jpeg');
    const prodImg = await loadImage(prodBuf);
    const prodSize = 600;
    const prodX = 430;
    const prodY = 80;
    const ratio = Math.min(prodSize / prodImg.width, prodSize / prodImg.height);
    const dw = prodImg.width * ratio;
    const dh = prodImg.height * ratio;

    ctx.save();
    ctx.shadowColor = 'rgba(0,0,0,0.15)';
    ctx.shadowBlur = 50;
    ctx.shadowOffsetX = 8;
    ctx.shadowOffsetY = 16;
    ctx.drawImage(prodImg, prodX + (prodSize - dw) / 2, prodY + (prodSize - dh) / 2, dw, dh);
    ctx.restore();

    // LINIE ACCENT VERTICALĂ
    const grad = ctx.createLinearGradient(0, 60, 0, S - 100);
    grad.addColorStop(0, 'rgba(196,30,58,0)');
    grad.addColorStop(0.15, '#C41E3A');
    grad.addColorStop(0.85, '#C41E3A');
    grad.addColorStop(1, 'rgba(196,30,58,0)');
    ctx.fillStyle = grad;
    ctx.fillRect(400, 60, 3, S - 150);

    // TEXT stânga
    const tx = 55;

    // Label roșu sus
    await drawRoundRect(ctx, tx, 65, 175, 32, 5, '#C41E3A');
    ctx.fillStyle = '#FFFFFF';
    ctx.font = 'bold 13px Arial';
    ctx.fillText('SCULĂ PROFESIONALĂ', tx + 10, 87);

    // Titlu
    ctx.fillStyle = '#1A1A1A';
    ctx.font = 'bold 64px Arial';
    ctx.fillText('ROTOPER-', tx, 205);
    ctx.fillText('CUTOR', tx, 278);
    ctx.fillStyle = '#C41E3A';
    ctx.fillText('SDS+', tx, 351);

    // Specificații tehnice
    ctx.fillStyle = '#999999';
    ctx.font = '21px Arial';
    ctx.fillText('850W  ·  3.3J  ·  0–1100 RPM', tx, 400);

    // Linie separator
    ctx.strokeStyle = '#DDDDDD';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(tx, 425); ctx.lineTo(360, 425); ctx.stroke();

    // Avantaje
    const adv = ['Putere maximă la impact', 'Vibrații reduse semnificativ', '3 moduri de lucru'];
    adv.forEach((a, i) => {
        const y = 472 + i * 58;
        ctx.beginPath();
        ctx.arc(tx + 8, y - 7, 5, 0, Math.PI * 2);
        ctx.fillStyle = '#C41E3A';
        ctx.fill();
        ctx.fillStyle = '#333333';
        ctx.font = '25px Arial';
        ctx.fillText(a, tx + 24, y);
    });

    // CTA buton
    await drawRoundRect(ctx, tx, 675, 225, 50, 8, '#1A1A1A');
    ctx.fillStyle = '#FFFFFF';
    ctx.font = 'bold 19px Arial';
    ctx.fillText('Descoperă acum  →', tx + 18, 707);

    // Brand
    ctx.fillStyle = '#BBBBBB';
    ctx.font = '17px Arial';
    ctx.fillText('YATO', tx, 790);

    // BANNER MALINCO
    const bannerH = 78;
    ctx.fillStyle = '#C41E3A';
    ctx.fillRect(0, S - bannerH, S, bannerH);

    const logo = await loadImage('/var/www/erp/public/malinco-logo-white.png');
    const lw = 260, lh = Math.round(logo.height * (260 / logo.width));
    ctx.drawImage(logo, (S - lw) / 2, S - bannerH + (bannerH - lh) / 2, lw, lh);

    fs.writeFileSync('/var/www/erp/storage/app/public/social/test_v2.png', canvas.toBuffer('image/png'));
    console.log('OK');
}

render().catch(e => { console.error('ERR:', e.message); process.exit(1); });
