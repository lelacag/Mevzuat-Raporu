#!/usr/bin/env bash
# Enforce repository no-JavaScript policy.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
FAIL=0

echo "== No-JS policy scan: $ROOT =="

echo
echo "-- Standalone JS/TS files (excluding vendor) --"
mapfile -t JS_FILES < <(find . -type f \( -name '*.js' -o -name '*.mjs' -o -name '*.cjs' -o -name '*.ts' -o -name '*.tsx' -o -name '*.jsx' \) \
  ! -path './vendor/*' ! -path './tmp/*' ! -path './logs/*' 2>/dev/null || true)
if ((${#JS_FILES[@]})); then
  printf '%s\n' "${JS_FILES[@]}"
  FAIL=1
else
  echo OK
fi

echo
echo "-- Inline <script> / event handlers / javascript: URIs --"
# vendor PHPMailer has a regex capture group named script — exclude vendor
HITS=$(grep -RInE \
  -e '<script[\s>]' \
  -e '</script>' \
  -e '[[:space:]]on(click|submit|input|change|load|error|keyup|keydown|mouseover|focus|blur)[[:space:]]*=' \
  -e 'javascript:' \
  -e 'addEventListener[[:space:]]*\(' \
  --include='*.php' --include='*.html' --include='*.htm' \
  --exclude-dir=vendor --exclude-dir=tmp --exclude-dir=logs \
  . 2>/dev/null | grep -v 'outbound.php:.*javascript:' | grep -v 'Disallow javascript' || true)

# Allow security denylist comments that mention javascript:
HITS=$(echo "$HITS" | grep -v 'Disallow javascript' || true)

if [[ -n "${HITS// }" ]]; then
  echo "$HITS"
  FAIL=1
else
  echo OK
fi

echo
echo "-- CSP script-src 'none' --"
if grep -q "script-src 'none'" includes/header.php 2>/dev/null; then
  echo OK header.php
else
  echo "FAIL: includes/header.php missing script-src 'none'"
  FAIL=1
fi
if [[ -f includes/landing_header.php ]]; then
  if grep -q "script-src 'none'" includes/landing_header.php; then
    echo OK landing_header.php
  else
    echo "FAIL: includes/landing_header.php missing script-src 'none'"
    FAIL=1
  fi
fi

echo
if [[ "$FAIL" -ne 0 ]]; then
  echo "RESULT: FAIL — JavaScript policy violations found"
  exit 1
fi
echo "RESULT: PASS — no client JavaScript found"
exit 0
