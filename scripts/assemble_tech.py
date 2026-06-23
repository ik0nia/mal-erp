#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Documentație tehnică — 3 documente CURATE per sistem (zero amestec) + master. HTML + .doc.
Capitole mono-sistem reutilizate din wf1/wf2 + capitole transversale SCOPED din wxo75a1iw."""
import json, re

BASE = '/tmp/claude-1001/-var-www-erp/a3fee843-14e7-43cd-91a6-89a8b19f844f/tasks'
wf1 = json.load(open(f'{BASE}/wpsym6vi3.output'))['result']['tech']
wf2 = json.load(open(f'{BASE}/wyeyot9qy.output'))['result']['tech_extra']
extra = json.load(open(f'{BASE}/wxo75a1iw.output'))['result']['extra']
EX = {e['key']: e for e in extra if e}
SRC = {'wf1': wf1, 'wf2': wf2}

def strip_first_h3(body):
    return re.sub(r'^\s*<h3>.*?</h3>', '', body, count=1, flags=re.S)

def resolve(ch):
    """('reuse', src, idx, title, grp) sau ('new', key, title, grp) -> (title, grp, body)"""
    if ch[0] == 'reuse':
        _, src, idx, title, grp = ch
        return title, grp, strip_first_h3(SRC[src][idx]['body_html'])
    else:
        _, key, title, grp = ch
        return title, grp, EX[key]['body_html']

# ---- structura fiecărui document (ordine capitole) ----
MALINCO = [
    ('reuse', 'wf1', 1, 'Platformă, securitate de bază și control acces', 'Arhitectură'),
    ('reuse', 'wf1', 2, 'Module funcționale (Achiziții, Ofertare, Stoc, Comenzi)', 'Module'),
    ('reuse', 'wf1', 3, 'Integrări externe (WooCommerce, Sameday, WinMentor, AI)', 'Integrări'),
    ('reuse', 'wf1', 4, 'Aplicații PWA, notificări push și joburi programate', 'Mobil & automatizări'),
    ('new', 'M_data',   'Modelul de date', 'Date & fluxuri'),
    ('new', 'M_sec',    'Securitate', 'Securitate'),
    ('new', 'M_infra',  'Infrastructură și deployment', 'Infrastructură'),
    ('new', 'M_proc',   'Procese de business pas-cu-pas', 'Procese'),
    ('new', 'M_ops',    'Proceduri operaționale și backup', 'Operare'),
    ('new', 'M_param',  'Parametri și configurare', 'Configurare'),
    ('new', 'M_struct', 'Structura codului și convenții', 'Cod'),
    ('new', 'M_install','Instalare și punere în funcțiune', 'Instalare'),
]
MAXCL = [
    ('reuse', 'wf1', 7, 'Framework MVC propriu (Core, routing, RBAC, migrații)', 'Arhitectură'),
    ('reuse', 'wf1', 8, 'Module de producție (Catalog, HPL, Magazie, Proiecte, Ofertare)', 'Module'),
    ('new', 'X_data',   'Modelul de date', 'Date & fluxuri'),
    ('new', 'X_sec',    'Securitate și control acces (RBAC)', 'Securitate'),
    ('new', 'X_infra',  'Infrastructură și deployment (Docker)', 'Infrastructură'),
    ('new', 'X_proc',   'Procese de business pas-cu-pas', 'Procese'),
    ('new', 'X_param',  'Parametri și configurare', 'Configurare'),
    ('new', 'X_struct', 'Structura codului și instalare', 'Cod'),
]
APIDOC = [
    ('reuse', 'wf1', 5, 'Arhitectura bridge-ului Go peste COM-ul WinMentor', 'Arhitectură'),
    ('reuse', 'wf1', 6, 'Referință API și importuri structurate', 'Referință API'),
    ('new', 'A_sec', 'Securitate și autentificare', 'Securitate'),
    ('new', 'A_ops', 'Deployment (serviciu Windows) și operare', 'Operare'),
    ('reuse', 'wf2', 5, 'Parametri de integrare (MentorAPI + WooCommerce)', 'Integrare'),
]

DOCS = [
    {'slug': 'documentatie-erp-malinco', 'title': 'ERP Malinco — Documentație Tehnică',
     'sub': 'Platforma centrală de business (erp.malinco.ro), pe Laravel 13 + Filament 4: arhitectură, module, integrări, aplicații PWA, model de date, securitate, infrastructură, procese, proceduri și parametri de configurare.',
     'chips': ['Laravel 13 + Filament 4', 'MySQL + Redis/Horizon', 'PWA · WooCommerce · Sameday'],
     'chapters': MALINCO},
    {'slug': 'documentatie-erp-maxcl', 'title': 'ERP maxCL — Documentație Tehnică',
     'sub': 'Sistem de gestiune a producției de mobilier (stoc.maxcl.ro), pe framework MVC propriu: arhitectură, module de producție (catalog, plăci HPL, magazie, proiecte), model de date, securitate, deployment Docker, procese, parametri și structura codului.',
     'chips': ['Framework MVC propriu', 'PHP + MySQL · Docker', 'Catalog · HPL · Magazie · Proiecte'],
     'chapters': MAXCL},
    {'slug': 'documentatie-winmentor-api', 'title': 'MentorAPI / WinMentor API — Documentație Tehnică',
     'sub': 'Bridge REST în Go peste interfața COM proprietară a aplicației WinMentor: arhitectura punții, referința de endpoint-uri și importuri structurate, securitate, deployment (serviciu Windows) și parametri de integrare.',
     'chips': ['Go + COM (DocImpServer)', 'REST / JSON · OpenAPI', 'Serviciu Windows 32-bit'],
     'chapters': APIDOC},
]
# master = concatenarea celor 3, cu separatoare de parte
MASTER = {'slug': 'documentatie-tehnica', 'title': 'Ecosistemul ERP Malinco, MentorAPI &amp; ERP maxCL',
          'sub': 'Documentație tehnică completă a celor trei sisteme, organizată pe părți distincte, fără suprapuneri de conținut.',
          'chips': ['ERP Malinco', 'MentorAPI / WinMentor API', 'ERP maxCL'], 'chapters': None}

NAV = {'documentatie-tehnica': 'Complet', 'documentatie-erp-malinco': 'ERP Malinco',
       'documentatie-erp-maxcl': 'ERP maxCL', 'documentatie-winmentor-api': 'WinMentor API'}

CSS = '''
:root{--ink:#1e293b;--accent:#334155;--accent2:#4f46e5;--line:#e2e8f0;--soft:#f8fafc;--code:#0f172a;}
*{box-sizing:border-box;}
body{margin:0;background:#525659;color:var(--ink);font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;}
.toolbar{position:sticky;top:0;z-index:10;background:#1e293b;padding:10px 16px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;box-shadow:0 2px 8px rgba(0,0,0,.3);}
.toolbar a{display:inline-flex;align-items:center;gap:6px;background:var(--accent2);color:#fff;text-decoration:none;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;}
.toolbar a.alt{background:#475569;}
.toolbar a.nav{background:transparent;border:1px solid #64748b;font-weight:500;font-size:12px;padding:7px 12px;}
.toolbar a:hover{filter:brightness(1.12);}
.page{background:#fff;max-width:880px;margin:22px auto;padding:0;box-shadow:0 6px 26px rgba(0,0,0,.32);}
.cover{background:linear-gradient(135deg,#1e293b,#4f46e5);color:#fff;padding:64px 60px 52px;}
.cover .brand{font-size:12px;letter-spacing:3px;text-transform:uppercase;opacity:.85;font-weight:700;}
.cover h1{font-size:32px;margin:16px 0 10px;line-height:1.18;font-weight:800;}
.cover .sub{font-size:15px;opacity:.92;max-width:600px;line-height:1.5;}
.cover .systems{display:flex;gap:10px;flex-wrap:wrap;margin-top:24px;}
.cover .chip{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.3);padding:7px 14px;border-radius:20px;font-size:12.5px;font-weight:600;}
.cover .foot{margin-top:28px;font-size:12.5px;opacity:.8;}
.inner{padding:40px 60px 56px;}
.toc-wrap h2{font-size:18px;color:var(--accent);margin:0 0 14px;}
.part-sep{margin:30px 0 0;padding:10px 14px;background:var(--soft);border-left:4px solid var(--accent2);font-weight:700;color:var(--accent);font-size:15px;}
ol.toc{list-style:none;padding:0;margin:0 0 10px;}
ol.toc li{margin:0;border-bottom:1px dotted var(--line);}
ol.toc a{display:flex;align-items:baseline;gap:8px;text-decoration:none;color:var(--ink);padding:8px 2px;font-size:14px;}
ol.toc a:hover{color:var(--accent2);}
.toc-n{color:var(--accent2);font-weight:700;min-width:26px;}
.toc-grp{margin-left:auto;font-size:11px;color:#94a3b8;white-space:nowrap;}
.chapter{margin-top:38px;}
.chapnum{font-size:11px;letter-spacing:2px;text-transform:uppercase;color:var(--accent2);font-weight:700;}
.chapter h2{font-size:22px;color:var(--accent);margin:5px 0 16px;line-height:1.25;border-bottom:3px solid var(--accent2);padding-bottom:10px;}
.chapter h3{font-size:16px;color:#1e293b;margin:22px 0 8px;}
.chapter h4{font-size:14px;color:var(--accent2);margin:15px 0 6px;}
.chapter p{margin:8px 0;line-height:1.6;font-size:13.6px;}
.chapter ul,.chapter ol{margin:8px 0;padding-left:24px;}
.chapter li{margin:5px 0;line-height:1.52;font-size:13.6px;}
.chapter table{width:100%;border-collapse:collapse;margin:14px 0;font-size:12.3px;}
.chapter th,.chapter td{border:1px solid var(--line);padding:8px 10px;text-align:left;vertical-align:top;}
.chapter th{background:var(--soft);color:var(--accent);font-weight:700;}
.chapter tr:nth-child(even) td{background:#fcfdfe;}
.chapter code{background:#eef2ff;color:#3730a3;padding:1px 6px;border-radius:4px;font-family:'SF Mono',Menlo,Consolas,monospace;font-size:12.3px;}
.chapter pre{background:var(--code);color:#e2e8f0;padding:14px 16px;border-radius:8px;overflow-x:auto;font-size:12.3px;line-height:1.5;}
.chapter pre code{background:none;color:inherit;padding:0;}
@media print{
  @page{size:A4;margin:16mm 14mm;}
  body{background:#fff;}
  .toolbar{display:none;}
  .page{box-shadow:none;margin:0;max-width:none;}
  .cover{padding:46px 38px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .inner{padding:24px 0 0;}
  .chapter{page-break-before:always;break-before:page;}
  .toc-wrap{page-break-after:always;break-after:page;}
  .chapter h2,.chapter h3,.chapter h4{page-break-after:avoid;break-after:avoid;}
  .chapter table,.chapter pre{page-break-inside:avoid;break-inside:avoid;}
}
@media screen and (max-width:760px){.cover{padding:38px 24px;}.cover h1{font-size:25px;}.inner{padding:26px 20px;}}
'''
DOC_CSS = '''
@page{size:A4;margin:1.8cm;}
body{font-family:'Segoe UI','Calibri',sans-serif;font-size:10.5pt;line-height:1.4;color:#1e293b;}
h1{font-size:19pt;color:#312e81;text-align:center;margin:0 0 6pt;}
.brand{text-align:center;font-size:9pt;letter-spacing:2pt;text-transform:uppercase;color:#4f46e5;font-weight:bold;}
.sub{text-align:center;font-size:10pt;color:#475569;margin-bottom:10pt;border-bottom:1.5pt solid #4f46e5;padding-bottom:10pt;}
.part-sep{background:#eef2ff;border-left:3pt solid #4f46e5;padding:6pt 10pt;font-weight:bold;color:#312e81;margin:14pt 0 6pt;}
h2{font-size:15pt;color:#312e81;border-bottom:1.5pt solid #4f46e5;padding-bottom:4pt;margin:18pt 0 8pt;}
.chapnum{font-size:8pt;letter-spacing:1.5pt;text-transform:uppercase;color:#4f46e5;font-weight:bold;}
h3{font-size:12pt;color:#1e293b;margin:12pt 0 5pt;}
h4{font-size:11pt;color:#4f46e5;margin:9pt 0 4pt;}
p{margin:5pt 0;} ul,ol{margin:5pt 0;} li{margin:2pt 0;}
table{border-collapse:collapse;width:100%;font-size:9pt;margin:7pt 0;}
td,th{border:0.75pt solid #cbd5e1;padding:4pt 6pt;vertical-align:top;text-align:left;}
th{background:#eef2ff;color:#312e81;}
code{font-family:'Consolas','Courier New',monospace;font-size:9pt;background:#eef2ff;color:#3730a3;}
pre{background:#0f172a;color:#e2e8f0;padding:8pt;font-family:'Consolas',monospace;font-size:8.5pt;}
ol.toc{list-style:none;padding:0;} ol.toc a{text-decoration:none;color:#1e293b;} .toc-grp{color:#94a3b8;font-size:8pt;}
'''

def render_chapters(chapters, part_label=None):
    """returnează (toc_html, content_html, n). part_label = etichetă de parte (master)."""
    toc, content = [], []
    if part_label:
        toc.append(f'<li style="border:none;margin-top:10px"><strong>{part_label}</strong></li>')
        content.append(f'<div class="part-sep">{part_label}</div>')
    for ch in chapters:
        title, grp, body = resolve(ch)
        render_chapters.n += 1
        n = render_chapters.n
        cid = f'cap{n}'
        toc.append(f'<li><a href="#{cid}"><span class="toc-n">{n}.</span> {title} <span class="toc-grp">{grp}</span></a></li>')
        content.append(f'<section class="chapter" id="{cid}"><div class="chapnum">Capitolul {n} · {grp}</div><h2>{title}</h2>\n{body}\n</section>')
    return '\n'.join(toc), '\n\n'.join(content)

def build(doc, toc_inner, content, n):
    chips = ''.join(f'<span class="chip">{c}</span>' for c in doc['chips'])
    navlinks = ''.join(f'<a class="nav" href="{slug}.html">{"● " if slug==doc["slug"] else ""}{label}</a>' for slug, label in NAV.items())
    title_plain = re.sub(r'&amp;', '&', doc['title'])
    HTML = f'''<!DOCTYPE html>
<html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{title_plain}</title><style>{CSS}</style></head>
<body>
<div class="toolbar"><a href="{doc['slug']}.doc" download>⬇ Word (.doc)</a><a class="alt" href="javascript:window.print()">🖨 Print / PDF</a>{navlinks}</div>
<div class="page">
  <div class="cover"><div class="brand">Ikonia Agency · Documentație Tehnică</div><h1>{doc['title']}</h1>
    <div class="sub">{doc['sub']}</div><div class="systems">{chips}</div>
    <div class="foot">{n} capitole · document confidențial · proprietatea Ikonia Agency S.R.L.</div></div>
  <div class="inner"><div class="toc-wrap"><h2>Cuprins</h2><ol class="toc">{toc_inner}</ol></div>
{content}
  </div></div></body></html>'''
    open(f'/var/www/erp/public/{doc["slug"]}.html', 'w', encoding='utf-8').write(HTML)
    DOC = f'''<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<head><meta charset="utf-8"><title>{title_plain}</title>
<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom><w:DoNotOptimizeForBrowser/></w:WordDocument></xml><![endif]-->
<style>{DOC_CSS}</style></head><body>
<p class="brand">Ikonia Agency · Documentație Tehnică</p><h1>{doc['title']}</h1>
<p class="sub">{doc['sub']} · {n} capitole · confidențial.</p>
<h2>Cuprins</h2><ol class="toc">{toc_inner}</ol>
<br clear="all" style="page-break-before:always">
{content}</body></html>'''
    open(f'/var/www/erp/public/{doc["slug"]}.doc', 'w', encoding='utf-8').write(DOC)

# ---- 3 documente separate ----
for d in DOCS:
    render_chapters.n = 0
    toc, content = render_chapters(d['chapters'])
    build(d, toc, content, len(d['chapters']))
    print(f"{d['slug']}: {len(d['chapters'])} capitole")

# ---- master: cele 3 părți concatenate ----
render_chapters.n = 0
parts = [('PARTEA I — ERP Malinco', MALINCO), ('PARTEA II — ERP maxCL', MAXCL), ('PARTEA III — MentorAPI / WinMentor API', APIDOC)]
toc_all, content_all = [], []
for label, chs in parts:
    t, c = render_chapters(chs, part_label=label)
    toc_all.append(t); content_all.append(c)
build(MASTER, '\n'.join(toc_all), '\n\n'.join(content_all), render_chapters.n)
print(f"{MASTER['slug']}: {render_chapters.n} capitole (master)")
