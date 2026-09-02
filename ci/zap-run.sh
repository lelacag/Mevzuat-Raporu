#!/usr/bin/env bash
set -euo pipefail

TARGET="$1"
if [ -z "${TARGET:-}" ]; then
  echo "Usage: $0 <target-url>"
  exit 2
fi
mkdir -p zap-reports

docker run --rm -v "$(pwd)/zap-reports":/zap/wrk/:rw owasp/zap2docker-stable \
  zap-baseline.py -t "$TARGET" -r zap-reports/zap-report.html -j zap-reports/zap-report.json

echo "ZAP baseline finished. Reports: ./zap-reports/" 
