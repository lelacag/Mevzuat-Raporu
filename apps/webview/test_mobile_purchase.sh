#!/usr/bin/env bash
set -euo pipefail

BASE_URL="http://localhost/textsocialmedia"
API_TOKEN="${IAP_API_TOKEN:-testtoken}"

echo "Simulating mobile purchase (test mode)"

curl -s -X POST "$BASE_URL/api/validate_iap.php" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $API_TOKEN" \
  -d '{"platform":"android","user_id":1,"plan":"monthly","purchase_token":"TEST_SUCCESS"}' | jq '.' || true

echo "Done. If you see success, test-mode purchase worked."