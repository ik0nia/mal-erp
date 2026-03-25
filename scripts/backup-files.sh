#!/bin/bash
BACKUP_DIR="/mnt/backups/files"
DATE=$(date +%Y-%m-%d_%H-%M)
FILE="${BACKUP_DIR}/storage_app_${DATE}.tar.gz"
LOG="/var/www/erp/storage/logs/backup.log"

mkdir -p "$BACKUP_DIR"

tar -czf "$FILE" -C /var/www/erp/storage/app . 2>/dev/null

echo "[$(date)] Files backup OK: $FILE ($(du -sh $FILE | cut -f1))" >> "$LOG"

# Păstrează doar ultimele 14 zile
find "$BACKUP_DIR" -name "*.tar.gz" -mtime +14 -delete
