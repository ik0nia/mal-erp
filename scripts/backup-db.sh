#!/bin/bash
BACKUP_DIR="/var/www/erp/storage/backups"
DATE=$(date +%Y-%m-%d_%H-%M)
FILE="${BACKUP_DIR}/erp_malinco_${DATE}.sql.gz"
mkdir -p "$BACKUP_DIR"
mysqldump -h 127.0.0.1 -u erp_user -pTypo1bmng!@! erp_malinco --single-transaction --quick --lock-tables=false --no-tablespaces | gzip > "$FILE"
echo "[$(date)] Backup OK: $FILE ($(du -sh $FILE | cut -f1))" >> /var/www/erp/storage/logs/backup.log
find "$BACKUP_DIR" -name "*.sql.gz" -mtime +30 -delete
