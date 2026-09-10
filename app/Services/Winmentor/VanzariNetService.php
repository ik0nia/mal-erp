<?php

namespace App\Services\Winmentor;

use Illuminate\Support\Facades\DB;

/**
 * Calculează `lei_cu_tva` + `motiv_exclus` pe winmentor_vanzari_raw.
 *
 * Exportul WinMentor listează TOATE documentele de ieșire, nu doar vânzările
 * nete — verificat 2026-09-10 contra cifrei de afaceri din bilanț:
 *
 *  - «refacturare_aviz»: marfa livrată pe aviz (AE) apare A DOUA OARĂ pe
 *    factura care o refacturează (F) — ex. 58.800 cărămizi = 899K lei dublate
 *    pe un singur client în 2025. Regula: dacă pe (an, partener, sku) avizele
 *    acoperă ≥80% din cantitatea facturată, liniile F pozitive sunt refacturări.
 *  - «avans»: facturile de avans (+3M/an) se stornează la facturarea finală —
 *    net ~0 pe an, dar distorsionează grav zilele/lunile (linii de 300K+).
 *    Se exclud ambele sensuri (avansul și stornoul lui).
 *  - «teren»: vânzările de imobilizări (PARCELE DE TEREN, ~1M în 2025) nu
 *    sunt vânzări operaționale (în bilanț sunt la alte venituri, nu în CA).
 *  - «fara_articol»: linii centralizatoare fără articol (bonuri «INTERNE»
 *    care dublează bonurile individuale de emulare).
 *  - «achitare_factura»: bonurile «ACHITAT FACT.EMISE» (casa 4, din nov 2025)
 *    sunt PLĂȚI pentru facturi deja emise, nu vânzări — marfa a fost numărată
 *    pe aviz/factură; fără excludere 2026 părea +13% vs 2025 (2,2M dublați).
 *
 * Sumele sunt CU TVA (decizia utilizatorului): avizele/facturile au prețul
 * FĂRĂ TVA (verificat: c*p*(1+cota) = valoare_factura per document) → se
 * adaugă cota liniei (fallback 19%, respectiv 21% de la 1 aug 2025);
 * bonurile de casă (S) au prețul deja CU TVA (c*p = valoarea plătită la
 * casă, verificat pe bonuri cu o singură linie) → rămân ca atare.
 *
 * ATENȚIE: chiar și după curățare, totalul rămâne peste facturarea reală
 * (~+20-25%) — exportul NU conține toate facturile de decontare a avizelor
 * (dovedit: factura 70349/23.07.2025 există în solduri, 0 linii în export),
 * iar avizele ies la prețuri peste cele negociate la decontare. Pentru
 * tendințe și comparații interne regulile sunt însă consistente pe toți anii.
 */
class VanzariNetService
{
    /** Prag: avizele trebuie să acopere ≥80% din cantitatea F ca să considerăm refacturare. */
    private const PRAG_ACOPERIRE = 0.8;

    public function recomputeAn(int $an, string $firma = 'MAL2019'): int
    {
        // Pas 1: reguli per linie (articol, tip document, TVA per linie)
        $afectate = DB::update("
            UPDATE winmentor_vanzari_raw
            SET motiv_exclus = CASE
                    WHEN den_articol IS NULL OR den_articol = '' THEN 'fara_articol'
                    WHEN den_articol REGEXP 'AVANS' THEN 'avans'
                    WHEN den_articol REGEXP 'PARCEL' THEN 'teren'
                    WHEN den_articol REGEXP 'ACHITAT' THEN 'achitare_factura'
                    ELSE NULL
                END,
                lei_cu_tva = CASE
                    WHEN den_articol IS NULL OR den_articol = '' THEN 0
                    WHEN den_articol REGEXP 'AVANS|PARCEL|ACHITAT' THEN 0
                    WHEN tip_document = 'S' THEN ROUND(cantitate * pret, 2)
                    ELSE ROUND(cantitate * pret * (1 +
                        CASE WHEN cota_tva REGEXP '^[0-9]+(\\\\.[0-9]+)?$'
                             THEN CAST(cota_tva AS DECIMAL(5,2))
                             WHEN (an * 100 + luna) >= 202508 THEN 21
                             ELSE 19
                        END / 100), 2)
                END
            WHERE firma = ? AND an = ?
        ", [$firma, $an]);

        // Pas 2: refacturări de avize — F pozitive pe perechi (partener, sku) unde
        // avizele anului acoperă cel puțin PRAG din cantitatea facturată ȘI bilanțul
        // cantitativ al SKU-ului e rupt (ieșirile cumulate depășesc intrările cumulate
        // — dovada dublei descărcări aviz+factură). Exportul conține în principiu doar
        // documente care mișcă stocul (facturile de decontare a avizelor NU apar), deci
        // o factură cu marfă e implicit vânzare reală — o excludem numai când fizic
        // n-a existat marfă și pentru ea și pentru avize (constatat pe cărămida Cemacon:
        // ieșiri 198K buc vs intrări 79K în 2025; paleții, cu bilanț închis, rămân numărați).
        DB::update("
            UPDATE winmentor_vanzari_raw v
            JOIN (
                SELECT part_id, sku,
                    SUM(CASE WHEN tip_document = 'F' AND cantitate > 0 THEN cantitate ELSE 0 END) f_qty
                FROM winmentor_vanzari_raw
                WHERE firma = ? AND an = ? AND den_articol IS NOT NULL AND den_articol != ''
                GROUP BY part_id, sku
                HAVING SUM(CASE WHEN tip_document = 'AE' AND cantitate > 0 THEN cantitate ELSE 0 END) > 0
                   AND SUM(CASE WHEN tip_document = 'F'  AND cantitate > 0 THEN cantitate ELSE 0 END) > 0
                   AND SUM(CASE WHEN tip_document = 'AE' AND cantitate > 0 THEN cantitate ELSE 0 END)
                    >= ? * SUM(CASE WHEN tip_document = 'F' AND cantitate > 0 THEN cantitate ELSE 0 END)
            ) c ON c.part_id = v.part_id AND c.sku = v.sku
            JOIN (
                SELECT o.sku, o.out_cum - COALESCE(i.in_cum, 0) exces
                FROM (
                    SELECT sku, SUM(cantitate) out_cum
                    FROM winmentor_vanzari_raw
                    WHERE firma = ? AND an <= ?
                      AND den_articol IS NOT NULL AND den_articol != ''
                    GROUP BY sku
                ) o
                LEFT JOIN (
                    SELECT sku, SUM(cantitate) in_cum
                    FROM winmentor_intrari_raw
                    WHERE firma = ? AND YEAR(data_intrare) <= ?
                    GROUP BY sku
                ) i ON i.sku = o.sku
            ) b ON b.sku = v.sku AND b.exces >= 0.5 * c.f_qty
            SET v.motiv_exclus = 'refacturare_aviz', v.lei_cu_tva = 0
            WHERE v.firma = ? AND v.an = ? AND v.tip_document = 'F'
              AND v.cantitate > 0 AND v.motiv_exclus IS NULL
        ", [$firma, $an, self::PRAG_ACOPERIRE, $firma, $an, $firma, $an, $firma, $an]);

        return $afectate;
    }
}
