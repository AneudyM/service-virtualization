<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\JsonResponse;
use App\Entity\EntityManager;
use App\Kambia\KambiaService;
use App\UI\VirtualUI;

final class KambiaController
{
    public static function dashboardPage(?string $namespace): never
    {
        $ns = $namespace ?? 'default';
        $transfers = EntityManager::findAllByNamespace($ns, KambiaService::ENTITY_TRANSFER);

        $rows = '';
        foreach ($transfers as $t) {
            $d = $t['data'];
            $status = $d['status'] ?? KambiaService::STATUS_PAID;
            $badge = match ($status) {
                'PAID' => 'b-success',
                'FAILED' => 'b-failed',
                'PENDING', 'PROCESSING' => 'b-pending',
                default => 'b-neutral',
            };
            $tid = htmlspecialchars($d['transfer_id'] ?? '');
            $xref = htmlspecialchars($d['external_reference'] ?? '');
            $amt = htmlspecialchars((string)($d['amount'] ?? ''));
            $acct = $d['external_account'] ?? [];
            $holder = htmlspecialchars(trim(($acct['names'] ?? '') . ' ' . ($acct['lastnames'] ?? '')));
            $accountNum = htmlspecialchars((string)($acct['account_number'] ?? ''));
            $when = substr($t['created_at'] ?? '', 0, 19);
            $rows .= <<<ROW
            <tr>
                <td class="mono">{$tid}</td>
                <td class="mono">{$xref}</td>
                <td>\${$amt}</td>
                <td>{$holder}<br><span class="mono" style="color:#94a3b8;font-size:0.7rem;">{$accountNum}</span></td>
                <td><span class="badge {$badge}">{$status}</span></td>
                <td class="datetime">{$when}</td>
                <td>
                    <div class="actions">
                        <button class="scenario approve" onclick="action('/control/kambia/webhook/{$tid}', {status:'PAID'}, 'Paid + webhook fired')">Approve</button>
                        <button class="scenario decline" onclick="action('/control/kambia/webhook/{$tid}', {status:'FAILED'}, 'Failed + webhook fired')">Decline</button>
                        <button class="scenario neutral" onclick="action('/control/kambia/webhook/{$tid}', {status:'PENDING'}, 'Pending')">Pending</button>
                    </div>
                </td>
            </tr>
            ROW;
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="empty-hint">No transfers yet. Trigger via rampas-colombia payOut cron or POST to /virtual/kambia/kambia/transaction/arch.</td></tr>';
        }

        $txCount = count($transfers);

        $body = <<<BODY
        <section class="card">
            <h2>arch transfers <span class="refresh-hint">{$txCount} transfer(s) · each action fires a webhook back to rampas-colombia</span></h2>
            <table>
                <thead><tr>
                    <th>transfer_id</th><th>external_reference</th><th>Amount</th><th>Beneficiary</th><th>Status</th><th>Created</th><th>Scenario</th>
                </tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        </section>
        <section class="card">
            <h2>About this provider</h2>
            <p style="font-size:0.875rem;color:#475569;line-height:1.6;">
                Virtual <strong>microservice-colombia</strong>: the AlfredPay wrapper in front of Kambia (Colombian PSE/ACH).
                Called by <span class="mono">rampas-colombia</span> via <span class="mono">URL_MICROSERVICE_COLOMBIA</span>.
                rampas-colombia runs the offramp cron and polls <span class="mono">/transaction/arch/details/{id}</span> for status.
                Each action button above BOTH flips the stored status AND fires a webhook to
                <span class="mono">rampas-colombia-local:3010/v1/webhook/webhookKambia</span> to drive the wrapper's async completion logic.
                No AES at this layer: the real Kambia API encryption lives inside the (un-deployed) microservice-colombia.
            </p>
        </section>
        BODY;

        VirtualUI::renderPage(
            'Kambia (microservice-colombia)',
            'Colombian PSE/ACH offramp. Called by rampas-colombia.',
            '135deg, #4338ca 0%, #6366f1 100%',
            '#6366f1',
            $body,
        );
    }


    public static function userLogin(): never
    {
        $r = KambiaService::userLogin();
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function accountDetails(): never
    {
        $r = KambiaService::accountDetails();
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function createTransferArch(string $namespace, array $body): never
    {
        $r = KambiaService::createTransferArch($namespace, $body);
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function getTransferArchDetails(string $namespace, string $id): never
    {
        $r = KambiaService::getTransferArchDetails($namespace, $id);
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function listBanks(): never
    {
        $r = KambiaService::listBanks();
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function listDocumentTypes(): never
    {
        $r = KambiaService::listDocumentTypes();
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function listAccountTypes(): never
    {
        $r = KambiaService::listAccountTypes();
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function controlFireWebhook(string $namespace, string $transferId, array $body): never
    {
        $status = (string)($body['status'] ?? KambiaService::STATUS_PAID);
        $r = KambiaService::controlFireWebhook($namespace, $transferId, $status);
        JsonResponse::send($r['body'], $r['status']);
    }
}
