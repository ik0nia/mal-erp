#!/bin/bash
BACKUP_DIR="/mnt/backups/files"
REMOTE_DIR="/var/backups/remote-erp"
DATE=$(date +%Y-%m-%d_%H-%M)
FILE="${BACKUP_DIR}/storage_app_${DATE}.tar.gz"
LOG="/var/www/erp/storage/logs/backup.log"

mkdir -p "$BACKUP_DIR"

# Backup storage + .env
tar -czf "$FILE" -C /var/www/erp storage/app .env 2>/dev/null
SIZE=$(du -sh "$FILE" | cut -f1)
echo "[$(date)] Files backup OK: $FILE ($SIZE)" >> "$LOG"

# Cross-server copy to malinco.ro
scp -i /home/erp/.ssh/id_ed25519 -o ConnectTimeout=30 "$FILE" root@malinco.ro:"$REMOTE_DIR/" 2>/dev/null \
    && echo "[$(date)] Files backup copied to malinco.ro" >> "$LOG" \
    || echo "[$(date)] WARN: failed to copy files backup to malinco.ro" >> "$LOG"

# Cleanup: keep 14 days local, 7 days remote
find "$BACKUP_DIR" -name "*.tar.gz" -mtime +14 -delete
ssh -i /home/erp/.ssh/id_ed25519 -o ConnectTimeout=10 root@malinco.ro "find $REMOTE_DIR -name '*.tar.gz' -mtime +7 -delete" 2>/dev/null
