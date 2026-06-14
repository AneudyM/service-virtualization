<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\JsonResponse;
use App\Entity\EntityManager;
use App\Transfero\TransferoService;
use App\UI\VirtualUI;

final class TransferoController
{
    public static function dashboardPage(?string $namespace): never
    {
        $ns = $namespace ?? 'default';
        $payments = EntityManager::findAllByNamespace($ns, TransferoService::ENTITY_PAYMENT);

        $groupRows = '';
        $copterRows = '';
        $groupCount = 0;
        $copterCount = 0;

        foreach ($payments as $p) {
            $d = $p['data'];
            $kind = $d['kind'] ?? '';
            if ($kind === 'transferoAuth_group') {
                $groupCount++;
                $status = $d['paymentStatus'] ?? TransferoService::STATUS_TRANSFERO_SUCCESS;
                $badge = match ($status) {
                    TransferoService::STATUS_TRANSFERO_SUCCESS => 'b-success',
                    'CompletedWithError', 'Failed' => 'b-failed',
                    'Pending', 'Processing' => 'b-pending',
                    default => 'b-neutral',
                };
                $pgId = htmlspecialchars($d['paymentGroupId'] ?? '');
                $payCount = count($d['payments'] ?? []);
                $firstPay = $d['payments'][0]['paymentId'] ?? '-';
                $when = substr($p['created_at'] ?? '', 0, 19);
                $groupRows .= <<<ROW
                <tr>
                    <td class="mono">{$pgId}</td>
                    <td>{$payCount} payment(s) · first: <span class="mono">{$firstPay}</span></td>
                    <td><span class="badge {$badge}">{$status}</span></td>
                    <td class="datetime">{$when}</td>
                    <td>
                        <div class="actions">
                            <button class="scenario approve" onclick="action('/control/transfero/set-status/{$pgId}', {status:'CompletedWithSuccess'}, 'Approved')">Approve</button>
                            <button class="scenario decline" onclick="action('/control/transfero/set-status/{$pgId}', {status:'CompletedWithError'}, 'Declined')">Decline</button>
                            <button class="scenario neutral" onclick="action('/control/transfero/set-status/{$pgId}', {status:'Pending'}, 'Set Pending')">Pending</button>
                            <button class="scenario neutral" onclick="action('/control/transfero/set-status/{$pgId}', {status:'Processing'}, 'Set Processing')">Processing</button>
                        </div>
                    </td>
                </tr>
                ROW;
            } elseif ($kind === 'copterpay') {
                $copterCount++;
                $status = $d['status'] ?? TransferoService::STATUS_COPTERPAY_SUCCESS;
                $badge = match ($status) {
                    'PAID' => 'b-success',
                    'FAILED' => 'b-failed',
                    'CREATED', 'PROCESSING' => 'b-pending',
                    default => 'b-neutral',
                };
                $id = htmlspecialchars($d['id'] ?? '');
                $code = htmlspecialchars($d['code'] ?? '');
                $amt = htmlspecialchars((string)($d['amount'] ?? ''));
                $pix = htmlspecialchars((string)($d['pixKey'] ?? ''));
                $when = substr($p['created_at'] ?? '', 0, 19);
                $copterRows .= <<<ROW
                <tr>
                    <td class="mono">{$id}</td>
                    <td class="mono">{$code}</td>
                    <td>R\$ {$amt}</td>
                    <td class="mono">{$pix}</td>
                    <td><span class="badge {$badge}">{$status}</span></td>
                    <td class="datetime">{$when}</td>
                    <td>
                        <div class="actions">
                            <button class="scenario approve" onclick="action('/control/transfero/set-status/{$id}', {status:'PAID'}, 'Paid')">Pay</button>
                            <button class="scenario decline" onclick="action('/control/transfero/set-status/{$id}', {status:'FAILED'}, 'Failed')">Fail</button>
                            <button class="scenario neutral" onclick="action('/control/transfero/set-status/{$id}', {status:'PROCESSING'}, 'Processing')">Processing</button>
                        </div>
                    </td>
                </tr>
                ROW;
            }
        }

        if ($groupRows === '') {
            $groupRows = '<tr><td colspan="5" class="empty-hint">No transferoAuth payments yet. Trigger via rampas-brasil payout cron or POST to /virtual/transfero/transferoAuth/send-payment.</td></tr>';
        }
        if ($copterRows === '') {
            $copterRows = '<tr><td colspan="7" class="empty-hint">No copterpay payments yet. Trigger via rampas-brasil or POST to /virtual/transfero/copterpay/payout.</td></tr>';
        }

        $body = <<<BODY
        <section class="card">
            <h2>transferoAuth / send-payment <span class="refresh-hint">{$groupCount} group(s) · auto-refreshes on action</span></h2>
            <table>
                <thead><tr>
                    <th>Payment Group ID</th><th>Payments</th><th>Status</th><th>Created</th><th>Scenario</th>
                </tr></thead>
                <tbody>{$groupRows}</tbody>
            </table>
        </section>
        <section class="card">
            <h2>copterpay / payout <span class="refresh-hint">{$copterCount} payment(s)</span></h2>
            <table>
                <thead><tr>
                    <th>opId</th><th>Code</th><th>Amount</th><th>PIX Key</th><th>Status</th><th>Created</th><th>Scenario</th>
                </tr></thead>
                <tbody>{$copterRows}</tbody>
            </table>
        </section>
        <section class="card">
            <h2>About this provider</h2>
            <p style="font-size:0.875rem;color:#475569;line-height:1.6;">
                Virtual <strong>Transfero</strong> stands in for <span class="mono">openbanking.bit.one</span>, the real PIX payout provider
                called by <span class="mono">rampas-brasil</span> via <span class="mono">TRANSF_URL</span>.
                Two flows: <strong>transferoAuth</strong> (batch PIX) and <strong>copterpay</strong> (single PIX).
                rampas-brasil polls <span class="mono">get-payment</span> / <span class="mono">get-status</span> to transition withdrawals from
                <em>sent</em> → <em>completed</em>. Use the buttons above to flip a payment's status and exercise the wrapper's polling logic.
            </p>
        </section>
        BODY;

        VirtualUI::renderPage(
            'Transfero',
            'openbanking.bit.one: Brazil PIX payouts. Called by rampas-brasil.',
            '135deg, #047857 0%, #10b981 100%',
            '#10b981',
            $body,
        );
    }


    public static function authToken(): never
    {
        $r = TransferoService::authToken();
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function transferoSendPayment(string $namespace, array $body): never
    {
        $r = TransferoService::transferoSendPayment($namespace, $body);
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function transferoGetPayment(string $namespace, string $id): never
    {
        $r = TransferoService::transferoGetPayment($namespace, $id);
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function copterpayPayout(string $namespace, array $body): never
    {
        $r = TransferoService::copterpayPayout($namespace, $body);
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function copterpayGetStatus(string $namespace, string $id): never
    {
        $r = TransferoService::copterpayGetStatus($namespace, $id);
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function controlSetStatus(string $namespace, string $id, array $body): never
    {
        $status = (string)($body['status'] ?? '');
        $r = TransferoService::controlSetStatus($namespace, $id, $status);
        JsonResponse::send($r['body'], $r['status']);
    }
}
