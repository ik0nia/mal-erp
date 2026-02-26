<?php

/**
 * Glosar de abrevieri folosite în denumirile de produse WinMentor.
 *
 * prompt_context  — injectat în prompt-urile Claude (descrieri, categorisire)
 * title_expansions — înlocuiri aplicate direct în titlul produsului (cu regex)
 * do_not_change   — token-uri care NU se modifică niciodată în titlu
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Context pentru prompt-uri Claude
    | Folosit în descrieri, categorisire și orice alt prompt AI.
    |--------------------------------------------------------------------------
    */
    'prompt_context' => <<<'GLOSSARY'
## Glosar abrevieri produse (specific acestui magazin)

### Materiale / tipuri de instalații
- CU = Cupru (ex: "CUPLAJ CU 20" = cuplaj din cupru 20mm)
- PPR = Polipropilenă reticulată (tip de țeavă pentru instalații termice)
- PEX = Pexal (țeavă multistrat pexal, folosită la instalații de apă caldă/rece)
- AL = Aluminiu (ex: "PROFIL AL" = profil din aluminiu)
- INOX = Inox / Oțel inoxidabil
- PVC = Policlorură de vinil

### Fitinguri și instalații sanitare
- TEU = Teu (fitting tip T — se lasă ca atare)
- COT = Cot (fitting curbat 90° — se lasă ca atare)
- MUFA = Mufă (manșon de îmbinare)
- NIPLU = Niplu (piesă de legătură filetată)
- REDUS / REDUCTIE = Reducție (trecere de la un diametru la altul)
- OLANDEZ = Racord olandez (uniune filetată demontabilă)
- INT = Interior (filet interior)
- EXT = Exterior (filet exterior)
- FI = Φ diametru (ex: "FI 120MM" = diametru 120mm)

### Instalații electrice / aparataje
- SIG = Siguranță / Întrerupător automat (ex: "SIG MOELLER C10" = siguranță Moeller 10A)
- PR = Prize (ex: "TRIPLU CU 3 PR" = triplu cu 3 prize)
- PRE. = Prelungitor (ex: "PRE. 20M 3X1,5" = prelungitor 20m cablu 3x1,5mm²)
- PT = pe tencuială (montaj aparent) — DOAR în context de aparataje electrice
- PT = pentru — în orice alt context (ex: "BISON PT PVC" = Bison pentru PVC)
- DOZA LEG. = Doză de legătură electrică (junction box)
- LEG = Legătură / Doză de legătură

### Scule și consumabile
- HSS = High Speed Steel (burghie din oțel rapid)
- HSS-CO = High Speed Steel Cobalt (burghie din oțel rapid cu cobalt — se lasă exact așa)
- SDS = SDS Plus (tip de coadă pentru ciocane rotopercutante)
- SCAI = Disc abraziv cu velcro (hook & loop)
- RAL = Cod culoare RAL (standard european pentru culori)
- FI = diametru Φ (pentru burghie, burlane etc.)

### Branduri care NU se modifică în titlu
- MAXCL = MaxCL (brand panouri HPL decorative)
- MRC, MRT = coduri de model/culoare MaxCL (se lasă neschimbate)
- BISON = brand adezivi și sigilanti
- KNAUF = brand materiale de construcții
- V-TAC = brand becuri LED
- PROX = brand scule (XP.PROX = linie de produs Prox)
- MOELLER = brand echipamente electrice
- FISCHER = brand sisteme de fixare (DUOPOWER = linie de dibluri Fischer)
- FISCHER DUOPOWER K NV = cod intern Fischer pentru diblu nylon cu cap (se lasă neschimbat)
- RAIN BIRD = brand sisteme irigații
- STARKE = brand becuri și produse electrice
- ADEPLAST = brand materiale de construcții
- AUSTROTHERM = brand polistiren
- NORTON = brand abrazive

### Coduri tehnice (se lasă neschimbate)
- NV = cod intern producător (ex: la dibluri Fischer)
- FPB = cod serie (biți profi)
- FP = cod tip disc diamantat
- CN / FN = coduri de finisaj la profile metalice
- XP = cod linie de produs (la scule Prox)
GLOSSARY,

    /*
    |--------------------------------------------------------------------------
    | Expansiuni aplicate în titluri
    | Format: ['pattern_regex' => 'înlocuire', 'context' => 'toate|fitinguri|electric']
    |--------------------------------------------------------------------------
    */
    'title_expansions' => [
        // CU = Cupru — doar în context de fitinguri/țevi (nu în HSS-CO, RAL etc.)
        [
            'pattern'     => '/\b(CUPLAJ|MUFA|NIPLU|TEU|COT|REDUCTIE|TEAVA|OLANDEZ|CAPAC|RACORD)\s+CU\b/i',
            'replacement' => '$1 Cupru',
        ],
        [
            'pattern'     => '/\bCU\s+(OLANDEZ|TEU|COT|MUFA|NIPLU)\b/i',
            'replacement' => 'Cupru $1',
        ],
        // PEX = Pexal
        [
            'pattern'     => '/\bPEX\b/',
            'replacement' => 'Pexal',
        ],
        // PR = Prize (doar la prelungitoare/stechere)
        [
            'pattern'     => '/\b(\d+)\s*PR\b(?=\s|$)/',
            'replacement' => '$1 Prize',
        ],
        // PRE. = Prelungitor
        [
            'pattern'     => '/\bPRE\.\s*/i',
            'replacement' => 'Prelungitor ',
        ],
        // SIG = Siguranță (doar înaintea unui brand cunoscut)
        [
            'pattern'     => '/\bSIG\s+(MOELLER|ABB|HAGER|GEWISS|ETI)\b/i',
            'replacement' => 'Siguranță $1',
        ],
        // DOZA LEG. = Doză legătură electrică
        [
            'pattern'     => '/\bDOZA\s+LEG\.\s*/i',
            'replacement' => 'Doză legătură electrică ',
        ],
        // INT/EXT în fitinguri = Interior/Exterior
        [
            'pattern'     => '/\bINT-EXT\b/i',
            'replacement' => 'Interior-Exterior',
        ],
        [
            'pattern'     => '/\bEXT-INT\b/i',
            'replacement' => 'Exterior-Interior',
        ],
        [
            'pattern'     => '/\bINT-INT\b/i',
            'replacement' => 'Interior-Interior',
        ],
        [
            'pattern'     => '/\bEXT-EXT\b/i',
            'replacement' => 'Exterior-Exterior',
        ],
        // INT / EXT standalone în context fitinguri (precedate sau urmate de dimensiuni/tip)
        [
            'pattern'     => '/\b(MUFA|NIPLU|REDUCTIE|OLANDEZ|RACORD|TEU|COT|CUPLAJ)\s+(\d[^\s]*)\s+INT\b/i',
            'replacement' => '$1 $2 Interior',
        ],
        [
            'pattern'     => '/\b(MUFA|NIPLU|REDUCTIE|OLANDEZ|RACORD|TEU|COT|CUPLAJ)\s+(\d[^\s]*)\s+EXT\b/i',
            'replacement' => '$1 $2 Exterior',
        ],
        // PT în context pur non-electric = pentru (abreviere comună)
        // (PT electric se lasă pentru prompt-uri, nu se schimbă în titlu)
    ],

    /*
    |--------------------------------------------------------------------------
    | Token-uri care NU se modifică niciodată în titlu
    |--------------------------------------------------------------------------
    */
    'do_not_change' => [
        'MAXCL', 'MRC', 'MRT', 'HSS', 'HSS-CO', 'SDS', 'TEU', 'COT',
        'FPB', 'FP', 'XP', 'NV', 'CN', 'FN', 'RAL', 'PPR', 'PVC',
        'OSB', 'BCA', 'LED', 'UV', 'INOX', 'AL', 'BISON', 'KNAUF',
        'FISCHER', 'DUOPOWER', 'PROX', 'NORTON', 'ADEPLAST', 'STARKE',
        'AUSTROTHERM', 'V-TAC', 'RAIN', 'BIRD', 'MOELLER',
    ],

];
