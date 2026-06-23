# Runbook — Backup & Restaurare ERP Malinco

## Sistemul de backup (existent)
- **`scripts/backup-db.sh`** — dump complet MySQL (`erp_malinco`), gzip, **zilnic la 02:00** (cron).
  - Local: `/mnt/backups/db/erp_malinco_<data>.sql.gz` (~200 MB), retenție **30 zile**.
  - Offsite: copiat prin `scp` la `root@malinco.ro:/var/backups/remote-erp/`, retenție **14 zile**.
- **Arhivare binlog** — `/etc/cron.d/binlog-archive` (zilnic 04:15) comprimă binlog-urile MySQL în `/mnt/backups/binlog-archive` → permite **recuperare la moment (point-in-time)**.

## Obiective
- **RPO (cât date putem pierde):** maximum ~24h prin dump-ul zilnic; **sub o oră** folosind binlog-urile pentru point-in-time.
- **RTO (cât durează restaurarea):** câteva minute pentru baza completă (vezi testul de verificare).

## Restaurare — procedură

### 1. Test de verificare (SIGUR — nu atinge producția)
Restaurează cel mai recent backup într-o bază separată `erp_malinco_restore_check`:
```bash
cd /var/www/erp
bash scripts/restore-db.sh --latest
```
Sau un backup anume:
```bash
bash scripts/restore-db.sh /mnt/backups/db/erp_malinco_2026-06-21_02-00.sql.gz
```
La final compară numărul de tabele cu producția. Pentru curățare:
```bash
sudo mysql -e "DROP DATABASE \`erp_malinco_restore_check\`;"
```
> Recomandare: rulează acest test **lunar**, ca să confirmi că backup-urile sunt valide.

### 2. Restaurare în PRODUCȚIE (dezastru) — cu confirmare dublă
```bash
bash scripts/restore-db.sh --latest erp_malinco
# scrie exact: RESTORE PRODUCTION
```
⚠ Suprascrie datele curente. Folosește doar la pierdere/coruptie reală a bazei.

### 3. Recuperare la moment (point-in-time)
1. Restaurează ultimul dump dinaintea momentului dorit (pasul 2).
2. Aplică binlog-urile din `/mnt/backups/binlog-archive` până la poziția/ora dorită:
```bash
zcat /mnt/backups/binlog-archive/<binlog>.gz | sudo mysql erp_malinco   # cu --stop-datetime pentru oprire la moment
```

## După orice restaurare în producție
- `php artisan config:clear && php artisan cache:clear`
- `sudo supervisorctl restart laravel-horizon`
- Verifică login + un raport în `/admin`.

## Note
- Scriptul folosește `sudo mysql` (root prin socket); rulează-l de pe serverul ERP.
- `erp_user` are privilegii doar pe `erp_malinco` (nu poate crea baza de verificare) — de aceea scriptul folosește root.
