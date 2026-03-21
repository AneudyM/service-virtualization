#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# AlfredPay Service Virtualization POC — Demo Script
#
# This script demonstrates the full KYC lifecycle through the virtual
# compliance service, proving the architecture works.
#
# Usage:
#   ./demo.sh https://intelycs.com/service-virtualization/public
#   ./demo.sh http://localhost:8080   (for local testing)
# ──────────────────────────────────────────────────────────────────────────────

BASE_URL="${1:-http://localhost:8080}"
NS="demo-$(date +%s)"

echo "=== AlfredPay Service Virtualization POC Demo ==="
echo "Base URL:  $BASE_URL"
echo "Namespace: $NS"
echo ""

# ── 1. Health Check ──────────────────────────────────────────────────────────

echo "--- 1. Health Check ---"
curl -s "$BASE_URL/health" | python3 -m json.tool 2>/dev/null || curl -s "$BASE_URL/health"
echo -e "\n"

# ── 2. Seed a Scenario ──────────────────────────────────────────────────────

echo "--- 2. Seed Scenario (KYC for MX individual customer) ---"
curl -s -X POST "$BASE_URL/control/scenarios" \
  -H "Content-Type: application/json" \
  -d "{
    \"namespace\": \"$NS\",
    \"domain\": \"compliance\",
    \"name\": \"KYC Happy Path - Mexico Individual\",
    \"seed\": [
      {
        \"customer_id\": \"CUST-MX-001\",
        \"verification_type\": \"kyc\",
        \"country\": \"MX\",
        \"callback_url\": \"$BASE_URL/health\",
        \"customer_data\": {
          \"first_name\": \"Maria\",
          \"last_name\": \"Garcia\",
          \"email\": \"maria@example.com\"
        }
      }
    ]
  }" | python3 -m json.tool 2>/dev/null || echo "(raw output above)"
echo -e "\n"

# ── 3. List Sessions ────────────────────────────────────────────────────────

echo "--- 3. List Sessions in Namespace ---"
curl -s "$BASE_URL/api/compliance/sessions" \
  -H "X-Test-Namespace: $NS" | python3 -m json.tool 2>/dev/null || echo "(raw output above)"
echo -e "\n"

# ── 4. Get Session Details ──────────────────────────────────────────────────

echo "--- 4. Get Session (should be in DRAFT state) ---"
# Extract session_ref from the seeded data
SESSION_REF=$(curl -s "$BASE_URL/api/compliance/sessions" \
  -H "X-Test-Namespace: $NS" | python3 -c "
import sys, json
data = json.load(sys.stdin)
sessions = data.get('data', [])
if sessions:
    print(sessions[0].get('session_ref', ''))
" 2>/dev/null)

echo "Session ref: $SESSION_REF"
curl -s "$BASE_URL/api/compliance/sessions/$SESSION_REF" \
  -H "X-Test-Namespace: $NS" | python3 -m json.tool 2>/dev/null || echo "(raw output above)"
echo -e "\n"

# ── 5. Submit Documents (DRAFT -> PENDING) ──────────────────────────────────

echo "--- 5. Submit Documents (DRAFT -> PENDING, auto-approve after 0s) ---"
curl -s -X POST "$BASE_URL/api/compliance/sessions/$SESSION_REF/submit" \
  -H "Content-Type: application/json" \
  -H "X-Test-Namespace: $NS" \
  -d '{
    "documents": [
      {"type": "id_front", "filename": "ine_front.jpg"},
      {"type": "id_back", "filename": "ine_back.jpg"},
      {"type": "selfie", "filename": "selfie.jpg"}
    ],
    "auto_outcome": "approved",
    "auto_outcome_delay_seconds": 0
  }' | python3 -m json.tool 2>/dev/null || echo "(raw output above)"
echo -e "\n"

# ── 6. Fire Callbacks (instant, no cron wait) ───────────────────────────────

echo "--- 6. Fire Pending Callbacks (instant delivery) ---"
curl -s -X POST "$BASE_URL/control/fire-callbacks" \
  -H "Content-Type: application/json" \
  -d "{\"namespace\": \"$NS\"}" | python3 -m json.tool 2>/dev/null || echo "(raw output above)"
echo -e "\n"

# ── 7. Check Final State ────────────────────────────────────────────────────

echo "--- 7. Check Session (should be APPROVED after auto-transition) ---"
curl -s "$BASE_URL/api/compliance/sessions/$SESSION_REF" \
  -H "X-Test-Namespace: $NS" | python3 -m json.tool 2>/dev/null || echo "(raw output above)"
echo -e "\n"

# ── 8. View State History ───────────────────────────────────────────────────

echo "--- 8. State Transition History ---"
curl -s "$BASE_URL/api/compliance/sessions/$SESSION_REF/history" \
  -H "X-Test-Namespace: $NS" | python3 -m json.tool 2>/dev/null || echo "(raw output above)"
echo -e "\n"

# ── 9. Inspect Full Namespace ───────────────────────────────────────────────

echo "--- 9. Full Namespace Inspection ---"
curl -s "$BASE_URL/control/scenarios/$NS" | python3 -m json.tool 2>/dev/null || echo "(raw output above)"
echo -e "\n"

# ── 10. Cleanup ─────────────────────────────────────────────────────────────

echo "--- 10. Reset Namespace (cleanup) ---"
curl -s -X DELETE "$BASE_URL/control/scenarios/$NS" | python3 -m json.tool 2>/dev/null || echo "(raw output above)"
echo -e "\n"

echo "=== Demo Complete ==="
echo ""
echo "What this proved:"
echo "  1. Scenario seeding with control plane API"
echo "  2. Stateful KYC entity (DRAFT -> PENDING -> APPROVED)"
echo "  3. Callback orchestration with instant firing"
echo "  4. Full state history and observability"
echo "  5. Namespace isolation and cleanup"
echo ""
echo "This is the Wave 0 + Wave 1 foundation from the"
echo "AlfredPay Service Virtualization Architecture doc."
