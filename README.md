# Temelia ERP

ERP pentru distribuție de materiale de construcții — gestiune produse, stocuri,
comenzi, achiziții, ofertare și integrări cu WooCommerce, WinMentor și curierat
(Sameday). Aplicație internă, rulată în producție.

## Stack

- **PHP 8.3** · **Laravel 13** · **Filament 5** (două panel-uri: `Admin` la `/admin`, `App` la `/`) · **Livewire 4**
- **MySQL** · **Redis** (cache + cozi) · **Laravel Horizon** (supervisor)
- **Vite 8** + **Tailwind 4** pentru assets; design system custom în `public/css/erp-design.css`
- **node-renderer/**: Puppeteer + Chrome headless — randare imagini social media (JPEG 1080×1080)
- **mentorapi/**: bridge Go (Windows, COM) pentru WinMentor — 162 endpoint-uri REST
- **wordpress-plugin/**: plugin bridge cu endpoint-uri REST custom pe site-ul WooCommerce

## Module principale

- **Catalog & stocuri**: sincronizare produse/categorii/stocuri cu WooCommerce, push prețuri, metrici zilnice
- **Comenzi**: comenzi online sincronizate local, editare din ERP (status, produse, transport), AWB curier, import în WinMentor
- **Achiziții**: necesare (PNR) → comenzi furnizor (PO) cu flux de aprobare, dashboard buyer, recomandări pe vânzări reale
- **Ofertare**: oferte cu TVA, PDF branded, politici de discount pe rol × categorie/furnizor
- **Email + AI**: import IMAP, procesare Claude (tipuri, prețuri furnizori, entități), asociere furnizori
- **BI**: dashboard KPI, rapoarte periodice, clasificare ABC, sezonalitate
- **WinMentor**: vânzări/cumpărări/livrări/solduri/comenzi clienți, fișa clientului 360°, importuri structurate
- **PWA mobil** (`/app`): hub, dispecerizare vânzări, de predat, recepție (`/wh`), inventar (`/inv`)
- **Social media**: generator grafic (pipeline AI copy + imagine + randare)

## Access control

Matrice de permisiuni pe rol în DB (`role_permissions`), administrată din
*Setări → Permisiuni roluri*; default-deny (doar `super_admin` trece implicit).
Rutele non-Filament folosesc middleware-ul `perm:<cheie>`. Scope per locație
prin `EnforcesLocationScope`.

## Dezvoltare

```bash
composer dev        # server + queue + logs + vite (local)
composer test       # suita de teste
npm run build       # build assets producție
php artisan horizon # cozi (în producție rulează prin supervisor)
```

## Documentație

- `docs/PROJECT_CONTEXT.md` — arhitectură, module, fluxuri (onboarding rapid)
- `docs/MAIN_ANALYSIS_NOTES.md` — hotspot-uri de fiabilitate
- `mentorapi/README.md` — bridge-ul Go WinMentor
- Manual + documentație tehnică completă: `storage/app/documente/` (privat, servit la `/documente` doar pentru admin)
