#!/bin/bash
#
# restore-db.sh — restaurează un backup MySQL pentru VERIFICARE.
# IMPLICIT restaurează într-o bază SEPARATĂ de verificare (NU producția).
# Producția se atinge DOAR cu confirmare dublă explicită.
#
# Utilizare:
#   scripts/restore-db.sh <backup.sql.gz> [target_db]
#   scripts/restore-db.sh --latest            # restaurează cel mai recent backup în baza de verificare
#
set -euo pipefail

BACKUP_DIR="/mnt/backups/db"
PROD_DB="erp_malinco"
VERIFY_DB="erp_malinco_restore_check"

ARG1="${1:-}"
if [ -z "$ARG1" ]; then
    echo "Utilizare: $0 <backup.sql.gz> [target_db]"
    echo "          $0 --latest"
    echo
    echo "Backup-uri disponibile (cele mai recente):"
    ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | head -5 | sed 's/^/  /'
    exit 1
fi

if [ "$ARG1" = "--latest" ]; then
    BACKUP_FILE=$(ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | head -1)
    [ -z "$BACKUP_FILE" ] && { echo "EROARE: niciun backup în $BACKUP_DIR"; exit 1; }
else
    BACKUP_FILE="$ARG1"
fi
TARGET_DB="${2:-$VERIFY_DB}"

[ -f "$BACKUP_FILE" ] || { echo "EROARE: fișierul '$BACKUP_FILE' nu există."; exit 1; }

echo "Backup : $BACKUP_FILE"
echo "Țintă  : $TARGET_DB"

# --- Protecție producție ---
if [ "$TARGET_DB" = "$PROD_DB" ]; then
    echo
    echo "!!! ATENȚIE: ținta este BAZA DE PRODUCȚIE ($PROD_DB). Restaurarea SUPRASCRIE datele curente. !!!"
    read -r -p "Scrie exact 'RESTORE PRODUCTION' pentru a continua: " CONF
    [ "$CONF" = "RESTORE PRODUCTION" ] || { echo "Anulat — nimic modificat."; exit 1; }
fi

echo "[1/4] Verific integritatea arhivei (gunzip -t)..."
gunzip -t "$BACKUP_FILE" || { echo "EROARE: arhivă coruptă."; exit 1; }
echo "      OK — arhivă validă."

echo "[2/4] (Re)creez baza '$TARGET_DB'..."
sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`$TARGET_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "[3/4] Restaurez (poate dura câteva minute)..."
START=$(date +%s)
gunzip -c "$BACKUP_FILE" | sudo mysql "$TARGET_DB"
ELAPSED=$(( $(date +%s) - START ))

echo "[4/4] Verific rezultatul..."
TABLES=$(sudo mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$TARGET_DB';")
echo
echo "GATA în ${ELAPSED}s. Baza '$TARGET_DB' are $TABLES tabele restaurate."
if [ "$TARGET_DB" != "$PROD_DB" ]; then
    echo "Producția ($PROD_DB) NU a fost atinsă. Pentru a șterge baza de verificare:"
    echo "  sudo mysql -e \"DROP DATABASE \\\`$TARGET_DB\\\`;\""
fi
