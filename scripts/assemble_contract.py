#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Asamblează DRAFT CONTRACT (HTML print-ready + .doc Word) din output-ul workflow-ului."""
import json, re, os

F1 = '/tmp/claude-1001/-var-www-erp/a3fee843-14e7-43cd-91a6-89a8b19f844f/tasks/wpsym6vi3.output'
contract = json.load(open(F1))['result']['contract']

# versiuni condensate (workflow condensare), fallback pe original
COND_F = '/tmp/claude-1001/-var-www-erp/a3fee843-14e7-43cd-91a6-89a8b19f844f/tasks/wm5d3xb5g.output'
cond_map = {}
if os.path.exists(COND_F) and os.path.getsize(COND_F) > 0:
    try:
        for c in json.load(open(COND_F))['result']['condensed']:
            if c and c.get('body_html'):
                cond_map[c['i']] = c['body_html']
    except Exception as e:
        print('cond load skip:', e)
print(f'condensate disponibile: {sorted(cond_map)}')

# versiuni REBALANSATE (workflow rebalansare etică) — prioritate maximă
REBAL_F = '/tmp/claude-1001/-var-www-erp/a3fee843-14e7-43cd-91a6-89a8b19f844f/tasks/wd12oub2a.output'
rebal_map = {}
if os.path.exists(REBAL_F) and os.path.getsize(REBAL_F) > 0:
    try:
        for x in json.load(open(REBAL_F))['result']['rebal']:
            if x and x.get('body_html'):
                rebal_map[x['key']] = x['body_html']
    except Exception as e:
        print('rebal load skip:', e)
REBAL_IDX = {'ip': 2, 'price': 3, 'sla': 4, 'newfeat': 5, 'liability': 6}
idx_rebal = {idx: rebal_map[k] for k, idx in REBAL_IDX.items() if k in rebal_map}
print(f'rebalansate disponibile: {sorted(rebal_map)}')

# jetoane cross-referință -> numere finale de articol
ART_TOKENS = {'PRET': 5, 'IP': 6, 'DATE': 7, 'SLA': 8, 'NOI': 9,
              'GAR': 10, 'CONF': 11, 'GDPR': 12, 'REZIL': 14}

# audit juridic final (additions de inserat)
REVIEW_F = '/tmp/claude-1001/-var-www-erp/a3fee843-14e7-43cd-91a6-89a8b19f844f/tasks/wpmr1plw0.output'
review = []
if os.path.exists(REVIEW_F) and os.path.getsize(REVIEW_F) > 0:
    try:
        review = json.load(open(REVIEW_F))['result']['review']
    except Exception as e:
        print('review load skip:', e)
print(f'audit: {sum(len(a["additions"]) for a in review)} additions')

# ---- ARTICOL NOU (injectat după IP): proprietatea asupra datelor și conținutului prelucrat ----
# Devine Art. 7 după renumerotare. Referințe finale: buyout=Art.5, IP=Art.6, GDPR=Art.12.
ART_DATE = '''<h3>Art. 7 — Proprietatea asupra Datelor și a Conținutului Prelucrat de Sistem</h3>
<p>7.1. <strong>Categorii de date.</strong> În scopul prezentului Contract, Părțile disting în mod expres trei categorii distincte de date, supuse unor regimuri juridice diferite:</p>
<ol type="a">
<li><strong>Date cu caracter personal</strong> — datele persoanelor fizice prelucrate prin intermediul Sistemelor, guvernate exclusiv de Art. 12 (Protecția datelor / GDPR);</li>
<li><strong>Date operaționale brute ale Beneficiarului</strong> — datele factuale și tranzacționale introduse de către sau în numele Beneficiarului ori provenite din activitatea curentă a acestuia (coduri și denumiri de produse, prețuri proprii, stocuri, comenzi, parteneri și clienți), reprezentând fondul de date propriu al Beneficiarului;</li>
<li><strong>Conținut prelucrat și valoare adăugată</strong> — totalitatea conținutului generat, structurat, îmbogățit, normalizat sau optimizat prin intermediul Sistemelor și al componentelor lor de inteligență artificială și automatizare, incluzând fără a se limita la: descrierile, titlurile și textele de produs generate ori optimizate automat, clasificările și categorisirile, conținutul de optimizare pentru motoarele de căutare (SEO), datele îmbogățite și normalizate, modelul de date, schema bazei de date, mapările, dicționarele de corespondență, algoritmii, precum și orice operă derivată rezultată din procesarea efectuată de Sisteme.</li>
</ol>
<p>7.2. <strong>Proprietatea Beneficiarului asupra datelor brute.</strong> Datele operaționale brute prevăzute la art. 7.1 lit. (b) rămân proprietatea Beneficiarului.</p>
<p>7.3. <strong>Restituirea datelor brute la încetare.</strong> La încetarea Contractului din orice cauză, Prestatorul va pune la dispoziția Beneficiarului, la cererea scrisă a acestuia formulată în termen de maximum 30 (treizeci) de zile de la încetare, un export al datelor operaționale brute (art. 7.1 lit. b) într-un format electronic structurat, uzual și prelucrabil automat (de exemplu CSV sau SQL). Prestatorul este îndreptățit să factureze efortul tehnic rezonabil aferent extragerii și pregătirii exportului, la tariful orar de 70 €/oră. Această obligație de restituire este condiționată de achitarea prealabilă și integrală a tuturor sumelor scadente datorate Prestatorului.</p>
<p>7.4. <strong>Proprietatea Prestatorului asupra conținutului prelucrat.</strong> Conținutul prelucrat și valoarea adăugată prevăzute la art. 7.1 lit. (c) constituie operă proprie a Prestatorului, protejată prin dreptul de autor potrivit Legii nr. 8/1996, fiind rezultatul exclusiv al investiției, know-how-ului, tehnologiei și componentelor de inteligență artificială ale Prestatorului. Împrejurarea că prelucrarea a pornit de la date brute furnizate de Beneficiar <strong>nu conferă Beneficiarului niciun drept de proprietate, de coautorat sau de altă natură</strong> asupra conținutului prelucrat și a valorii adăugate.</p>
<p>7.5. <strong>Licență limitată asupra conținutului prelucrat.</strong> Pe durata Contractului, Beneficiarul beneficiază de o licență neexclusivă, netransferabilă și revocabilă de a utiliza conținutul prelucrat exclusiv în cadrul și în scopul operării Sistemelor. Această licență încetează de drept la încetarea Contractului, cu excepția cazului achiziției integrale a codului-sursă (buyout), situație în care drepturile se transferă în condițiile Art. 6.</p>
<p>7.6. <strong>Lipsa obligației de predare a conținutului prelucrat.</strong> În lipsa exercitării și a achitării integrale a opțiunii de achiziție (buyout) prevăzute la Art. 5, Prestatorul <strong>nu are obligația</strong> de a preda, exporta, livra în formă reutilizabilă sau de a permite extragerea conținutului prelucrat și a valorii adăugate prevăzute la art. 7.1 lit. (c), inclusiv a modelului și a schemei de date și a conținutului generat prin inteligență artificială. Beneficiarul se obligă să nu copieze, extragă, reproducă, reutilizeze ori exploateze conținutul prelucrat în afara Sistemelor licențiate, sub sancțiunea răspunderii pentru încălcarea dreptului de autor.</p>
<p>7.7. <strong>Delimitare față de obligațiile GDPR.</strong> Obligația de restituire ori de ștergere a datelor cu caracter personal prevăzută la Art. 12 privește exclusiv datele cu caracter personal și <strong>nu se extinde</strong> asupra codului-sursă, a modelului ori a schemei de date sau a conținutului prelucrat, care rămân supuse regimului prezentului articol și al Art. 6.</p>'''

# dacă există versiunea rebalansată a Art. 7, o folosim
if 'data' in rebal_map:
    ART_DATE = rebal_map['data']

# data order: 0=titlu/parti/preambul/def, 1=Obiect, 2=IP, 3=Pret, 4=Mentenanta, 5=Funct.noi, 6=Garantie, 7=Finale
# ART_DATE injectat după IP (index 2) -> devine Art. 7
ORDER = [0, 1, 3, 2, ART_DATE, 4, 5, 6, 7]

# ---- blocul real de parti (date din ERP / OpenAPI) ----
PARTY_BLOCK = '''<ol>
<li><strong>IKONIA AGENCY S.R.L.</strong>, persoană juridică română, cu sediul social în municipiul Oradea, B-dul Dacia nr. 31, bloc AN57, etaj 4, ap. 13, județul Bihor, cod poștal 410464, înregistrată la Oficiul Registrului Comerțului sub nr. <strong>J05/3636/2022</strong>, având Cod Unic de Înregistrare <strong>RO47310601</strong> (plătitoare de TVA), telefon 0742490584, e-mail codrut@ikonia.ro, cont bancar IBAN <span class="fill">______________________________</span> deschis la <span class="fill">________________</span>, reprezentată legal prin <span class="fill">________________________</span>, în calitate de Administrator, denumită în continuare „<strong>Prestatorul</strong>"; și</li>
<li><strong>MALINCO PRODEX S.R.L.</strong>, persoană juridică română, cu sediul social în localitatea Sântandrei nr. 391/a2, ap. 2, județul Bihor, cod poștal 417515, înregistrată la Oficiul Registrului Comerțului sub nr. <strong>J05/1209/1997</strong>, având Cod Unic de Înregistrare <strong>RO9669166</strong>, telefon 0259447722, cont bancar IBAN <span class="fill">______________________________</span> deschis la <span class="fill">________________</span>, reprezentată legal prin <span class="fill">________________________</span>, în calitate de Administrator, denumită în continuare „<strong>Beneficiarul</strong>".</li>
</ol>
<p>Prestatorul și Beneficiarul vor fi denumiți în mod individual „<strong>Partea</strong>", iar împreună „<strong>Părțile</strong>".</p>'''


def article_nums(body):
    nums = []
    for m in re.finditer(r'Art\.?\s*(\d+)', body):
        n = m.group(1)
        if n not in nums:
            nums.append(n)
    return nums


def renumber(body, mapping):
    """mapping: {old_str: new_int}. Folosește token \\x00 ca să evite re-matching."""
    for old, new in mapping.items():
        new_t = f'\x00{new}\x00'
        # heading + cross-ref: "Art. 5" / "art. 5"
        body = re.sub(r'([Aa]rt\.\s*)' + old + r'\b', r'\1' + new_t, body)
        # clause prefix la început de paragraf/listă: "<p>5.1."
        # sub-alineate „N.x" oriunde (inclusiv în <strong>, „alin. (N.x)"), DAR nu sume „N.500" (3 cifre)
        body = re.sub(r'(?<!\d)' + old + r'\.(\d{1,2})(?!\d)', new_t + r'.\1', body)
    return body.replace('\x00', '')


sections_html = []
counter = 1
for di in ORDER:
    if isinstance(di, str):      # articol custom injectat (ex: ART_DATE)
        body = di
    else:
        body = idx_rebal.get(di, cond_map.get(di, contract[di]['body_html']))
    if di == 0:
        # scoate h3 cu titlul documentului (îl punem în header propriu)
        body = re.sub(r'<h3>\s*CONTRACT DE.*?</h3>', '', body, count=1, flags=re.I | re.S)
        # scoate paragraful "încheiat astăzi [●]..." (data o punem în header)
        body = re.sub(r'<p>\s*încheiat astăzi.*?</p>', '', body, count=1, flags=re.I | re.S)
        # înlocuiește blocul de părți (lista cu [●]) cu datele reale
        body = re.sub(
            r'(<h3>Art\.\s*1[^<]*Părțile[^<]*</h3>\s*).*?(?=<h3>)',
            r'\1' + PARTY_BLOCK + '\n\n',
            body, count=1, flags=re.S)
    olds = article_nums(re.sub(r'<p>.*?</p>', '', body, flags=re.S))  # numere din titluri h3
    # mai sigur: ia numerele din h3
    h3nums = []
    for h in re.findall(r'<h3>(.*?)</h3>', body, flags=re.S):
        m = re.search(r'Art\.?\s*(\d+)', h)
        if m and m.group(1) not in h3nums:
            h3nums.append(m.group(1))
    mapping = {old: counter + i for i, old in enumerate(h3nums)}
    counter += len(h3nums)
    body = renumber(body, mapping)
    # curăță [●] rămase
    body = body.replace('[●]', '<span class="fill">____________</span>')
    sections_html.append(body)

CONTENT = '\n\n'.join(sections_html)

# corectură program de suport: luni–joi, 10:00–17:00 (cerere client)
CONTENT = CONTENT.replace(
    'se prestează în zilele lucrătoare (luni–vineri, cu excepția sărbătorilor legale din România), în intervalul orar 09:00–18:00, ora României.',
    'se prestează de luni până joi (cu excepția sărbătorilor legale din România), în intervalul orar 10:00–17:00, ora României.')

# scoate minimul de 5% din indexare oriunde apare (definiție + reînnoire), inclusiv variantele cu tag-uri
CONTENT = re.sub(
    r',?\s*(?:dar\s*)?(<strong>\s*)?nu mai pu[țt]in de 5\s*%\s*pe an(\s*</strong>)?',
    lambda m: (m.group(1) or '') + 'fără prag minim și fără efect retroactiv' + (m.group(2) or ''),
    CONTENT)
CONTENT = re.sub(r',\s*fără prag minim', ', fără prag minim', CONTENT)

# înlocuiește jetoanele cross-referință cu numerele finale de articol
for k, v in ART_TOKENS.items():
    CONTENT = CONTENT.replace('{{ART_' + k + '}}', f'Art. {v}')

# ===== clauze suplimentare Prestator (cerute explicit) =====
ART9_SCOPE = '''<h4>9.14 Natura și limitele dreptului de utilizare permanentă a dezvoltărilor plătite</h4>
<p>9.14.1. Dreptul de utilizare permanentă al Beneficiarului asupra dezvoltărilor noi achitate, prevăzut la prezentul articol și la Art. 7, se exercită <strong>exclusiv ca parte integrantă a Sistemului</strong> în care dezvoltarea a fost implementată și în aceleași condiții și model de acces aplicabile Sistemului însuși.</p>
<p>9.14.2. În cadrul Opțiunii 2 (Abonament), dezvoltarea funcționează <strong>numai ca o componentă a Sistemului licențiat și numai pe durata în care Beneficiarul deține dreptul de utilizare a Sistemului</strong> (abonament activ). Dezvoltarea nu este livrată ca produs software de sine stătător și nu conferă Beneficiarului dreptul de a o opera independent de Sistem.</p>
<p>9.14.3. Caracterul „permanent" și „care supraviețuiește încetării" al dreptului de utilizare semnifică exclusiv: (i) protecția Beneficiarului împotriva retragerii, dezactivării sau condiționării de către Prestator a funcționalității achitate, pe durata Contractului; și (ii) includerea dezvoltării respective în codul transferat în cazul exercitării opțiunii de achiziție (buyout) ori al Opțiunii 1. Acesta <strong>nu conferă</strong> Beneficiarului dreptul de a obține funcționarea dezvoltării în afara Sistemului ori după încetarea accesului la Sistem, în lipsa achiziției codului.</p>
<p>9.14.4. După încetarea Contractului fără achiziția codului, accesul Beneficiarului la rezultatele dezvoltării se realizează exclusiv prin exportul rezultatelor și datelor în condițiile Art. 7, iar nu prin operarea în continuare a software-ului Prestatorului.</p>'''

ART8_CREDIT = '''<h4>8.16 Natura și limitele creditelor de serviciu</h4>
<p>8.16.1. Creditele de serviciu prevăzute la alin. (8.9) constituie <strong>unicul remediu financiar</strong> (cu titlu de penalitate convențională) pentru nerespectarea nivelurilor de disponibilitate și se acordă <strong>exclusiv prin diminuarea facturilor viitoare, fără plată în numerar și fără a da naștere vreunui drept de rambursare în bani</strong>.</p>
<p>8.16.2. Creditele de serviciu nu se cumulează cu alte despăgubiri pentru aceeași indisponibilitate și nu pot depăși, agregat, echivalentul a <strong>două (2) componente lunare de abonament</strong> aferente găzduirii și mentenanței într-un an contractual.</p>
<p>8.16.3. Creditele de serviciu acumulate și neutilizate până la data încetării Contractului <strong>se sting de drept</strong>, fără a fi datorate în bani și fără a constitui o creanță a Beneficiarului. Prezentul alineat nu aduce atingere remediilor pentru prejudicii dovedite cauzate prin culpă gravă sau dol, în limitele Art. 10.</p>'''

# ===== integrare audit (additions) cu fix referințe interne + clauzele Prestator =====
import collections
def _fixrefs(html, old, new):
    return re.sub(r'(?<!\d)' + str(old) + r'\.(\d{1,2})(?!\d)', f'{new}.' + r'\1', html)
_audit = collections.defaultdict(list)
for _a in review:
    for _x in _a['additions']:
        _h = _x['html']
        if _x['label'].startswith('10.'):
            _h = _fixrefs(_h, 9, 10)   # Art.10: refs 9.x -> 10.x
        elif _x['label'].startswith('9.'):
            _h = _fixrefs(_h, 7, 9)    # Art.9: refs 7.x -> 9.x
        _audit[_x['insert_before_article']].append((_x['label'], _h))
for _k in _audit:
    _audit[_k].sort(key=lambda t: [int(p) for p in t[0].split('.')])
INSERT = {k: [h for _, h in v] for k, v in _audit.items()}
INSERT.setdefault('Art. 10', []).append(ART9_SCOPE)   # clauza mea pe Art. 9 (înainte de Art. 10)
INSERT.setdefault('Art. 9', []).append(ART8_CREDIT)    # clauza mea pe Art. 8 (înainte de Art. 9)
for _anchor, _blocks in INSERT.items():
    _num = _anchor.split()[-1]
    _m = re.search(r'<h3>\s*Art\.\s*' + _num + r'\b', CONTENT)
    if _m:
        CONTENT = CONTENT[:_m.start()] + '\n'.join(_blocks) + '\n' + CONTENT[_m.start():]
    else:
        print('ANCHOR NEGASIT:', _anchor)

TOTAL_ART = counter - 1

# ---- semnături + disclaimer ----
SIGNATURES = '''<h3>Semnăturile Părților</h3>
<p>Prezentul Contract a fost încheiat astăzi, <span class="fill">______________</span>, în două (2) exemplare originale, câte unul pentru fiecare Parte, ambele având aceeași valoare juridică.</p>
<table class="sign-table">
<tr><td><strong>PRESTATOR</strong><br>IKONIA AGENCY S.R.L.</td><td><strong>BENEFICIAR</strong><br>MALINCO PRODEX S.R.L.</td></tr>
<tr><td>Reprezentant legal:<br><span class="fill">____________________</span><br><br>Semnătura: ____________________<br><br>Data: ______________</td>
<td>Reprezentant legal:<br><span class="fill">____________________</span><br><br>Semnătura: ____________________<br><br>Data: ______________</td></tr>
</table>'''

DISCLAIMER = '''<div class="disclaimer">
<strong>⚠ DOCUMENT DRAFT — model orientativ.</strong> Prezentul document constituie un <strong>proiect de contract (draft)</strong> generat ca punct de plecare în negociere. Deși acoperă clauzele esențiale și protejează interesele Prestatorului, el <strong>NU constituie consultanță juridică</strong> și trebuie revizuit și avizat de un avocat/consilier juridic înainte de semnare. Câmpurile marcate cu linie punctată (IBAN, reprezentanți legali, date, bancă) se completează la semnare.
</div>'''

# ============ HTML (screen + print) ============
CSS = '''
:root{--ink:#1a1a1a;--accent:#1f3a5f;--accent2:#2c5282;--line:#d9dee5;--soft:#f4f6f9;}
*{box-sizing:border-box;}
body{margin:0;background:#525659;color:var(--ink);font-family:Georgia,'Times New Roman',serif;}
.toolbar{position:sticky;top:0;z-index:10;background:#2d3748;padding:10px 16px;display:flex;gap:10px;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.3);}
.toolbar a{display:inline-flex;align-items:center;gap:6px;background:var(--accent2);color:#fff;text-decoration:none;padding:8px 18px;border-radius:6px;font-family:Arial,sans-serif;font-size:13px;font-weight:600;}
.toolbar a.alt{background:#4a5568;}
.toolbar a:hover{filter:brightness(1.12);}
.page{background:#fff;max-width:820px;margin:22px auto;padding:42px 56px 48px;box-shadow:0 6px 26px rgba(0,0,0,.32);}
.dochead{text-align:center;border-bottom:3px double var(--accent);padding-bottom:20px;margin-bottom:8px;}
.docbrand{font-family:Arial,sans-serif;font-size:11px;letter-spacing:3px;color:var(--accent2);text-transform:uppercase;font-weight:700;}
.doctitle{font-size:24px;font-weight:700;color:var(--accent);margin:12px 0 6px;line-height:1.25;}
.draftbadge{display:inline-block;background:#fff;border:2px solid #c0392b;color:#c0392b;font-family:Arial,sans-serif;font-weight:800;letter-spacing:4px;font-size:13px;padding:4px 18px;border-radius:4px;margin-top:6px;}
.docmeta{font-family:Arial,sans-serif;font-size:12px;color:#555;margin-top:12px;}
.content{text-align:justify;font-size:11.2px;line-height:1.36;}
.content h3{font-size:12.4px;color:var(--accent);border-bottom:1px solid var(--line);padding-bottom:2px;margin:12px 0 5px;page-break-after:avoid;break-after:avoid;}
.content h4{font-size:14px;color:var(--accent2);margin:16px 0 6px;}
.content p{margin:3px 0;}
.content ol,.content ul{margin:4px 0 4px 4px;padding-left:20px;}
.content li{margin:2.5px 0;}
.content table{width:100%;border-collapse:collapse;margin:8px 0;font-size:11px;}
.content table th,.content table td{border:1px solid var(--line);padding:7px 9px;text-align:left;vertical-align:top;}
.content table th{background:var(--soft);color:var(--accent);}
.content code{background:var(--soft);padding:1px 5px;border-radius:3px;font-size:12.5px;}
.fill{color:#888;letter-spacing:1px;}
.sign-table{width:100%;border-collapse:collapse;margin-top:14px;}
.sign-table td{border:1px solid var(--line);padding:16px;width:50%;vertical-align:top;font-size:13px;}
.disclaimer{background:#fef6f5;border:1.5px solid #e0b4b0;border-left:5px solid #c0392b;padding:14px 16px;border-radius:6px;font-size:12.5px;line-height:1.55;margin-top:26px;font-family:Arial,sans-serif;color:#5a2a26;}
@media print{
  @page{size:A4;margin:11mm 12mm;}
  body{background:#fff;}
  .toolbar{display:none;}
  .page{box-shadow:none;margin:0;max-width:none;padding:0;}
  .content h3{page-break-after:avoid;break-after:avoid;}
  .content table,.sign-table,.disclaimer{page-break-inside:avoid;break-inside:avoid;}
  .content{text-rendering:optimizeLegibility;}
}
@media screen and (max-width:760px){.page{padding:28px 20px;}.content{font-size:14px;}}
'''

HTML = f'''<!DOCTYPE html>
<html lang="ro"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DRAFT — Contract Ikonia ⇄ Malinco</title>
<style>{CSS}</style></head>
<body>
<div class="toolbar">
  <a href="draft-contract.doc" download>⬇ Descarcă Word (.doc)</a>
  <a class="alt" href="javascript:window.print()">🖨 Printează / Salvează PDF</a>
</div>
<div class="page">
  <div class="dochead">
    <div class="docbrand">Ikonia Agency S.R.L.</div>
    <div class="doctitle">Contract de Licență, Dezvoltare<br>și Mentenanță Software</div>
    <div class="draftbadge">— DRAFT —</div>
    <div class="docmeta">Nr. <span class="fill">________</span> / data <span class="fill">______________</span> &nbsp;·&nbsp; încheiat între Prestator și Beneficiar</div>
  </div>
  <div class="content">
{CONTENT}

{SIGNATURES}
{DISCLAIMER}
  </div>
</div>
</body></html>'''

open('/var/www/erp/public/draft-contract.html', 'w', encoding='utf-8').write(HTML)

# ============ .DOC (Word-compatible HTML) ============
DOC_CSS = '''
@page{size:A4;margin:2cm;}
body{font-family:'Georgia',serif;font-size:11pt;line-height:1.5;text-align:justify;color:#1a1a1a;}
h1{font-size:18pt;color:#1f3a5f;text-align:center;margin:0 0 4pt;}
.brand{font-family:Arial,sans-serif;font-size:9pt;letter-spacing:2pt;color:#2c5282;text-align:center;text-transform:uppercase;}
.draft{text-align:center;color:#c0392b;font-family:Arial,sans-serif;font-weight:bold;letter-spacing:3pt;font-size:11pt;margin:6pt 0;}
.meta{text-align:center;font-family:Arial,sans-serif;font-size:9pt;color:#555;margin-bottom:10pt;border-bottom:1.5pt solid #1f3a5f;padding-bottom:8pt;}
h3{font-size:12pt;color:#1f3a5f;margin:16pt 0 6pt;border-bottom:0.75pt solid #ccc;padding-bottom:3pt;}
h4{font-size:11pt;color:#2c5282;margin:10pt 0 4pt;}
p{margin:5pt 0;}
ol,ul{margin:5pt 0;}
li{margin:3pt 0;}
table{border-collapse:collapse;width:100%;font-size:9.5pt;margin:8pt 0;}
td,th{border:0.75pt solid #b8c0cc;padding:5pt 7pt;vertical-align:top;text-align:left;}
th{background:#eef2f7;color:#1f3a5f;}
code{font-family:'Courier New',monospace;font-size:9.5pt;background:#f2f4f7;}
.fill{color:#777;}
.disclaimer{border:1pt solid #c0392b;background:#fdf0ef;padding:8pt;font-family:Arial,sans-serif;font-size:9pt;margin-top:14pt;}
.sign-table td{width:50%;padding:14pt;}
'''

DOC = f'''<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<head><meta charset="utf-8">
<title>DRAFT Contract Ikonia - Malinco</title>
<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom><w:DoNotOptimizeForBrowser/></w:WordDocument></xml><![endif]-->
<style>{DOC_CSS}</style></head>
<body>
<p class="brand">Ikonia Agency S.R.L.</p>
<h1>Contract de Licență, Dezvoltare și Mentenanță Software</h1>
<p class="draft">— DRAFT —</p>
<p class="meta">Nr. ________ / data ______________ · încheiat între Prestator și Beneficiar</p>
{CONTENT}

{SIGNATURES}
{DISCLAIMER}
</body></html>'''

open('/var/www/erp/public/draft-contract.doc', 'w', encoding='utf-8').write(DOC)

print(f"OK — {TOTAL_ART} articole renumerotate.")
print(f"HTML: {len(HTML):,} bytes  ->  public/draft-contract.html")
print(f"DOC:  {len(DOC):,} bytes  ->  public/draft-contract.doc")
