<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\JsonResponse;
use App\Entity\EntityManager;
use App\ExchangeCopter\ExchangeCopterService;
use App\UI\VirtualUI;

final class ExchangeCopterController
{
    public static function dashboardPage(?string $namespace): never
    {
        $ns = $namespace ?? 'default';
        $cvus = EntityManager::findAllByNamespace($ns, ExchangeCopterService::ENTITY_CVU);
        $scenario = ExchangeCopterService::getScenario($ns);

        $rows = '';
        foreach ($cvus as $c) {
            $d = $c['data'];
            $flow = $d['flow'] ?? '?';
            $flowBadge = $flow === 'RESIDENT' ? 'b-success' : 'b-neutral';
            $accRef = htmlspecialchars($d['accountRef'] ?? '');
            $idUsr = htmlspecialchars($d['idUsuario'] ?? '');
            $cvu = htmlspecialchars($d['cvu'] ?? '');
            $alias = htmlspecialchars($d['alias'] ?? '');
            $holder = htmlspecialchars(trim(($d['firstName'] ?? '') . ' ' . ($d['lastName'] ?? '')));
            $when = substr($c['created_at'] ?? '', 0, 19);
            $rows .= <<<ROW
            <tr>
                <td class="mono" title="{$accRef}">{$accRef}</td>
                <td class="mono">{$idUsr}</td>
                <td class="mono">{$cvu}</td>
                <td class="mono">{$alias}</td>
                <td>{$holder}</td>
                <td><span class="badge {$flowBadge}">{$flow}</span></td>
                <td class="datetime">{$when}</td>
            </tr>
            ROW;
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="empty-hint">No CVUs issued yet. Use the AR virtual-account flow via penny-api / microserivces-argentina-payex.</td></tr>';
        }

        $count = count($cvus);
        $scenarios = [
            ExchangeCopterService::SCENARIO_HAPPY => 'Happy path (default)',
            ExchangeCopterService::SCENARIO_AUTH_FAIL => 'Auth fails on /login',
            ExchangeCopterService::SCENARIO_NO_CVU_AVAILABLE => 'No CVUs available on create',
            ExchangeCopterService::SCENARIO_INVALID_CUIT => 'Error creating account / saving user',
        ];

        $scenarioButtons = '';
        foreach ($scenarios as $key => $label) {
            $active = $key === $scenario ? 'approve' : 'neutral';
            $scenarioButtons .= <<<BTN
            <button class="scenario {$active}" onclick="action('/control/exchangecopter/scenario', {scenario:'{$key}'}, 'Scenario: {$label}')">{$label}</button>
            BTN;
        }

        $body = <<<BODY
        <section class="card">
            <h2>Active scenario <span class="refresh-hint">current: <strong>{$scenario}</strong></span></h2>
            <div class="actions">{$scenarioButtons}</div>
            <p style="margin-top:0.75rem;font-size:0.8rem;color:#64748b;">
                Switch to exercise the wrapper's error paths. Scenario applies to the next call of the affected endpoint.
                Happy path is always the default on startup.
            </p>
        </section>
        <section class="card">
            <h2>Issued CVUs <span class="refresh-hint">{$count} CVU(s)</span></h2>
            <table>
                <thead><tr>
                    <th>accountRef</th><th>idUsuario</th><th>CVU</th><th>Alias</th><th>Holder</th><th>Flow</th><th>Created</th>
                </tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        </section>
        <section class="card">
            <h2>About this provider</h2>
            <p style="font-size:0.875rem;color:#475569;line-height:1.6;">
                Virtual <strong>ExchangeCopter</strong> stands in for <span class="mono">api.exchangecopter.com</span>,
                the TotalPay-side CVU provisioning used by <span class="mono">microserivces-argentina-payex</span>.
                Issues CVUs deterministically from accountRef (UUID). Two flows:
                <strong>RESIDENT</strong> (<span class="mono">/creacionCVUConRegistroWallets</span>) for Argentinian individuals,
                <strong>NOTRESIDENT</strong> (<span class="mono">/devolucionCVUonPayexAlfred</span>) for foreign users
                (e.g. the Decaf MX customer routing through AR for GlobeLink).
            </p>
        </section>
        BODY;

        VirtualUI::renderPage(
            'ExchangeCopter',
            'api.exchangecopter.com: AR CVU provisioning. Fronted by microserivces-argentina-payex.',
            '135deg, #0c4a6e 0%, #0284c7 100%',
            '#0284c7',
            $body,
        );
    }

    public static function login(?string $namespace): never
    {
        $r = ExchangeCopterService::login($namespace ?? 'default');
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function creacionCvuConRegistroWallets(?string $namespace, array $body): never
    {
        $r = ExchangeCopterService::creacionCvuConRegistroWallets($namespace ?? 'default', $body);
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function creacionAlias(?string $namespace, array $body): never
    {
        $r = ExchangeCopterService::creacionAlias($namespace ?? 'default', $body);
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function devolucionCvuOnPayexAlfred(?string $namespace, array $body): never
    {
        $r = ExchangeCopterService::devolucionCvuOnPayexAlfred($namespace ?? 'default', $body);
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function checkCbuAlias(string $aliasOrCvu): never
    {
        $r = ExchangeCopterService::checkCbuAlias($aliasOrCvu);
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function balance(?string $namespace, string $idCvu): never
    {
        $r = ExchangeCopterService::balance($namespace ?? 'default', $idCvu);
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function createTransactionTotalPay(?string $namespace, array $body): never
    {
        $r = ExchangeCopterService::createTransactionTotalPay($namespace ?? 'default', $body);
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function consultaCoelsaIdTotalPay(?string $namespace, string $coelsaId): never
    {
        $r = ExchangeCopterService::consultaCoelsaIdTotalPay($namespace ?? 'default', $coelsaId);
        JsonResponse::send($r['body'], $r['status']);
    }

    public static function setScenario(?string $namespace, array $body): never
    {
        $s = (string)($body['scenario'] ?? ExchangeCopterService::SCENARIO_HAPPY);
        ExchangeCopterService::setScenario($namespace ?? 'default', $s);
        JsonResponse::send(['scenario' => $s]);
    }
}
