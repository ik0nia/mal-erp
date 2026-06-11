#!/bin/bash
BACKUP_DIR="/mnt/backups/db"
REMOTE_DIR="/var/backups/remote-erp"
DATE=$(date +%Y-%m-%d_%H-%M)
FILE="${BACKUP_DIR}/erp_malinco_${DATE}.sql.gz"
LOG="/var/www/erp/storage/logs/backup.log"

mkdir -p "$BACKUP_DIR"
DB_PASS=$(grep -E '^DB_PASSWORD=' /var/www/erp/.env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
mysqldump -h 127.0.0.1 -u erp_user -p"$DB_PASS" erp_malinco --single-transaction --quick --lock-tables=false --no-tablespaces | gzip > "$FILE"
SIZE=$(du -sh "$FILE" | cut -f1)
echo "[$(date)] DB backup OK: $FILE ($SIZE)" >> "$LOG"

# Cross-server copy to malinco.ro
scp -i /home/erp/.ssh/id_ed25519 -o ConnectTimeout=30 "$FILE" root@malinco.ro:"$REMOTE_DIR/" 2>/dev/null \
    && echo "[$(date)] DB backup copied to malinco.ro" >> "$LOG" \
    || echo "[$(date)] WARN: failed to copy DB backup to malinco.ro" >> "$LOG"

# Cleanup: keep 30 days local, 14 days remote
find "$BACKUP_DIR" -name "*.sql.gz" -mtime +30 -delete
ssh -i /home/erp/.ssh/id_ed25519 -o ConnectTimeout=10 root@malinco.ro "find $REMOTE_DIR -name '*.sql.gz' -mtime +14 -delete" 2>/dev/null
