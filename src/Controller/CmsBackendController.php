<?php

declare(strict_types=1);

namespace App\Controller;

use App\CmsBackend\CmsBackendService;
use App\Core\JsonResponse;
use App\UI\VirtualUI;

final class CmsBackendController
{
    public static function dashboardPage(?string $namespace): never
    {
        $ns = self::ns($namespace);
        $state = CmsBackendService::getState($ns);
        $nsEsc = self::e($ns);
        $nsUrl = rawurlencode($ns);
        $stateJson = self::e(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $accountRows = '';
        foreach ($state['accounts'] ?? [] as $account) {
            $user = $account['user'] ?? [];
            $client = $user['client'] ?? [];
            $accountState = $user['account'] ?? [];
            $email = self::e($account['email'] ?? '');
            $password = self::e($account['password'] ?? '');
            $clientKey = self::e($client['key'] ?? '');
            $status = self::e($accountState['status'] ?? 'unknown');
            $mainAccount = !empty($user['mainAccount']) || !empty($client['mainAccount']) ? 'yes' : 'no';
            $features = self::featureBadges($user['features'] ?? []);
            $statusClass = $status === 'approved' ? 'b-success' : ($status === 'pending' ? 'b-pending' : 'b-neutral');

            $accountRows .= <<<ROW
            <tr>
                <td class="mono">{$email}</td>
                <td class="mono">{$password}</td>
                <td class="mono">{$clientKey}</td>
                <td>{$mainAccount}</td>
                <td><span class="badge {$statusClass}">{$status}</span></td>
                <td><div class="feature-list">{$features}</div></td>
            </tr>
            ROW;
        }

        $balanceRows = '';
        foreach ($state['mainAccounts'] ?? [] as $account) {
            $accountId = (string)($account['accountId'] ?? $account['id'] ?? '');
            $currency = self::e($account['currency'] ?? '');
            $displayName = self::e($account['displayName'] ?? '');
            $bankAccount = $account['bankAccount'] ?? [];
            $bankName = self::e($bankAccount['bankName'] ?? '');
            $accountNumber = self::e($bankAccount['accountNumber'] ?? '');
            $balance = self::e($state['balances'][$accountId] ?? '0.00');
            $accountIdEsc = self::e($accountId);

            $balanceRows .= <<<ROW
            <tr>
                <td class="mono">{$accountIdEsc}</td>
                <td>{$displayName}</td>
                <td><span class="badge b-neutral">{$currency}</span></td>
                <td class="mono">{$balance}</td>
                <td>{$bankName}</td>
                <td class="mono">{$accountNumber}</td>
            </tr>
            ROW;
        }

        $recipientRows = '';
        foreach ($state['fiatAccounts'] ?? [] as $account) {
            $id = self::e($account['fiatAccountId'] ?? $account['id'] ?? '');
            $name = self::e($account['accountName'] ?? $account['name'] ?? '');
            $currency = self::e($account['currency'] ?? '');
            $type = self::e($account['type'] ?? '');
            $accountNumber = self::e($account['accountNumber'] ?? ($account['fiatAccountFields']['accountNumber'] ?? ''));
            $isDefault = !empty($account['default']) ? 'yes' : 'no';

            $recipientRows .= <<<ROW
            <tr>
                <td class="mono">{$id}</td>
                <td>{$name}</td>
                <td><span class="badge b-neutral">{$currency}</span></td>
                <td class="mono">{$type}</td>
                <td class="mono">{$accountNumber}</td>
                <td>{$isDefault}</td>
            </tr>
            ROW;
        }

        if ($accountRows === '') {
            $accountRows = '<tr><td colspan="6" class="empty-hint">No mock accounts configured.</td></tr>';
        }
        if ($balanceRows === '') {
            $balanceRows = '<tr><td colspan="6" class="empty-hint">No main accounts configured.</td></tr>';
        }
        if ($recipientRows === '') {
            $recipientRows = '<tr><td colspan="6" class="empty-hint">No recipients configured.</td></tr>';
        }

        $accountCount = count($state['accounts'] ?? []);
        $mainAccountCount = count($state['mainAccounts'] ?? []);
        $recipientCount = count($state['fiatAccounts'] ?? []);
        $payinCount = count($state['payins'] ?? []);
        $payoutCount = count($state['payouts'] ?? []);
        $transferCount = count($state['transfers'] ?? []);
        $transactionCount = count($state['transactions'] ?? []);

        $body = <<<HTML
        <style>
            .cms-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
            .metric { border: 1px solid var(--md-sys-color-outline-variant); border-radius: 10px; padding: 0.9rem; background: #f8fafc; }
            .metric .label { color: #64748b; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; }
            .metric .value { margin-top: 0.35rem; font-size: 1.35rem; font-weight: 700; }
            .control-row { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: end; }
            .field { display: flex; flex-direction: column; gap: 0.35rem; min-width: 260px; }
            .field label { color: #64748b; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
            .field input, .field textarea {
                width: 100%; border: 1px solid var(--md-sys-color-outline); border-radius: 8px;
                padding: 0.65rem 0.75rem; font: inherit; background: #fff; color: #0f172a;
            }
            .json-editor {
                min-height: 420px; resize: vertical; font-family: 'Roboto Mono', monospace;
                font-size: 0.78rem; line-height: 1.45; white-space: pre;
            }
            .feature-list { display: flex; gap: 0.3rem; flex-wrap: wrap; max-width: 520px; }
            .feature-list .badge { margin-bottom: 0.2rem; }
            .table-wrap { overflow-x: auto; }
            .link-list { display: flex; gap: 0.75rem; flex-wrap: wrap; font-size: 0.85rem; }
            .link-list a { color: var(--md-sys-color-primary); font-weight: 600; text-decoration: none; }
            .danger-note { margin-top: 0.75rem; color: #92400e; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; padding: 0.75rem; font-size: 0.85rem; }
        </style>

        <section class="card">
            <h2>CMS backend mock <span class="refresh-hint">namespace: <strong>{$nsEsc}</strong></span></h2>
            <div class="cms-grid">
                <div class="metric"><div class="label">Accounts</div><div class="value">{$accountCount}</div></div>
                <div class="metric"><div class="label">Main accounts</div><div class="value">{$mainAccountCount}</div></div>
                <div class="metric"><div class="label">Recipients</div><div class="value">{$recipientCount}</div></div>
                <div class="metric"><div class="label">Payins</div><div class="value">{$payinCount}</div></div>
                <div class="metric"><div class="label">Payouts</div><div class="value">{$payoutCount}</div></div>
                <div class="metric"><div class="label">Transfers</div><div class="value">{$transferCount}</div></div>
                <div class="metric"><div class="label">Transactions</div><div class="value">{$transactionCount}</div></div>
            </div>
        </section>

        <section class="card">
            <h2>Controls</h2>
            <div class="control-row">
                <div class="field">
                    <label for="cms-namespace">Namespace</label>
                    <input id="cms-namespace" value="{$nsEsc}">
                </div>
                <button class="btn-primary" type="button" onclick="openCmsNamespace()">Open namespace</button>
                <button class="scenario decline" type="button" onclick="resetCmsState()">Reset default state</button>
            </div>
            <div class="actions" style="margin-top:1rem;">
                <button class="scenario approve" type="button" onclick="applyCmsPreset('local-balance-happy')">Local Balance happy path</button>
                <button class="scenario neutral" type="button" onclick="applyCmsPreset('original-flow')">Original flow</button>
                <button class="scenario neutral" type="button" onclick="applyCmsPreset('duplicate-dashboard')">Duplicate dashboard</button>
                <button class="scenario neutral" type="button" onclick="applyCmsPreset('no-recipients')">No recipients</button>
                <button class="scenario neutral" type="button" onclick="applyCmsPreset('kyb-pending')">KYB pending</button>
            </div>
            <div class="danger-note">
                This GUI writes to the same service-virtualization state returned by the CMS API endpoints. Use a unique namespace per Jenkins test run.
            </div>
        </section>

        <section class="card">
            <h2>Login accounts</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th>Email</th><th>Password</th><th>Client key</th><th>Main account</th><th>Status</th><th>Features</th>
                    </tr></thead>
                    <tbody>{$accountRows}</tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h2>Main accounts and balances</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th>Account ID</th><th>Name</th><th>Currency</th><th>Available</th><th>Bank</th><th>Account number</th>
                    </tr></thead>
                    <tbody>{$balanceRows}</tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h2>Recipients</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th>Recipient ID</th><th>Name</th><th>Currency</th><th>Type</th><th>Account number</th><th>Default</th>
                    </tr></thead>
                    <tbody>{$recipientRows}</tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h2>Useful paths</h2>
            <div class="link-list">
                <a href="/control/cms-backend/state?namespace={$nsUrl}">State JSON</a>
                <a href="/user/me?namespace={$nsUrl}">Current user fallback</a>
                <a href="/v2/main-accounts/local-balances-owner/main-accounts?namespace={$nsUrl}">Main accounts API</a>
                <a href="/transactions/fiat-accounts/id?namespace={$nsUrl}&amp;customerId=local-balances-owner&amp;currency=MXN">Recipients API</a>
            </div>
        </section>

        <section class="card">
            <h2>Raw state editor</h2>
            <div class="field">
                <label for="cms-state-json">State JSON</label>
                <textarea id="cms-state-json" class="json-editor" spellcheck="false">{$stateJson}</textarea>
            </div>
            <div class="actions" style="margin-top:0.9rem;">
                <button class="btn-primary" type="button" onclick="saveCmsState()">Save JSON state</button>
                <button class="scenario neutral" type="button" onclick="reloadCmsState()">Reload page</button>
            </div>
        </section>

        <script>
            function cmsNamespaceValue() {
                const value = document.getElementById('cms-namespace').value.trim();
                return value || 'cms-front-local';
            }

            function cmsControlUrl(path) {
                const separator = path.indexOf('?') === -1 ? '?' : '&';
                return path + separator + 'namespace=' + encodeURIComponent(cmsNamespaceValue());
            }

            function openCmsNamespace() {
                window.location.href = window.location.pathname + '?namespace=' + encodeURIComponent(cmsNamespaceValue());
            }

            async function postCms(path, body, label) {
                const response = await fetch(cmsControlUrl(path), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(body || {}),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(payload.message || payload.error || response.statusText);
                }
                toast(label + ': ok');
                setTimeout(() => window.location.reload(), 700);
            }

            function applyCmsPreset(preset) {
                postCms('/control/cms-backend/presets/' + encodeURIComponent(preset), {}, 'Preset ' + preset)
                    .catch((error) => toast(error.message, true));
            }

            function resetCmsState() {
                postCms('/control/cms-backend/reset', {}, 'Reset')
                    .catch((error) => toast(error.message, true));
            }

            function saveCmsState() {
                let parsed;
                try {
                    parsed = JSON.parse(document.getElementById('cms-state-json').value);
                } catch (error) {
                    toast('Invalid JSON: ' + error.message, true);
                    return;
                }

                postCms('/control/cms-backend/state', {state: parsed}, 'State saved')
                    .catch((error) => toast(error.message, true));
            }

            function reloadCmsState() {
                window.location.reload();
            }
        </script>
        HTML;

        VirtualUI::renderPage(
            'CMS Backend',
            'cms-front drop-in API mock: accounts, feature flags, local balances, recipients, and transactions.',
            '135deg, #0f172a 0%, #2563eb 100%',
            '#2563eb',
            $body,
        );
    }

    public static function controlState(?string $namespace): never
    {
        JsonResponse::send([
            'namespace' => self::ns($namespace),
            'state' => CmsBackendService::getState(self::ns($namespace)),
        ]);
    }

    public static function controlSaveState(?string $namespace, array $body): never
    {
        $state = $body['state'] ?? $body;

        if (!is_array($state)) {
            JsonResponse::error('CMS backend state must be a JSON object', 400);
        }

        JsonResponse::send([
            'namespace' => self::ns($namespace),
            'state' => CmsBackendService::replaceState(self::ns($namespace), $state),
        ]);
    }

    public static function controlReset(?string $namespace): never
    {
        JsonResponse::send([
            'namespace' => self::ns($namespace),
            'state' => CmsBackendService::reset(self::ns($namespace)),
        ]);
    }

    public static function controlPreset(?string $namespace, string $preset): never
    {
        $state = CmsBackendService::applyPreset(self::ns($namespace), $preset);

        if ($state === null) {
            JsonResponse::error('Unknown CMS backend preset', 404, ['preset' => $preset]);
        }

        JsonResponse::send([
            'namespace' => self::ns($namespace),
            'preset' => $preset,
            'state' => $state,
        ]);
    }

    public static function passwordlessLogin(array $body): never
    {
        self::send(CmsBackendService::passwordlessLogin($body));
    }

    public static function passwordLogin(?string $namespace, array $body): never
    {
        self::send(CmsBackendService::passwordLogin(self::ns($namespace), $body));
    }

    public static function tokenLogin(?string $namespace): never
    {
        self::send(CmsBackendService::tokenLogin(self::ns($namespace)));
    }

    public static function currentUser(?string $namespace): never
    {
        self::send(CmsBackendService::currentUser(self::ns($namespace), self::authorization()));
    }

    public static function getMainAccount(?string $namespace, string $id): never
    {
        self::send(CmsBackendService::getMainAccount(self::ns($namespace), $id));
    }

    public static function listMainAccounts(?string $namespace, string $id): never
    {
        self::send(CmsBackendService::listMainAccounts(self::ns($namespace)));
    }

    public static function getMainAccountBalance(?string $namespace, string $id): never
    {
        self::send(CmsBackendService::getMainAccountBalance(self::ns($namespace), $id));
    }

    public static function getMainAccountBalanceByCurrency(?string $namespace, string $currency): never
    {
        self::send(CmsBackendService::getMainAccountBalanceByCurrency(self::ns($namespace), $currency));
    }

    public static function createQuote(?string $namespace, array $body): never
    {
        self::send(CmsBackendService::createQuote(self::ns($namespace), $body));
    }

    public static function getQuote(?string $namespace, string $idOrRef): never
    {
        self::send(CmsBackendService::getQuote(self::ns($namespace), $idOrRef));
    }

    public static function createInternalTransfer(?string $namespace, array $body): never
    {
        self::send(CmsBackendService::createInternalTransfer(self::ns($namespace), $body));
    }

    public static function getTransfer(?string $namespace, string $idOrRef): never
    {
        self::send(CmsBackendService::getTransfer(self::ns($namespace), $idOrRef));
    }

    public static function listTransfers(?string $namespace): never
    {
        self::send(CmsBackendService::listTransfers(self::ns($namespace), $_GET));
    }

    public static function updateTransfer(?string $namespace, array $body): never
    {
        self::send(CmsBackendService::updateTransfer(self::ns($namespace), $body));
    }

    public static function createPayin(?string $namespace, array $body): never
    {
        self::send(CmsBackendService::createPayin(self::ns($namespace), $body));
    }

    public static function getPayin(?string $namespace, string $id): never
    {
        self::send(CmsBackendService::getPayin(self::ns($namespace), $id));
    }

    public static function listPayins(?string $namespace): never
    {
        self::send(CmsBackendService::listPayins(self::ns($namespace), $_GET));
    }

    public static function createPayout(?string $namespace, array $body): never
    {
        self::send(CmsBackendService::createPayout(self::ns($namespace), $body));
    }

    public static function getPayout(?string $namespace, string $id): never
    {
        self::send(CmsBackendService::getPayout(self::ns($namespace), $id));
    }

    public static function listPayouts(?string $namespace): never
    {
        self::send(CmsBackendService::listPayouts(self::ns($namespace), $_GET));
    }

    public static function getVirtualAccountDeposit(?string $namespace, string $country, string $vaId): never
    {
        self::send(CmsBackendService::getVirtualAccountDeposit(self::ns($namespace), $country, $vaId));
    }

    public static function listTransactions(?string $namespace): never
    {
        self::send(CmsBackendService::listTransactions(self::ns($namespace), $_GET));
    }

    public static function findTransaction(?string $namespace): never
    {
        self::send(CmsBackendService::findTransaction(self::ns($namespace), (string)($_GET['txId'] ?? '')));
    }

    public static function transactionLogs(?string $namespace): never
    {
        self::send(CmsBackendService::transactionLogs(self::ns($namespace), (string)($_GET['txId'] ?? '')));
    }

    public static function createTransaction(?string $namespace, string $type, array $body): never
    {
        self::send(CmsBackendService::createTransaction(self::ns($namespace), $type, $body));
    }

    public static function listFiatAccounts(?string $namespace): never
    {
        self::send(CmsBackendService::listFiatAccounts(self::ns($namespace), $_GET));
    }

    public static function listFiatAccountsByCustomerId(?string $namespace): never
    {
        self::send(CmsBackendService::listFiatAccounts(
            self::ns($namespace),
            $_GET,
            (string)($_GET['customerId'] ?? 'local-balances-owner'),
        ));
    }

    public static function createFiatAccount(?string $namespace, array $body, ?string $customerId = null): never
    {
        self::send(CmsBackendService::createFiatAccount(self::ns($namespace), $body, $customerId));
    }

    public static function setDefaultFiatAccount(?string $namespace, array $body, ?string $customerId = null): never
    {
        self::send(CmsBackendService::setDefaultFiatAccount(
            self::ns($namespace),
            (string)($body['fiatAccountId'] ?? ''),
            $customerId,
        ));
    }

    public static function deleteFiatAccount(?string $namespace, string $fiatAccountId, ?string $customerId = null): never
    {
        self::send(CmsBackendService::deleteFiatAccount(self::ns($namespace), $fiatAccountId, $customerId));
    }

    public static function listLiquidationAddresses(?string $namespace): never
    {
        self::send(CmsBackendService::listLiquidationAddresses(self::ns($namespace), $_GET));
    }

    public static function createLiquidationAddress(?string $namespace, array $body): never
    {
        self::send(CmsBackendService::createLiquidationAddress(self::ns($namespace), $body));
    }

    public static function setDefaultLiquidationAddress(?string $namespace): never
    {
        self::send(CmsBackendService::setDefaultLiquidationAddress(
            self::ns($namespace),
            (string)($_GET['liquidationAddressId'] ?? ''),
        ));
    }

    public static function deleteLiquidationAddress(?string $namespace): never
    {
        self::send(CmsBackendService::deleteLiquidationAddress(
            self::ns($namespace),
            (string)($_GET['liquidationAddressId'] ?? ''),
        ));
    }

    public static function emptyList(): never
    {
        self::send(CmsBackendService::emptyList());
    }

    public static function rolesAllowed(): never
    {
        self::send(CmsBackendService::rolesAllowed());
    }

    public static function clients(?string $namespace): never
    {
        self::send(CmsBackendService::clients(self::ns($namespace)));
    }

    public static function profiles(): never
    {
        self::send(CmsBackendService::profiles());
    }

    public static function apiKeys(): never
    {
        self::send(CmsBackendService::apiKeys());
    }

    private static function send(array $result): never
    {
        JsonResponse::send($result['body'], $result['status'] ?? 200);
    }

    private static function featureBadges(array $features): string
    {
        if ($features === []) {
            return '<span class="badge b-neutral">none</span>';
        }

        $badges = '';
        foreach ($features as $feature) {
            $badges .= '<span class="badge b-neutral">' . self::e($feature) . '</span>';
        }

        return $badges;
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function ns(?string $namespace): string
    {
        return $namespace ?: 'cms-front-local';
    }

    private static function authorization(): ?string
    {
        return $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    }
}
