# Incident MentorAPI / WinMentor — 2026-09-15

_Raport complet. Timestamp-urile sunt în UTC (câmpul `timestamp` din bridge); ora locală server = UTC+3._

---

## Rezumat (o frază)
În timpul testării live a endpoint-ului nou `SetPartAnalizat`, apelul a aruncat o excepție internă în DocImpServer și a lăsat **sesiunea WinMentor blocată** — de atunci `SetNumeFirma` (selectarea firmei) eșuează la orice apel, blocând integrarea ERP ↔ WinMentor până la repornirea motorului WinMentor pe serverul Windows.

## Impact
- **Sync ERP ↔ WinMentor: oprit** (toate job-urile care selectează firma eșuează). Nedistructiv — nu aduce date noi, se reia singur după deblocare.
- **Date: neafectate.** Apelurile implicate au fost de citire/context, fără scriere/import. Nicio factură/stoc/sold modificat.
- **Utilizatorii care lucrează direct în WinMentor:** cel mai probabil neafectați (blocat e canalul de automatizare COM), dar neconfirmat de la distanță.
- **582** de erori `SetNumeFirma` logate în ziua incidentului (majoritatea = job-uri de sync care lovesc repetat bridge-ul blocat).

## Cauză rădăcină
`SetPartAnalizat` (funcție WinMentor **nedocumentată oficial**, adăugată ca endpoint în v1.5.0) încarcă un partener în „modul analiză" — o operație care **mută starea globală a motorului WinMentor**. Apelată fără secvența/contextul intern corect, a aruncat excepție și a lăsat motorul într-o stare din care **nu mai poate deschide nicio firmă** (`SetNumeFirma` → excepție). Starea trăiește în procesul WinMentor, nu în `mentorapi.exe`.

## NU a fost din versiunea API (dovedit)
- Cu **v1.5.0** → `SetNumeFirma` stricat.
- Cu **v1.4.0** (build vechi redeployat) → `SetNumeFirma` **stricat identic**.
- Ambele versiuni eșuează la fel → cauza e starea WinMentor, nu codul API. Modificările v1.5.0 au fost pur aditive (6 endpoint-uri noi), fără atingerea căii `SetNumeFirma`/conectare COM.

---

## Cronologie (din `storage/logs/winmentor/bridge/bridge-2026-09-15.log`)

| Ora (UTC) | Eveniment |
|---|---|
| ~18:49 | Deploy v1.5.0 confirmat live (`/api/health` → version 1.5.0) |
| 18:55:00 | Test `GetValoriAtribut` → *server threw an exception* (endpoint nou, runtime-broken) |
| 18:55:27 | `GetValoriAtribut` (coduri 2,3,5,10,100) → toate excepție; `GetInfoBonConsum` → *error code 1* (execută, nr. bon invalid) |
| 18:55 | `GetListaFirmeExt` → **OK** (a întors lista firmelor); `ExistaMonetarul` → **OK** |
| 18:56:03 | `GetNomenclatorArticole` → *error code 1* |
| 18:57:18 | `GetSoldFactNeop` → *error code 1* (baseline, motor încă funcțional) |
| **18:57:18** | **`SetPartAnalizat` → *server threw an exception*** ← DECLANȘATORUL |
| 18:57:18 | `GetSoldFactNeop` → *error code 1* (încă) |
| **18:57:18** | **`SetNumeFirma` → *server threw an exception*** ← prima cădere, imediat după |
| 18:57:47 | `SetNumeFirma` × retry (test recuperare) → eșec |
| 18:58:06 | `SetNumeFirma` după `comDisconnect`+`comConnect` → eșec |
| 19:00–19:01 | `SetNumeFirma` după `com/reset` → eșec |
| 19:15:43–44 | Rafală de ~30 eșecuri `SetNumeFirma` (job de sync în buclă lovește bridge-ul blocat) |
| 19:31, 19:39 | Eșecuri continue (job-uri sync + verificări) |

Eroarea detaliată a motorului blocat (vizibilă via `/api/com/call`): `005;;Nu ai selectat anul si luna de lucru` — returnată **identic la ORICE metodă** (`GetListaFirme`, `SetNumeFirma`, `SetLunaLucru`), deși `GetListaFirme` nici nu are nevoie de lună → confirmă că e eroare-șablon a sesiunii înțepenite, nu o problemă reală de context.

---

## Ce s-a încercat pentru recuperare (și a eșuat)
1. `com/disconnect` + `com/connect` (recreează obiectul COM în proces) → fără efect.
2. `com/reset` → fără efect.
3. Repornirea serviciului `mentorapi.exe` de **2 ori** → fără efect (starea e în WinMentor, nu în procesul nostru).
4. Secvență `SetLunaLucru` → `SetNumeFirma` (ipoteza „lipsește luna") → `SetLunaLucru` însuși aruncă aceeași eroare → ipoteză infirmată.
5. `SetNumeFirma` pe firmă de **test** (TEST2023/TEST2026) → aceeași eroare → nu e lock specific pe firma de producție, e motorul global.

**Concluzie:** nu există apel COM care să repare. Nu există funcție de „anulare" a contextului analizat în cele 171 de metode. Resetarea „normală" (`SetNumeFirma`) e exact ce e picat.

## Rezolvare necesară (pe serverul Windows 82.79.74.132)
1. Verifică ecranul pentru un **dialog de eroare WinMentor** deschis → închide-l.
2. Dacă nu → închide complet **`WinMentor.exe`** și repornește-l.
3. Dacă tot nu → **reboot** la mașină (curăță orice lock/sesiune/dialog).
4. Opțional după: repornește `mentorapi.exe` pentru o conexiune curată.
5. Verificare: `SetNumeFirma` trebuie să reușească; `/api/health` → `comConnected: true` + sync-ul se reia.

---

## Lecții / prevenție
- **`SetPartAnalizat` NU trebuie expus ca endpoint** — e funcție internă nedocumentată care mută starea globală și poate bloca producția la un apel. De scos din rute (rămâne accesibilă doar via `/api/com/call` pentru teste controlate). Idem `GetValoriAtribut` (runtime-broken).
- **Testele pe funcții „rupte"/setteri se fac DOAR** în fereastră nocturnă, cu sync ERP oprit, pe firmă de test — conform notei existente. Regula a fost încălcată aici.
- Bridge-ul e **stare globală partajată, în producție** — orice setter riscant afectează toți consumatorii.

## Ce e OK și rămâne bun (nelegat de incident)
- Documentația completă (171 metode) + HTML cu exemple: `documente/mentorapi-api-v1.5.0.html`, `documente/mentorapi-v1.5.0-documentatie.md`.
- 3 endpoint-uri noi funcționale: `GetListaFirmeExt`, `ExistaMonetarul`, `GetInfoBonConsum`.
- Semnăturile tuturor celor 171 metode, validate din TLB-ul oficial.

## Fișiere de referință
- Log dovezi: `storage/logs/winmentor/bridge/bridge-2026-09-15.log`
- Cod endpoint problematic: `mentorapi/api/completare.go` (`handleSetPartAnalizat`), rută în `mentorapi/api/router.go`
- Context funcții rupte: `mentorapi/FUNCTII_DE_REZOLVAT.md`
