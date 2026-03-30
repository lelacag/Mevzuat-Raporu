#!/usr/bin/env bash
set -euo pipefail

echo "Portable binder installer — won't overwrite existing .env/config files unless you confirm."
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SQL_FILE="$ROOT_DIR/database_schema.sql"
SEED_FILE="$ROOT_DIR/seed/seed_data.sql"
EXAMPLE_CONFIG="$(dirname "$ROOT_DIR")/includes/config.example.php"
TARGET_CONFIG="$(dirname "$ROOT_DIR")/includes/config.php"

# Helper: import a SQL file using mysql with optional password
_import_sql() {
  local file="$1"
  read -p "MySQL user (default: root): " dbuser
  dbuser=${dbuser:-root}
  read -s -p "MySQL password (press Enter for none): " dbpass
  echo
  if [[ -z "$dbpass" ]]; then
    mysql -u "$dbuser" < "$file"
  else
    mysql -u "$dbuser" -p"$dbpass" < "$file"
  fi
}

# 1) DB schema
if [[ -f "$SQL_FILE" ]]; then
  read -p "Import database schema now using mysql? [y/N] " ans
  if [[ "$ans" =~ ^[Yy]$ ]]; then
    _import_sql "$SQL_FILE"
    echo "DB schema import finished."
  else
    echo "Skipping DB schema import."
  fi
else
  echo "Database schema not found at $SQL_FILE — please copy database_schema.sql into binder root." >&2
fi

# 2) Optional seed data
if [[ -f "$SEED_FILE" ]]; then
  read -p "Import optional seed data from seed/seed_data.sql? [y/N] " ans2
  if [[ "$ans2" =~ ^[Yy]$ ]]; then
    _import_sql "$SEED_FILE"
    echo "Seed data import finished."
  else
    echo "Skipping seed import."
  fi
fi

# 3) Create config from example (safe: won't overwrite existing config)
if [[ -f "$EXAMPLE_CONFIG" && ! -f "$TARGET_CONFIG" ]]; then
  read -p "Create includes/config.php from includes/config.example.php? [y/N] " c
  if [[ "$c" =~ ^[Yy]$ ]]; then
    cp "$EXAMPLE_CONFIG" "$TARGET_CONFIG"
    echo "Created $TARGET_CONFIG — please edit DB credentials and keys as needed."
  else
    echo "Skipping config creation (no changes made)."
  fi
else
  if [[ -f "$TARGET_CONFIG" ]]; then
    echo "includes/config.php already exists — not overwriting."
  else
    echo "No includes/config.example.php found in the parent project — please create config manually."
  fi
fi

# 4) Permissions
echo "Setting permissive write permissions for common runtime dirs (if present)."
for d in logs sitemap_cache uploads; do
  if [[ -d "$ROOT_DIR/$d" ]]; then
    chmod -R 775 "$ROOT_DIR/$d" || true
    echo "Adjusted permissions: $d"
  fi
done

# 5) macOS suggestion: open Finder to the folder
if [[ "$(uname)" == "Darwin" ]]; then
  echo "(macOS detected) You can open the binder folder in Finder with: open "$ROOT_DIR""
fi

echo "Done. Start your web server (XAMPP) and open the project in your browser."
