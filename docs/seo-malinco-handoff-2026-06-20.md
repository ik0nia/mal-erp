# Malinco.ro — Raport SEO & AEO + Plan (handoff)
**Data:** 2026-06-20 · Pentru continuare în sesiune viitoare

---

## 0. Accese (verificate)
- **Google Search Console**: ✅ funcțional. Service account `malinco-erp@malinco-erp.iam.gserviceaccount.com` e **siteOwner pe `sc-domain:malinco.ro`**. Cheie: `/var/www/erp/storage/app/google-sa.json`. Apel REST via JWT (scope `webmasters.readonly`).
- **Google Analytics 4**: acces la proprietate (cont „Malinco", property `401168783`, drept editare) DAR **Data API e DEZACTIVAT** în proiectul GCP `235098963098`. De activat 1-click: https://console.developers.google.com/apis/api/analyticsdata.googleapis.com/overview?project=235098963098 (Admin API merge deja).
- **burst-statistics** (analytics self-hosted): ✅ în DB WP (~400 pageviews/zi).
- **SSH site**: `ssh -i ~/.ssh/id_ed25519 root@malinco.ro` (WordPress 7.0, WooCommerce 10.7, temă custom `malinco` pe Woodmart).

## 1. Diagnostic — de ce au scăzut pozițiile
- **NU e penalizare.** Poziție medie stabilă ~11 tot anul; mai 2026 = lună record (3068 clickuri).
- Declin gradual ~15-20% clickuri din vârful de început-mai, coincide cu **May 2026 Core Update** (21 mai–2 iun, foarte volatil). Plus March 2026 Core Update.
- Pierderea reală e pe **pagini specifice** (mascată de medie): polistiren grafitat 10→22, plutitor wc 4.4→11, gips carton 13→17 — re-evaluate de core update (conținut/concurență), nu bug tehnic.
- Cererea pe brand a scăzut („malinco" −39 clk, poziție stabilă) — marketing, nu SEO.

## 2. Descoperirea cheie — rankezi dar NU primești clickuri (CTR catastrofal)
Pe interogări comerciale mari, ești pe poziții bune dar CTR ~0.1-0.5%:
- `prenandez`: poz **2.4**, 1888 impresii, **7 clickuri (0.4%)**
- `tinci alb interior`: poz 3.0, 1609 impr, CTR 0.5%
- `bca 5 cm`: poz 10, 1283 impr, 3 clickuri
- `ciment 20 kg`: 1189 impr, 1 click

Cauza: SERP-ul 2026 e dominat de **Google Shopping + „Popular products" + AI Overviews**, care înghit clickurile deasupra organicului. **Jocul nu mai e „mai multe poziții", ci „cucerirea SERP-ului".**

## 3. Inspirație de la lideri (aceleași produse unde pierzi)
**Fier beton:** Leroy `Fier beton B500C, Ø 10 mm, L 6000 mm` · Hornbach `Oțel beton striat B500 Ø 10 mm 6 m` · **Tu:** `Fier Beton 10 Bara 6 M – Malinco.ro`
**Polistiren grafitat:** Dedeman `Polistiren expandat pentru fațadă, Baudeman EPS 80 grafitat, 15 cm` · e-izolatii `... Austrotherm AF 80 Plus, gros. 15 cm, 1.5 mp/pac`

Tiparul lor: precizie tehnică (clasă B500C, simbol Ø, unități corecte) · brand vizibil · sinonime (oțel beton = fier beton) · unitate ambalare (mp/pac) · aplicație (pentru fațadă). Liderii mari NU pun „preț mic" în titlu — doar magazinele mici.

**Rădăcina problemei = CALITATEA DATELOR de produs** (nume gen „Fier Beton 10 Bara 6 M", fără clasă/brand/unități/ambalare). De acolo iese tot: titluri, schema, snippet, conținut ȘI răspunsuri AI/LLM.

## 4. Ce e DEJA corect tehnic (verificat)
- ✅ Schema Product completă pe `/p/` (Product+Offer+Brand+AggregateRating+MerchantReturnPolicy+OfferShippingDetails+BreadcrumbList) — `themes/malinco/woocommerce/single-product.php`.
- ✅ llms.txt dinamic, bun (overview + categorii + secțiune Hunter cu specs). Minor: „15.000" vs meta „25.000".
- ✅ canonical + redirect http/www→https corecte; Organization/WebSite schema pe home.
- ✅ Performanță bună (TTFB 22-82ms).

## 5. Ce s-a FĂCUT în sesiunea asta
- ✅ **robots.txt reparat** (backup `robots.txt.bak-20260620`): WooCommerce folosește `min_price`/`max_price` (nu `price_min`), iar `shop_view`/`per_row`/`&orderby` nu erau blocate → 51% din crawl Google era irosit pe filtre. Acum blocate oriunde + allow GPTBot/ClaudeBot/Google-Extended/PerplexityBot.

## 6. Plan prioritizat — „alte soluții de îmbunătățit" (ce fac liderii, noi nu)
| # | Soluție | Impact |
|---|---------|--------|
| 1 | **Curățare + îmbogățire date produs** (clasă, Ø, brand, ambalare, sinonime) — alimentează tot | 🔴 maxim |
| 2 | **Titluri** tehnice curate (`Nume curat | Categorie – Malinco`), fără preț/stoc în titlu (ăla în schema/meta, live) | 🔴 CTR |
| 3 | **Recenzii → stele** în SERP (acum **0 din 17.440** produse) | 🔴 CTR |
| 4 | **Tabel caracteristici tehnice** pe produs | 🔴 Google + LLM |
| 5 | **Grupare variante** (1 pagină cu selector grosime, nu 17k pagini subțiri) | 🔴 anti-thin |
| 6 | **Google Shopping / free listings** (google-listings-and-ads activ — de auditat feed-ul) | 🔴 |
| 7 | **Ghiduri & calculatoare** („cât polistiren/ciment îmi trebuie") | 🟠 trafic + citări LLM + linkuri |
| 8 | **Pagini brand puternice** (Austrotherm, Baumit, Knauf...) — model: Hunter | 🟠 |
| 9 | **Conținut editorial categorii** + FAQ/HowTo schema | 🟠 |
| 10 | **Redirect 301** cele 14 produse care dau 404 (71 clk/90 zile) | 🟡 |

**Avantaj nedrept Malinco:** ERP cu date reale (specs furnizor, stoc, prețuri live) + generator AI descrieri (`ikonia-productdesc-ai-generator`) → poate produce date la nivel de lider, automat, la scară.

## 7. Decizii în așteptare / următorul pas
- **Format titlu** — recomandare: `Nume curat | Categorie – Malinco` (fără preț/stoc); categorii ca modelul Hunter. Utilizatorul încă decide; de validat prin pilot măsurat în GSC.
- **PILOT propus:** categoria **Termoizolații / polistiren grafitat** (unde s-a pierdut) — pachet complet stil-lider: curățare date + titluri + tabel specs + schema, apoi măsurat CTR/poziții în GSC la 2-3 săptămâni → dacă merge, replicat pe tot catalogul cu ERP+AI.

## 8. Memorii relevante (în `~/.claude/.../memory/`)
- `project_malinco_seo_diagnostic.md`, `reference_google_search_console_access.md`
