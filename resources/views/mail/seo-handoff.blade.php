@extends('mail.layout')

@section('content')
@php
  $h2 = 'margin:24px 0 8px;font-size:16px;color:#B91C1C;font-weight:700;';
  $p  = 'margin:0 0 10px;font-size:14px;line-height:1.6;color:#1A1A1A;';
  $li = 'margin:0 0 6px;font-size:14px;line-height:1.55;color:#1A1A1A;';
  $td = 'padding:7px 10px;font-size:13px;border-bottom:1px solid #EEE;vertical-align:top;';
  $thd= 'padding:7px 10px;font-size:12px;text-align:left;background:#F5F0E8;color:#6B7280;border-bottom:2px solid #E8DFD0;';
@endphp

<p style="{{ $p }}">Salut Codrut,</p>
<p style="{{ $p }}">Rezumatul analizei SEO &amp; AEO pentru <strong>malinco.ro</strong> din 20.06.2026, ca să continuăm într-o sesiune viitoare. Documentul complet e salvat la <code>/var/www/erp/docs/seo-malinco-handoff-2026-06-20.md</code>.</p>

<h2 style="{{ $h2 }}">Concluzia principală</h2>
<p style="{{ $p }}"><strong>Nu e penalizare.</strong> Poziția medie e stabilă, mai a fost lună record. Declinul moderat coincide cu <strong>May 2026 Core Update</strong>. Marea problemă reală: <strong>rankezi bine, dar nu primești clickuri</strong> — pe interogări comerciale ești pe poziția 2-3 dar cu CTR de 0,1–0,5% (ex: „prenandez" poz. 2.4, 1888 impresii, doar 7 clickuri). SERP-ul 2026 e dominat de Google Shopping + „Popular products" + AI Overviews care înghit clickurile. <strong>Jocul nu mai e „mai multe poziții", ci „cucerirea SERP-ului".</strong></p>

<h2 style="{{ $h2 }}">Ce am rezolvat deja</h2>
<ul style="padding-left:18px;margin:0 0 10px;">
  <li style="{{ $li }}"><strong>robots.txt reparat</strong> (backup salvat): se irosea ~51% din crawl-ul Google pe URL-uri cu filtre (nume parametri greșiți: <code>min_price/max_price</code>, <code>shop_view</code>, <code>per_row</code>). Acum blocate corect + acces permis pentru GPTBot/ClaudeBot/Google-Extended/PerplexityBot.</li>
</ul>

<h2 style="{{ $h2 }}">Inspirație de la lideri (unde te bat acum)</h2>
<p style="{{ $p }}">Leroy: <em>„Fier beton B500C, Ø 10 mm, L 6000 mm"</em> · Dedeman: <em>„Polistiren expandat pentru fațadă, Baudeman EPS 80 grafitat, 15 cm"</em> · Tu: <em>„Fier Beton 10 Bara 6 M – Malinco.ro"</em>.<br>
Ei au: precizie tehnică (clasă, Ø, unități), brand vizibil, sinonime (oțel beton = fier beton), unitate de ambalare (mp/pac), aplicația. <strong>Rădăcina = calitatea datelor de produs</strong> — de acolo iese tot: titluri, schema, snippet ȘI răspunsuri AI/LLM.</p>

<h2 style="{{ $h2 }}">Plan prioritizat de îmbunătățire</h2>
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 12px;">
  <tr><th style="{{ $thd }}">Soluție</th><th style="{{ $thd }}">Impact</th></tr>
  <tr><td style="{{ $td }}">1. Curățare + îmbogățire date produs (clasă, Ø, brand, ambalare, sinonime)</td><td style="{{ $td }}">🔴 maxim</td></tr>
  <tr><td style="{{ $td }}">2. Titluri tehnice curate (fără preț/stoc — alea în schema/meta, live)</td><td style="{{ $td }}">🔴 CTR</td></tr>
  <tr><td style="{{ $td }}">3. Recenzii → stele în Google (acum 0 din 17.440 produse)</td><td style="{{ $td }}">🔴 CTR</td></tr>
  <tr><td style="{{ $td }}">4. Tabel caracteristici tehnice pe produs</td><td style="{{ $td }}">🔴 Google + LLM</td></tr>
  <tr><td style="{{ $td }}">5. Grupare variante (1 pagină/produs cu selector, nu 17k pagini subțiri)</td><td style="{{ $td }}">🔴</td></tr>
  <tr><td style="{{ $td }}">6. Google Shopping / free listings (de auditat feed-ul)</td><td style="{{ $td }}">🔴</td></tr>
  <tr><td style="{{ $td }}">7. Ghiduri &amp; calculatoare („cât polistiren/ciment îmi trebuie")</td><td style="{{ $td }}">🟠 trafic + citări LLM</td></tr>
  <tr><td style="{{ $td }}">8. Pagini brand puternice (model: Hunter); conținut editorial categorii</td><td style="{{ $td }}">🟠</td></tr>
  <tr><td style="{{ $td }}">9. Redirect 301 cele 14 produse care dau 404</td><td style="{{ $td }}">🟡</td></tr>
</table>
<p style="{{ $p }}"><strong>Avantajul tău nedrept:</strong> ERP cu date reale (specs furnizor, stoc, prețuri live) + generator AI descrieri → poți produce date la nivel de lider, automat, la scară.</p>

<h2 style="{{ $h2 }}">Următorul pas propus</h2>
<p style="{{ $p }}"><strong>Pilot pe Termoizolații / polistiren grafitat</strong> (categorie unde s-a pierdut): pachet complet stil-lider (curățare date + titluri + tabel specs + schema), apoi măsurat CTR și poziții în GSC la 2-3 săptămâni. Dacă merge → replicăm pe tot catalogul.</p>

<h2 style="{{ $h2 }}">De reținut pentru data viitoare</h2>
<ul style="padding-left:18px;margin:0 0 10px;">
  <li style="{{ $li }}">Am acces complet la <strong>Search Console</strong> (prin service account) — pot trage orice date.</li>
  <li style="{{ $li }}">Pentru <strong>GA4</strong> trebuie activat 1-click „Google Analytics Data API" în proiectul GCP (link în documentul complet), apoi trag și datele de analytics.</li>
</ul>

<p style="{{ $p }}">— Asistent ERP Malinco</p>
@endsection
