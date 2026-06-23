#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import json, html, re

OUT = '/tmp/claude-1001/-var-www-erp/a3fee843-14e7-43cd-91a6-89a8b19f844f/tasks/w5x0mg6an.output'
d = json.load(open(OUT))['result']
RATE = 70
# Recalibrare: estimarea multi-agent reflecta "rebuild de la zero cu echipa".
# Devizul comercial reflecta efortul REAL livrat (optimizat) -> factor pe module.
# Fundatia (arhitectura obligatorie) NU se scaleaza si include si lucrul cross-modul.
FACTOR = 0.515
# MentorAPI (deviz) aliniat la valoarea produsului standalone (~9.500 € = 135h).
MENTORAPI_HOURS = 135
estimates = d['estimates']

# 7 module gasite de critic -> 3 sectiuni
missing_sections = [
    ("Furnizori & Date de Bază (Master Data)", [
        ("Modul Furnizori + motor asociere produs-furnizor", "SupplierResource (1036 linii) cu RelationManagers (Contacts, Emails, Feeds, Products), trepte de preț, plafoane PO, comenzi de asociere automată produs↔furnizor — strat fundamental pe care depind Achiziții și BI", 120),
        ("Date de bază Clienți + lookup firmă din CUI (ANAF/OpenAPI)", "CustomerResource, OpenApiCompanyLookupService — normalizare CUI + preluare automată date firmă (denumire, reg. com., adresă, plătitor TVA), folosit în Oferte și Clienți", 40),
        ("Asociere EAN scanat la produs (auto-detecție + aprobare)", "EanAssociationRequest + Resource + logică InventoryController: flux cerere/aprobare la scanarea unui cod necunoscut în depozit", 24),
        ("Curs valutar BNR (EUR) + serii documente", "BnrExchangeRateService (preluare/cache curs BNR pentru furnizori EUR), DocumentSeriesSettingsPage, generare serii OFF/PNR/PO", 24),
    ]),
    ("Integrări Specifice per Furnizor", [
        ("Integrări Fischer, Temad, Cemacon", "Trei integrări custom one-off, distincte de Toya: parsing feed propriu per furnizor, mapare coduri și prețuri, actualizare product_suppliers + WooCommerce, alerte modificări preț", 96),
    ]),
    ("Sincronizare Real-Time & Monitorizare", [
        ("Webhook-uri WooCommerce în timp real", "WooWebhookController (271 linii) — sincronizare push live (HMAC), produse + comenzi actualizate instant, distinct de sync-ul programat", 32),
        ("Monitorizare modificări articole WinMentor + anomalii preț", "DetectWinmentorArticleChanges — detecție SKU/denumire schimbate în WinMentor + sincronizare ERP/Woo, detecție anomalii preț achiziție", 40),
    ]),
]

def esc(s): return html.escape(str(s), quote=False)
def short(desc, maxlen=155):
    desc = desc.strip()
    if len(desc) <= maxlen:
        return desc
    cut = desc[:maxlen]
    for sep in ['. ', '; ', ', ', ' ']:
        i = cut.rfind(sep)
        if i > maxlen * 0.55:
            return cut[:i].rstrip(' ,;.') + '…'
    return cut.rstrip() + '…'
def fmt(n): return f"{n:,.0f}".replace(',', '.')
def fmt2(n): return f"{n:,.2f}".replace(',', 'X').replace('.', ',').replace('X', '.')
def scale(h): return max(1, round(h * FACTOR))
def clean_name(m): return re.sub(r'^\d+\.\s*', '', m).strip()

# ---- construieste structura: FUNDATIE OBLIGATORIE (arhitectura + infra + securitate + cross-modul) ----
# Fundatia = Infrastructura (idx 0) + Securitate/DevOps (idx 14) + lucru cross-modul real, NESCALAT.
infra = estimates[0]
security = estimates[14]
foundation_extra = [
    {'name': 'Layer de servicii partajate & arhitectură cross-modul',
     'description': 'Servicii partajate (clienți API, formatare, helpers), pattern-uri reutilizate consistent în toate cele 17 module, refactorizări transversale și menținerea coerenței arhitecturale pe întreaga platformă.',
     'hours': 52},
    {'name': 'CI/CD, deploy automatizat & monitorizare producție',
     'description': 'Pipeline build/deploy, configurare supervisor + Horizon, monitorizare workeri, health checks automate, proceduri rollback, hardening server de producție.',
     'hours': 30},
    {'name': 'Testare & QA transversală + documentație tehnică',
     'description': 'Testare integrată end-to-end, verificare regresii cross-modul la fiecare livrare, documentație tehnică completă și manual de utilizare.',
     'hours': 32},
    {'name': 'Orchestrare & interdependențe între module',
     'description': 'Modulele NU sunt insule — Achiziții, Furnizori, Stoc, WinMentor, WooCommerce și Oferte împart aceleași date și fluxuri. Modelarea relațiilor, sincronizarea stărilor între module, fluxurile end-to-end (ex: comandă online → necesar → PO → recepție → stoc → preț site) și consistența datelor reprezintă efort fundamental fără de care modulele nu pot funcționa unele cu altele.',
     'hours': 100},
]
foundation_items = infra['line_items'] + security['line_items'] + foundation_extra
# modulele functionale = restul (fara Infra idx0 si Securitate idx14)
modules = estimates[1:14]

def section_block(title, line_items, mandatory=False, raw=False, factor=None, show_subtotal=True):
    """Returneaza (rows_html, subtotal_hours). raw=True -> ore nescalate; factor -> scalare locala."""
    if raw:
        hfun = lambda x: x
    elif factor is not None:
        hfun = lambda x: max(1, round(x * factor))
    else:
        hfun = scale
    sub_h = sum(hfun(li['hours']) for li in line_items)
    cls = 'cat-row mandatory' if mandatory else 'cat-row'
    badge = ' <span class="oblig">OBLIGATORIU</span>' if mandatory else ''
    rows = [f'      <tr class="{cls}"><td colspan="5">{esc(title)}{badge}</td></tr>']
    for li in line_items:
        h = hfun(li['hours'])
        rows.append(f'''      <tr>
        <td><div class="item-name">{esc(li['name'])}</div><div class="item-desc">{esc(short(li['description']))}</div></td>
        <td class="center">{h}</td>
        <td class="right">{RATE}</td>
        <td>{fmt(h*RATE)}</td>
        <td class="right"><span class="badge badge-done">Livrat</span></td>
      </tr>''')
    if show_subtotal:
        rows.append(f'''      <tr class="subtotal-row"><td colspan="3">Subtotal — {esc(title)}</td><td>{fmt(sub_h*RATE)} €</td><td class="right">{sub_h}h</td></tr>''')
    return '\n'.join(rows), sub_h

# fundatie (nescalata)
found_rows, found_h = section_block('Arhitectură, Infrastructură, Securitate & DevOps', foundation_items, mandatory=True, raw=True)

# module functionale (15-1 + 3 missing)
all_modules = [(clean_name(m['module']), m['line_items'], m.get('scope_summary',''), m.get('complexity','')) for m in modules]
for title, items in missing_sections:
    all_modules.append((title, [{'name':n,'description':de,'hours':h} for n,de,h in items], '', 'medie'))

mod_rows_parts = []
module_costs = []  # (title, hours, eur)
for i,(title, items, summ, cx) in enumerate(all_modules, 1):
    if 'MentorAPI' in title:
        full = sum(li['hours'] for li in items)
        lf = MENTORAPI_HOURS / full if full else 1
        block, sub_h = section_block(f'{i}. {title}', items, factor=lf, show_subtotal=False)
    else:
        block, sub_h = section_block(f'{i}. {title}', items, show_subtotal=False)
    mod_rows_parts.append(block)
    module_costs.append((title, sub_h, sub_h*RATE, summ, cx))

mod_rows = '\n\n'.join(mod_rows_parts)

found_eur = found_h * RATE
modules_h = sum(c[1] for c in module_costs)
modules_eur = modules_h * RATE
total_h = found_h + modules_h
subtotal = total_h * RATE
tva = round(subtotal * 0.21, 2)
total_tva = subtotal + tva

# ---- cards: fundatie + top module dupa cost ----
def first_sentences(text, maxlen=240):
    text = text.strip()
    if len(text) <= maxlen: return text
    cut = text[:maxlen]
    for sep in ['. ', '; ', ', ']:
        idx = cut.rfind(sep)
        if idx > maxlen*0.5: return cut[:idx+1]
    return cut.rstrip()+'…'

found_summary = ('Fundația obligatorie a întregii platforme: două panel-uri Filament cu temă custom, sistem multi-tenant '
                 'per locație, permisiuni granulare per rol, autentificare + PIN, cozi Redis/Horizon, securitate (CSP) și '
                 'DevOps, plus layerul de servicii partajate folosit de toate modulele. Fără această fundație, niciun modul nu poate funcționa.')
cards = []
# card fundatie (special, premium)
cards.append(f'''      <div class="module-card foundation">
        <div class="module-card-title"><span class="icon">★</span> Arhitectură, Infrastructură, Securitate & DevOps <span class="oblig">OBLIGATORIU</span></div>
        <p>{esc(found_summary)}</p>
        <div class="module-pills"><span class="pill pill-red">Fundație obligatorie — facturată o singură dată</span><span class="pill pill-blue">{found_h}h</span><span class="pill pill-green">{fmt(found_eur)} €</span></div>
      </div>''')
for i,(title,h,eur,summ,cx) in enumerate(module_costs,1):
    if not summ:
        summ = 'Modul funcțional dedicat.'
    cards.append(f'''      <div class="module-card">
        <div class="module-card-title"><span class="icon">{i}</span> {esc(title)}</div>
        <p>{esc(first_sentences(summ,240))}</p>
        <div class="module-pills"><span class="pill pill-blue">{h}h efort</span></div>
      </div>''')

cards_block = '\n\n'.join(cards)

open('/tmp/deviz_cards.html','w').write(cards_block)
open('/tmp/deviz_found.html','w').write(found_rows)
open('/tmp/deviz_modrows.html','w').write(mod_rows)
json.dump({
  'total_h':total_h,'subtotal':subtotal,'tva':tva,'total_tva':total_tva,
  'found_h':found_h,'found_eur':found_eur,'modules_h':modules_h,'modules_eur':modules_eur,
  'n_modules':len(all_modules),
  'fmt':{'subtotal':fmt(subtotal),'tva':fmt2(tva),'total_tva':fmt2(total_tva),'found_eur':fmt(found_eur),'modules_eur':fmt(modules_eur)}
}, open('/tmp/deviz_totals.json','w'))

print(f"FACTOR={FACTOR}")
print(f"Fundatie obligatorie: {found_h}h = {fmt(found_eur)} €")
print(f"Module functionale:   {modules_h}h = {fmt(modules_eur)} €")
print(f"TOTAL: {total_h}h = {fmt(subtotal)} € fara TVA | {fmt2(total_tva)} € cu TVA")
print(f"Sub 125k: {'DA' if subtotal <= 125000 else 'NU - mai reduc'}")
print("--- cost per modul ---")
for title,h,eur,_,_ in module_costs:
    print(f"  {fmt(eur):>8} €  ({h}h)  {title}")
