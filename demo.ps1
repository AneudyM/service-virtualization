# AlfredPay Service Virtualization POC — Demo Script (PowerShell)
# Usage: .\demo.ps1

$base = "https://service-virtualization.intelycs.com"
$ns = "demo-001"

Write-Host "`n=== AlfredPay Service Virtualization POC Demo ===" -ForegroundColor Cyan
Write-Host "Base URL:  $base"
Write-Host "Namespace: $ns`n"

# 1. Seed scenario
Write-Host "--- 1. Seed Scenario ---" -ForegroundColor Yellow
$seed = Invoke-RestMethod -Uri "$base/control/scenarios" -Method Post -ContentType "application/json" -Body (@{
    namespace = $ns
    domain = "compliance"
    name = "KYC Happy Path - Mexico Individual"
    seed = @(@{
        customer_id = "CUST-MX-001"
        verification_type = "kyc"
        country = "MX"
        callback_url = "$base/health"
        customer_data = @{ first_name = "Maria"; last_name = "Garcia" }
    })
} | ConvertTo-Json -Depth 5)
$seed | ConvertTo-Json -Depth 10
$sessionRef = $seed.data.seeded[0].session_ref
Write-Host "`nSession ref: $sessionRef`n" -ForegroundColor Green

# 2. List sessions
Write-Host "--- 2. List Sessions (should be DRAFT) ---" -ForegroundColor Yellow
$sessions = Invoke-RestMethod -Uri "$base/api/compliance/sessions" -Headers @{"X-Test-Namespace"=$ns}
$sessions | ConvertTo-Json -Depth 10

# 3. Submit documents
Write-Host "`n--- 3. Submit Documents (DRAFT -> PENDING, auto-approve) ---" -ForegroundColor Yellow
$submit = Invoke-RestMethod -Uri "$base/api/compliance/sessions/$sessionRef/submit" -Method Post -ContentType "application/json" -Headers @{"X-Test-Namespace"=$ns} -Body (@{
    documents = @(
        @{ type = "id_front"; filename = "ine_front.jpg" }
        @{ type = "selfie"; filename = "selfie.jpg" }
    )
    auto_outcome = "approved"
    auto_outcome_delay_seconds = 0
} | ConvertTo-Json -Depth 5)
$submit | ConvertTo-Json -Depth 10

# 4. Fire callbacks
Write-Host "`n--- 4. Fire Callbacks (instant delivery) ---" -ForegroundColor Yellow
$fire = Invoke-RestMethod -Uri "$base/control/fire-callbacks" -Method Post -ContentType "application/json" -Body (@{ namespace = $ns } | ConvertTo-Json)
$fire | ConvertTo-Json -Depth 10

# 5. Check final state
Write-Host "`n--- 5. Final State (should be APPROVED) ---" -ForegroundColor Yellow
$final = Invoke-RestMethod -Uri "$base/api/compliance/sessions/$sessionRef" -Headers @{"X-Test-Namespace"=$ns}
$final | ConvertTo-Json -Depth 10

# 6. State history
Write-Host "`n--- 6. State Transition History ---" -ForegroundColor Yellow
$history = Invoke-RestMethod -Uri "$base/api/compliance/sessions/$sessionRef/history" -Headers @{"X-Test-Namespace"=$ns}
$history | ConvertTo-Json -Depth 10

# 7. Full inspection
Write-Host "`n--- 7. Full Namespace Inspection ---" -ForegroundColor Yellow
$inspect = Invoke-RestMethod -Uri "$base/control/scenarios/$ns"
$inspect | ConvertTo-Json -Depth 10

# 8. Cleanup
Write-Host "`n--- 8. Cleanup ---" -ForegroundColor Yellow
$cleanup = Invoke-RestMethod -Uri "$base/control/scenarios/$ns" -Method Delete
$cleanup | ConvertTo-Json -Depth 10

Write-Host "`n=== Demo Complete ===" -ForegroundColor Cyan
Write-Host @"

What this proved:
  1. Scenario seeding with control plane API
  2. Stateful KYC entity (DRAFT -> PENDING -> APPROVED)
  3. Callback orchestration with instant firing
  4. Full state history and observability
  5. Namespace isolation and cleanup
"@ -ForegroundColor Green
