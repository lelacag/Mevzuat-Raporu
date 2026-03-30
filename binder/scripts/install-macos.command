#!/bin/bash
# macOS double-click installer wrapper
DIR="$(cd "$(dirname "$0")/.." && pwd)"
open "${DIR}"
cd "${DIR}"
./scripts/install.sh
read -p "Press Enter to close this window..." -r
