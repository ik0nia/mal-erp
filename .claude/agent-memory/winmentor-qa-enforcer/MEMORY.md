# QA Enforcer Memory

## Status audit (2026-02-26) — CORECTAT
Toate erorile cunoscute au fost corectate direct in DB.

## Atribute - coloane corecte in `woo_product_attributes`
- Coloane: `id`, `woo_product_id`, `name`, `value` (NU attribute_name/attribute_value)
- Erorile recurente (Culoare:Rentabil, Dulie:Mica/Mare, Tensiune cu amperaj, Utilizare:module) au fost corectate in sesiunea curenta sau anterioara

## Categorii - corectii aplicate
- CLEMA COAMA: Burlane(177)->Accesorii acoperis(20) ✓
- CLEMA COAMA: Jgheaburi(142)->Accesorii acoperis(20) ✓
- 3x GLETIERA: Spacluri(87)->Gletiere(85) ✓
- Categorii utile: Gletiere=85, Vopsele si emailuri=135, Accesorii acoperis=20, Accesorii tigla beton=246

## Titluri - CORECTATE
- 779 titluri ALL CAPS reformatate via `php artisan products:reformat-titles`
- Comanda necesita credit API Anthropic activ

## Imagini - probleme ESCALATE (necesita interventie manuala)
- Tablou Ingropat 12/4/24 module -> toate partajeaza imaginea de 24 posturi (URL: ...tablou-sigurante-ingropat-alb-24-posturi-83204.jpg)
- Tablou Aparent 6/8/4/18 module -> toate partajeaza aceeasi imagine
- 402 produse fara `main_image_url` - candidatii approved din `product_image_candidates` sunt pt produse cu imagine deja
- Imagini duplicate pentru variante de produs (dimensiuni diferite) sunt ACCEPTABILE (discuri, burghie, polistiren)

## Regula titluri (adaugata de utilizator)
- Titlurile NU trebuie scrise complet cu majuscule (ALL CAPS = FAIL automat)
- Acronime si unitati (LED, PPR, E14, W, mm) pot ramane uppercase

## Fisiere relevante
- `/var/www/erp/app/Console/Commands/GenerateProductAttributesCommand.php`
- `/var/www/erp/app/Console/Commands/ReformatProductTitlesCommand.php`
- Tabele: `woo_product_attributes` (cols: id, woo_product_id, name, value), `woo_products`, `woo_product_category`, `woo_categories`
