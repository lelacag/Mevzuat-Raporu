#!/bin/bash
# Simple deployment script for Mevzuat Raporu
# Usage: ./tools/deploy.sh [--skip-migrations]
set -euo pipefail

PROJECT_DIR="/srv/www/mevzuatraporu"
MIGRATIONS_DIR="${PROJECT_DIR}/tools/migrations"
APPLIED_FILE="${MIGRATIONS_DIR}/.applied"
LOG_FILE="${PROJECT_DIR}/logs/deploy.log"

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') $1" | tee -a "$LOG_FILE"
}

cd "$PROJECT_DIR"

log "=== Deploy started ==="

# 1. Pull latest changes
log "Pulling latest changes..."
git pull --ff-only 2>&1 | tee -a "$LOG_FILE"
if [ $? -ne 0 ]; then
    log "ERROR: git pull failed. Resolve conflicts before deploying."
    exit 1
fi

# 2. Run pending migrations (unless skipped)
if [[ "${1:-}" != "--skip-migrations" ]] && [ -d "$MIGRATIONS_DIR" ]; then
    touch "$APPLIED_FILE"
    
    # Load DB credentials from .env
    DB_PASS=""
    DB_USER="appuser"
    DB_NAME="textsocialmedia"
    DB_HOST="127.0.0.1"
    while IFS='=' read -r key value; do
        key=$(echo "$key" | xargs)
        [[ "$key" =~ ^#.*$ || -z "$key" ]] && continue
        value=$(echo "$value" | xargs | sed "s/^['\"]//;s/['\"]$//")
        case "$key" in
            DB_HOST) DB_HOST="$value" ;;
            DB_NAME) DB_NAME="$value" ;;
            DB_USER) DB_USER="$value" ;;
            DB_PASS) DB_PASS="$value" ;;
        esac
    done < "${PROJECT_DIR}/.env"
    
    PENDING=0
    for migration in "$MIGRATIONS_DIR"/*.sql; do
        [ -f "$migration" ] || continue
        basename=$(basename "$migration")
        if ! grep -qxF "$basename" "$APPLIED_FILE"; then
            log "Applying migration: $basename"
            mysql --host="$DB_HOST" --user="$DB_USER" --password="$DB_PASS" "$DB_NAME" < "$migration" 2>&1 | tee -a "$LOG_FILE"
            if [ $? -eq 0 ]; then
                echo "$basename" >> "$APPLIED_FILE"
                log "Migration applied: $basename"
            else
                log "ERROR: Migration failed: $basename"
                exit 1
            fi
            PENDING=$((PENDING + 1))
        fi
    done
    
    if [ "$PENDING" -eq 0 ]; then
        log "No pending migrations."
    fi
fi

# 3. Clear OPcache (if available)
if command -v php &> /dev/null; then
    php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPcache cleared.'; } else { echo 'OPcache not available.'; }" 2>/dev/null | tee -a "$LOG_FILE" || true
fi

# 4. Verify the site is responding
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/" 2>/dev/null || echo "000")
if [[ "$HTTP_CODE" == "200" || "$HTTP_CODE" == "302" ]]; then
    log "Site health check passed (HTTP $HTTP_CODE)"
else
    log "WARNING: Site health check returned HTTP $HTTP_CODE"
fi

log "=== Deploy completed ==="
