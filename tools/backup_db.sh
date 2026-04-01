#!/bin/bash
# Daily database backup script for Mevzuat Raporu
# Add to crontab: 0 3 * * * /srv/www/mevzuatraporu/tools/backup_db.sh >> /srv/www/mevzuatraporu/logs/backup.log 2>&1
set -euo pipefail

# Load credentials from .env
ENV_FILE="/srv/www/mevzuatraporu/.env"
if [ ! -f "$ENV_FILE" ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') ERROR: .env file not found at $ENV_FILE"
    exit 1
fi

# Parse .env (simple key=value, ignore comments and empty lines)
DB_HOST="127.0.0.1"
DB_NAME="textsocialmedia"
DB_USER="appuser"
DB_PASS=""
while IFS='=' read -r key value; do
    key=$(echo "$key" | xargs)  # trim
    [[ "$key" =~ ^#.*$ || -z "$key" ]] && continue
    value=$(echo "$value" | xargs | sed "s/^['\"]//;s/['\"]$//")
    case "$key" in
        DB_HOST) DB_HOST="$value" ;;
        DB_NAME) DB_NAME="$value" ;;
        DB_USER) DB_USER="$value" ;;
        DB_PASS) DB_PASS="$value" ;;
    esac
done < "$ENV_FILE"

if [ -z "$DB_PASS" ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') ERROR: DB_PASS not set in .env"
    exit 1
fi

# Backup settings
BACKUP_DIR="/var/backups/mevzuatraporu"
RETENTION_DAYS=30
DATE=$(date '+%Y-%m-%d_%H%M%S')
BACKUP_FILE="${BACKUP_DIR}/${DB_NAME}_${DATE}.sql.gz"

# Create backup directory
mkdir -p "$BACKUP_DIR"

echo "$(date '+%Y-%m-%d %H:%M:%S') Starting backup of ${DB_NAME}..."

# Dump and compress
mysqldump \
    --host="$DB_HOST" \
    --user="$DB_USER" \
    --password="$DB_PASS" \
    --single-transaction \
    --routines \
    --triggers \
    --quick \
    "$DB_NAME" | gzip > "$BACKUP_FILE"

# Verify backup was created and has content
if [ ! -s "$BACKUP_FILE" ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') ERROR: Backup file is empty or missing: $BACKUP_FILE"
    exit 1
fi

BACKUP_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
echo "$(date '+%Y-%m-%d %H:%M:%S') Backup complete: $BACKUP_FILE ($BACKUP_SIZE)"

# Purge old backups
DELETED=$(find "$BACKUP_DIR" -name "*.sql.gz" -mtime +${RETENTION_DAYS} -delete -print | wc -l)
if [ "$DELETED" -gt 0 ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') Purged $DELETED backup(s) older than ${RETENTION_DAYS} days"
fi

echo "$(date '+%Y-%m-%d %H:%M:%S') Backup job finished successfully"
