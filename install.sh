#!/usr/bin/env bash
set -euo pipefail

# Installer for POS API and application
# This script prepares the SQLite database, default users, and required directories.

cd "$(dirname "$0")"

if ! command -v php >/dev/null 2>&1; then
  echo "Error: PHP is not installed. Please install PHP 8.0 or later."
  exit 1
fi

DATA_DIR="./data"
UPLOAD_DIR="./images/uploads"

mkdir -p "$DATA_DIR"
mkdir -p "$UPLOAD_DIR"

echo "Preparing data directory: $DATA_DIR"
echo "Preparing upload directory: $UPLOAD_DIR"

echo "Initializing database and default users..."
cat <<'PHP' | php -d display_errors=1
<?php
require_once __DIR__ . '/inc/functions.php';
$db = get_database();
migrate_default_users();
$path = get_db_path();
echo "Database initialized at: $path\n";
echo "Default users created or verified.\n";
PHP

echo "Installer complete."
echo "To start the app, run: ./run_pos.sh"
