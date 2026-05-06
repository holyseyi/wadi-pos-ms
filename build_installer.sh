#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

if ! command -v makensis >/dev/null 2>&1; then
  echo "Error: makensis not found. Install NSIS and add it to your PATH."
  exit 1
fi

makensis pos_installer.nsi

echo "Built pos_installer.exe"
