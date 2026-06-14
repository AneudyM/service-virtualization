<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bankaool\BankaoolFixtures;
use App\Bankaool\BankaoolService;
use App\Core\JsonResponse;

/**
 * Bankaool Controller: API endpoints + browser UI.
 *
 * Two integration surfaces:
 *   1. Bankaool Direct API (OAuth2 + /v1/...): called by ramps-mexico
 *   2. Paymax/SRC Microservice API: called by rampas-penny-api-polybase
 *
 * Browser UI:
 *   GET /banks/bankaool: Dashboard with send-money form, account info, and transaction history
 *
 * Control Plane:
 *   GET  /control/bankaool/transactions: List all transactions
 *   POST /control/bankaool/deposit     : Trigger inbound deposit via API
 */
final class BankaoolController
{
    /**
     * POST /banks/bankaool/oauth2/token
     */
    public static function oauthToken(array $body): never
    {
        $result = BankaoolService::authenticate($body);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * GET /banks/bankaool/v1/cuenta
     */
    public static function getAccounts(?string $namespace): never
    {
        $result = BankaoolService::getAccounts($namespace ?? 'default');
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * GET /banks/bankaool/v1/cuenta/{id}/medios-pago
     */
    public static function getPaymentMethods(string $accountId, ?string $namespace): never
    {
        $result = BankaoolService::getPaymentMethods($namespace ?? 'default', $accountId);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * POST /banks/bankaool/v1/consulta-banco
     * ramps-mexico sends form-urlencoded: cuenta_destino={clabe}
     */
    public static function lookupBank(array $body): never
    {
        $accountNumber = $body['cuenta_destino'] ?? '';
        $result = BankaoolService::lookupBank($accountNumber);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * POST /banks/bankaool/v1/token-otp
     */
    public static function tokenOtp(?string $namespace): never
    {
        $result = BankaoolService::generateOtp($namespace ?? 'default');
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * POST /banks/bankaool/v1/transferir
     */
    public static function transfer(array $body, ?string $namespace): never
    {
        $result = BankaoolService::createTransfer($namespace ?? 'default', $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * POST /banks/bankaool/v1/aprobar-transferencia
     */
    public static function approveTransfer(array $body, ?string $namespace): never
    {
        $result = BankaoolService::approveTransfer($namespace ?? 'default', $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * POST /banks/bankaool/v1/cobranza
     */
    public static function collectionDeposit(array $body, ?string $namespace): never
    {
        $result = BankaoolService::createCollectionDeposit($namespace ?? 'default', $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * POST /banks/bankaool/auth/token
     */
    public static function authTokenSRC(array $body): never
    {
        $result = BankaoolService::authTokenSRC($body);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * POST /banks/bankaool/paymax/executen-payment
     */
    public static function executePayment(array $body, ?string $namespace): never
    {
        $result = BankaoolService::executePayment($namespace ?? 'default', $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * POST /banks/bankaool/paymax/customer-is-balance/create
     */
    public static function createCustomerAccount(array $body, ?string $namespace): never
    {
        $result = BankaoolService::createCustomerAccount($namespace ?? 'default', $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * GET /banks/bankaool/customer/customer-is-balance/account/{customer}
     */
    public static function getCustomerAccount(string $customerId, ?string $namespace): never
    {
        $result = BankaoolService::getCustomerAccount($namespace ?? 'default', $customerId);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * GET /banks/bankaool/customer/customer-is-balance/account-clabe/{clabe}
     */
    public static function getCustomerByClabe(string $clabe, ?string $namespace): never
    {
        $result = BankaoolService::getCustomerByClabe($namespace ?? 'default', $clabe);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * POST /banks/bankaool/paymax/customer-is-balance/accredit-payment
     */
    public static function accreditPayment(array $body, ?string $namespace): never
    {
        $result = BankaoolService::accreditPayment($namespace ?? 'default', $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * POST /banks/bankaool/paymax/customer-is-balance/debit-payment
     */
    public static function debitPayment(array $body, ?string $namespace): never
    {
        $result = BankaoolService::debitPayment($namespace ?? 'default', $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * POST /banks/bankaool/paymax/generate/deposit
     */
    public static function generateDeposit(array $body, ?string $namespace): never
    {
        $result = BankaoolService::generateDeposit($namespace ?? 'default', $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * GET /control/bankaool/transactions
     */
    public static function controlListTransactions(?string $namespace): never
    {
        $transactions = BankaoolService::getTransactionHistory($namespace ?? 'default');
        JsonResponse::send([
            'namespace'    => $namespace ?? 'default',
            'count'        => count($transactions),
            'transactions' => $transactions,
        ]);
    }

    /**
     * POST /control/bankaool/deposit: API-driven inbound deposit.
     */
    public static function controlDeposit(array $body, ?string $namespace): never
    {
        $result = BankaoolService::simulateInboundDeposit($namespace ?? 'default', $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * GET /banks/bankaool/banks: List all Mexican banks.
     */
    public static function listBanks(): never
    {
        JsonResponse::send(BankaoolFixtures::getAllBanks());
    }

    /**
     * POST /banks/bankaool/validate-clabe: Validate a CLABE.
     */
    public static function validateClabe(array $body): never
    {
        $clabe = $body['clabe'] ?? '';
        $result = BankaoolFixtures::validateClabe($clabe);
        JsonResponse::send($result, $result['valid'] ? 200 : 400);
    }

    /**
     * GET /banks/bankaool: Main dashboard page.
     */
    public static function dashboardPage(?string $namespace): never
    {
        $ns = $namespace ?? 'default';
        $balance = BankaoolService::getBalance($ns);
        $transactions = BankaoolService::getTransactionHistory($ns);
        $clabe = BankaoolFixtures::ALFREDPAY_ACCOUNT['clabe'];
        $accountAlias = BankaoolFixtures::ALFREDPAY_ACCOUNT['alias'];
        $baseUrl = rtrim($_ENV['APP_BASE_URL'] ?? 'http://localhost:8080', '/');

        $txRowsHtml = '';
        if (empty($transactions)) {
            $txRowsHtml = '<tr><td colspan="8" class="empty">No transactions yet. Use the form above to simulate a deposit.</td></tr>';
        } else {
            foreach ($transactions as $tx) {
                $dirClass = $tx['direction'] === 'IN' ? 'dir-in' : 'dir-out';
                $dirIcon = $tx['direction'] === 'IN' ? '+' : '-';
                $statusClass = match ($tx['status']) {
                    'LIQUIDADA'  => 'st-settled',
                    'INSTRUIDA'  => 'st-pending',
                    'ERROR'      => 'st-error',
                    'DEVUELTA'   => 'st-returned',
                    default      => '',
                };
                $amountFmt = number_format($tx['amount'], 2);
                $claveShort = substr($tx['clave_rastreo'], 0, 16) . '...';
                $dateShort = substr($tx['created_at'], 0, 19);
                $counterparty = $tx['direction'] === 'IN'
                    ? htmlspecialchars($tx['sender'])
                    : htmlspecialchars($tx['receiver']);
                $counterpartyClabe = $tx['direction'] === 'IN'
                    ? $tx['sender_clabe']
                    : $tx['receiver_clabe'];

                $txRowsHtml .= <<<ROW
                <tr>
                    <td><span class="dir-badge {$dirClass}">{$dirIcon} {$tx['direction']}</span></td>
                    <td class="amount {$dirClass}">{$dirIcon} \${$amountFmt}</td>
                    <td title="{$tx['clave_rastreo']}">{$claveShort}</td>
                    <td>{$counterparty}</td>
                    <td class="mono">{$counterpartyClabe}</td>
                    <td>{$tx['concepto']}</td>
                    <td><span class="status-badge {$statusClass}">{$tx['status']}</span></td>
                    <td class="datetime">{$dateShort}</td>
                </tr>
                ROW;
            }
        }

        $balanceFmt = number_format($balance, 2);
        $txCount = count($transactions);

        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(200);

        echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bankaool Virtual | AlfredPay Service Virtualization</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            -webkit-font-smoothing: antialiased;
        }

        .vs-badge {
            position: fixed; top: 12px; right: 12px; z-index: 100;
            background: rgba(255,243,205,0.95); color: #856404;
            padding: 4px 10px; border-radius: 4px; font-size: 10px;
            font-weight: 700; letter-spacing: 0.5px; border: 1px solid #ffc107;
        }
        header {
            background: linear-gradient(135deg, #1a365d 0%, #2563eb 100%);
            color: white; padding: 1.5rem 2rem;
        }
        header h1 { font-size: 1.5rem; font-weight: 700; }
        header .subtitle { font-size: 0.875rem; opacity: 0.8; margin-top: 0.25rem; }
        .container { max-width: 1200px; margin: 0 auto; padding: 1.5rem; }

        .account-card {
            background: white; border-radius: 12px; padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 1.5rem;
            display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1.5rem;
        }
        .account-card .label { font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
        .account-card .value { font-size: 1.1rem; font-weight: 600; }
        .account-card .balance { font-size: 1.75rem; font-weight: 700; color: #059669; }
        .mono { font-family: 'Courier New', monospace; font-size: 0.85rem; }

        .grid { display: grid; grid-template-columns: 400px 1fr; gap: 1.5rem; align-items: start; }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }

        .send-card {
            background: white; border-radius: 12px; padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .send-card h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .send-card h2::before { content: ''; display: block; width: 4px; height: 20px; background: #2563eb; border-radius: 2px; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-size: 0.8rem; font-weight: 500; color: #475569; margin-bottom: 0.375rem; }
        .form-group input, .form-group select {
            width: 100%; padding: 0.625rem 0.75rem; border: 1px solid #e2e8f0;
            border-radius: 6px; font-family: inherit; font-size: 0.875rem;
            transition: border-color 0.15s;
        }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .form-group .hint { font-size: 0.7rem; color: #94a3b8; margin-top: 0.25rem; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }

        .btn-send {
            width: 100%; padding: 0.75rem; background: #2563eb; color: white;
            border: none; border-radius: 6px; font-family: inherit;
            font-size: 0.95rem; font-weight: 600; cursor: pointer;
            transition: background 0.15s;
        }
        .btn-send:hover { background: #1d4ed8; }
        .btn-send:disabled { background: #94a3b8; cursor: not-allowed; }

        .clabe-validation { font-size: 0.75rem; margin-top: 0.25rem; }
        .clabe-valid { color: #059669; }
        .clabe-invalid { color: #dc2626; }

        .result-banner {
            margin-top: 1rem; padding: 0.75rem 1rem; border-radius: 6px;
            font-size: 0.85rem; display: none;
        }
        .result-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .result-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        .tx-card {
            background: white; border-radius: 12px; padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow-x: auto;
        }
        .tx-card h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .tx-card h2::before { content: ''; display: block; width: 4px; height: 20px; background: #8b5cf6; border-radius: 2px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        th { text-align: left; padding: 0.5rem; border-bottom: 2px solid #e2e8f0; font-weight: 600; color: #64748b; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap; }
        td { padding: 0.5rem; border-bottom: 1px solid #f1f5f9; white-space: nowrap; }
        tr:hover td { background: #f8fafc; }
        .empty { text-align: center; color: #94a3b8; padding: 2rem !important; font-style: italic; }

        .dir-badge { padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; }
        .dir-in { color: #059669; }
        .dir-badge.dir-in { background: #ecfdf5; }
        .dir-out { color: #dc2626; }
        .dir-badge.dir-out { background: #fef2f2; }
        .amount { font-weight: 600; font-family: 'Courier New', monospace; }

        .status-badge { padding: 2px 8px; border-radius: 4px; font-size: 0.65rem; font-weight: 600; text-transform: uppercase; }
        .st-settled { background: #ecfdf5; color: #059669; }
        .st-pending { background: #fef3c7; color: #92400e; }
        .st-error { background: #fef2f2; color: #dc2626; }
        .st-returned { background: #ede9fe; color: #7c3aed; }

        .datetime { font-family: 'Courier New', monospace; font-size: 0.75rem; color: #64748b; }

        footer { text-align: center; padding: 2rem; color: #94a3b8; font-size: 0.75rem; }
    </style>
</head>
<body>
    <div class="vs-badge">VIRTUAL SERVICE</div>

    <header>
        <h1>Bankaool Virtual Banking</h1>
        <div class="subtitle">AlfredPay Service Virtualization Platform &mdash; SPEI Transfer Simulator</div>
    </header>

    <div class="container">
        <!-- Account Overview -->
        <div class="account-card">
            <div>
                <div class="label">Account</div>
                <div class="value">{$accountAlias}</div>
            </div>
            <div>
                <div class="label">CLABE</div>
                <div class="value mono" id="account-clabe">{$clabe}</div>
            </div>
            <div>
                <div class="label">Balance (MXN)</div>
                <div class="balance" id="account-balance">\${$balanceFmt}</div>
            </div>
        </div>

        <div class="grid">
            <!-- Send Money Form -->
            <div class="send-card">
                <h2>Send Money via SPEI</h2>
                <form id="send-form" onsubmit="return handleSend(event)">
                    <div class="form-group">
                        <label for="destination_clabe">Destination CLABE</label>
                        <input type="text" id="destination_clabe" name="destination_clabe"
                               placeholder="18-digit CLABE number" maxlength="18"
                               pattern="\\d{18}" required oninput="validateClabeInput(this)">
                        <div id="clabe-feedback" class="clabe-validation"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount">Amount (MXN)</label>
                            <input type="number" id="amount" name="amount"
                                   placeholder="0.00" step="0.01" min="1" max="500000" required>
                        </div>
                        <div class="form-group">
                            <label for="sender_bank">Sender Bank</label>
                            <select id="sender_bank" name="sender_bank">
                                <option value="012">BBVA Mexico</option>
                                <option value="014">Santander</option>
                                <option value="021">HSBC</option>
                                <option value="072">Banorte</option>
                                <option value="127">Azteca</option>
                                <option value="147" selected>Bankaool</option>
                                <option value="646">STP</option>
                                <option value="722">Mercado Pago</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="sender_name">Sender Name</label>
                        <input type="text" id="sender_name" name="sender_name"
                               placeholder="JUAN PEREZ GARCIA" value="VIRTUAL SENDER" required>
                    </div>

                    <div class="form-group">
                        <label for="sender_rfc">RFC / CURP (Sender)</label>
                        <input type="text" id="sender_rfc" name="sender_rfc"
                               placeholder="XAXX010101000" value="XAXX010101000">
                        <div class="hint">Tax ID of the sending party</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="concepto">Concept</label>
                            <input type="text" id="concepto" name="concepto"
                                   placeholder="Payment description" value="Deposito SPEI">
                        </div>
                        <div class="form-group">
                            <label for="referencia">Reference</label>
                            <input type="text" id="referencia" name="referencia"
                                   placeholder="1234567" maxlength="7">
                            <div class="hint">7-digit numeric reference (auto-generated if empty)</div>
                        </div>
                    </div>

                    <button type="submit" class="btn-send" id="btn-send">Send SPEI Transfer</button>

                    <div id="result-banner" class="result-banner"></div>
                </form>
            </div>

            <!-- Transactions -->
            <div class="tx-card">
                <h2>Transaction History ({$txCount})</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Dir</th>
                            <th>Amount</th>
                            <th>Clave Rastreo</th>
                            <th>Counterparty</th>
                            <th>CLABE</th>
                            <th>Concept</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody id="tx-body">
                        {$txRowsHtml}
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <footer>
        Bankaool Virtual Service &mdash; AlfredPay Service Virtualization Platform v0.1.0
    </footer>

    <script>
        const BASE = '{$baseUrl}';
        const NS = '{$ns}';

        function validateClabeInput(input) {
            const val = input.value.replace(/\D/g, '');
            input.value = val;
            const fb = document.getElementById('clabe-feedback');
            if (val.length < 18) {
                fb.textContent = val.length + '/18 digits';
                fb.className = 'clabe-validation';
                return;
            }
            // Send validation request
            fetch(BASE + '/banks/bankaool/validate-clabe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ clabe: val })
            })
            .then(r => r.json())
            .then(data => {
                if (data.valid) {
                    fb.textContent = 'Valid: ' + data.bank_name + ' (' + data.bank_code + ')';
                    fb.className = 'clabe-validation clabe-valid';
                } else {
                    fb.textContent = data.error || 'Invalid CLABE';
                    fb.className = 'clabe-validation clabe-invalid';
                }
            })
            .catch(() => {
                fb.textContent = 'Could not validate';
                fb.className = 'clabe-validation clabe-invalid';
            });
        }

        async function handleSend(e) {
            e.preventDefault();
            const btn = document.getElementById('btn-send');
            const banner = document.getElementById('result-banner');
            btn.disabled = true;
            btn.textContent = 'Sending...';
            banner.style.display = 'none';

            const senderBank = document.getElementById('sender_bank').value;
            // Generate a sender CLABE using the selected bank code prefix
            const senderClabe = senderBank + '1' + '0000000000' + '0'; // Simplified; server generates proper one

            const payload = {
                destination_clabe: document.getElementById('destination_clabe').value,
                amount: parseFloat(document.getElementById('amount').value),
                sender_name: document.getElementById('sender_name').value,
                sender_rfc: document.getElementById('sender_rfc').value,
                sender_clabe: senderClabe,
                concepto: document.getElementById('concepto').value,
                referencia: document.getElementById('referencia').value || undefined,
            };

            try {
                const resp = await fetch(BASE + '/control/bankaool/deposit?namespace=' + NS, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await resp.json();

                if (resp.ok && !data.error) {
                    banner.className = 'result-banner result-success';
                    banner.innerHTML = '<strong>Transfer sent!</strong> Clave de rastreo: <code>' + data.clave_rastreo + '</code> | MXN ' + data.amount;
                    banner.style.display = 'block';
                    // Refresh page after a short delay to show new transaction
                    setTimeout(() => location.reload(), 1500);
                } else {
                    banner.className = 'result-banner result-error';
                    banner.textContent = data.error || data.message || 'Transfer failed';
                    banner.style.display = 'block';
                }
            } catch (err) {
                banner.className = 'result-banner result-error';
                banner.textContent = 'Network error: ' + err.message;
                banner.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Send SPEI Transfer';
            }
        }
    </script>
</body>
</html>
HTML;
        exit;
    }
}
