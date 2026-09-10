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
                    ELSE NULL
                END,
                lei_cu_tva = CASE
                    WHEN den_articol IS NULL OR den_articol = '' THEN 0
                    WHEN den_articol REGEXP 'AVANS|PARCEL' THEN 0
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

        // Pas 2: refacturări de avize — F pozitive pe perechi (partener, sku)
        // unde avizele anului acoperă cel puțin PRAG din cantitatea facturată
        DB::update("
            UPDATE winmentor_vanzari_raw v
            JOIN (
                SELECT part_id, sku
                FROM winmentor_vanzari_raw
                WHERE firma = ? AND an = ? AND den_articol IS NOT NULL AND den_articol != ''
                GROUP BY part_id, sku
                HAVING SUM(CASE WHEN tip_document = 'AE' AND cantitate > 0 THEN cantitate ELSE 0 END) > 0
                   AND SUM(CASE WHEN tip_document = 'F'  AND cantitate > 0 THEN cantitate ELSE 0 END) > 0
                   AND SUM(CASE WHEN tip_document = 'AE' AND cantitate > 0 THEN cantitate ELSE 0 END)
                    >= ? * SUM(CASE WHEN tip_document = 'F' AND cantitate > 0 THEN cantitate ELSE 0 END)
            ) c ON c.part_id = v.part_id AND c.sku = v.sku
            SET v.motiv_exclus = 'refacturare_aviz', v.lei_cu_tva = 0
            WHERE v.firma = ? AND v.an = ? AND v.tip_document = 'F'
              AND v.cantitate > 0 AND v.motiv_exclus IS NULL
        ", [$firma, $an, self::PRAG_ACOPERIRE, $firma, $an]);

        return $afectate;
    }
}
