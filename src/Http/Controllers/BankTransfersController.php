<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Http\Auth;
use App\Osm\OsmApi;
use App\Osm\OsmDebug;
use App\Osm\OsmLists;
use App\Store\SettingsStore;
use Throwable;
final class BankTransfersController
{
    /**
     * Node: accountsResponse.data.items / transResponse.data.items
     * @param array<string, mixed> $res
     * @return list<array<string, mixed>>
     */
    private static function bankItems(array $res): array
    {
        foreach ([
            $res['items'] ?? null,              // Node primary
            $res['data']['items'] ?? null,
            $res['data']['data']['items'] ?? null,
            $res['data'] ?? null,
        ] as $c) {
            if (!is_array($c) || $c === []) {
                continue;
            }
            if (!array_is_list($c)) {
                if (isset($c['items']) && is_array($c['items'])) {
                    $c = $c['items'];
                } else {
                    $c = array_values(array_filter($c, 'is_array'));
                }
            }
            if (!is_array($c) || $c === []) {
                continue;
            }
            $out = [];
            foreach ($c as $row) {
                if (!is_array($row)) {
                    continue;
                }
                // accounts have bankaccountid; transactions have type/amount/date
                if (
                    isset($row['bankaccountid']) || isset($row['id']) || isset($row['name'])
                    || isset($row['type']) || isset($row['amount']) || isset($row['date'])
                    || isset($row['reference'])
                ) {
                    $out[] = $row;
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
        return OsmLists::items($res);
    }

    private static function accountsEnabled(array $section): bool
    {
        $up = $section['upgrades'] ?? null;
        if (!is_array($up)) {
            return false;
        }
        $v = $up['accounts'] ?? false;
        if ($v === true || $v === 1 || $v === '1' || $v === 'true') {
            return true;
        }
        return !empty($v);
    }

    /**
     * Try getBankAccounts with type variants; on empty/404 probe upgrades.accounts (Node).
     * @param list<array<string, mixed>> $sections
     * @return array{sectionId: string, sectionType: string, sectionName: string, accounts: list<array<string, mixed>>, accountsRes: array<string, mixed>}
     */
    private static function resolveAccounts(OsmApi $api, string $token, array $sections, string $preferId, string $preferType): array
    {
        $attempts = [];
        if ($preferId !== '') {
            $liveType = $preferType;
            $liveName = $preferId;
            foreach ($sections as $s) {
                if (is_array($s) && (string) ($s['section_id'] ?? '') === $preferId) {
                    $liveType = (string) ($s['section_type'] ?? $liveType);
                    $liveName = (string) ($s['section_name'] ?? $liveName);
                    break;
                }
            }
            foreach ([$liveType, $preferType, 'adults', 'group'] as $type) {
                $type = trim((string) $type);
                if ($type === '') {
                    continue;
                }
                $attempts[] = ['id' => $preferId, 'type' => $type, 'name' => $liveName, 'preferred' => true];
            }
        }
        foreach ($sections as $s) {
            if (!is_array($s) || empty($s['section_id'])) {
                continue;
            }
            if (!self::accountsEnabled($s)) {
                continue;
            }
            $id = (string) $s['section_id'];
            $type = (string) ($s['section_type'] ?? 'adults');
            if ($type === '') {
                $type = 'adults';
            }
            $attempts[] = [
                'id' => $id,
                'type' => $type,
                'name' => (string) ($s['section_name'] ?? $id),
                'preferred' => $id === $preferId,
            ];
        }
        // Last resort: any adults section
        foreach ($sections as $s) {
            if (!is_array($s) || empty($s['section_id'])) {
                continue;
            }
            if (($s['section_type'] ?? '') !== 'adults') {
                continue;
            }
            $id = (string) $s['section_id'];
            $attempts[] = [
                'id' => $id,
                'type' => 'adults',
                'name' => (string) ($s['section_name'] ?? $id),
                'preferred' => $id === $preferId,
            ];
        }

        $seen = [];
        $lastError = null;
        $bestEmpty = null;
        foreach ($attempts as $a) {
            $key = $a['id'] . '|' . $a['type'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            try {
                $res = $api->get($token, '/ext/finances/bank/', [
                    'action' => 'getBankAccounts',
                    'section' => $a['type'],
                    'sectionid' => $a['id'],
                ]);
                OsmDebug::log('bank_accounts_' . $a['id'] . '_' . $a['type'], [
                    'sectionId' => $a['id'],
                    'sectionType' => $a['type'],
                    'top_keys' => array_keys($res),
                    'body' => $res,
                ]);
                $accounts = self::bankItems($res);
                $payload = [
                    'sectionId' => $a['id'],
                    'sectionType' => $a['type'],
                    'sectionName' => $a['name'],
                    'accountsRes' => $res,
                    'accounts' => $accounts,
                ];
                if ($accounts !== []) {
                    return $payload;
                }
                // Remember preferred empty (HTTP 200 but no parseable accounts) but keep probing
                if ($a['preferred'] && $bestEmpty === null) {
                    $bestEmpty = $payload;
                } elseif ($bestEmpty === null) {
                    $bestEmpty = $payload;
                }
            } catch (Throwable $e) {
                $lastError = $e;
                OsmDebug::log('bank_accounts_error_' . $a['id'] . '_' . $a['type'], [
                    'message' => $e->getMessage(),
                    'code' => (int) $e->getCode(),
                ]);
                continue;
            }
        }
        if ($bestEmpty !== null) {
            return $bestEmpty;
        }
        if ($lastError) {
            throw $lastError;
        }
        throw new \RuntimeException(
            'No accessible finance section found after probes'
            . ($preferId !== '' ? " (configured id {$preferId}, type {$preferType})" : '')
            . '.'
        );
    }

    public function index(): void
    {
        $token = Auth::requireLogin();
        $api = new OsmApi();
        try {
            $sections = $api->getDynamicSections($token);
        } catch (Throwable) {
            App::render('error.twig', Auth::baseContext([
                'title' => 'Bank transfers',
                'message' => 'Could not load sections from OSM.',
            ]));
            return;
        }

        $savedId = (string) SettingsStore::get('financeSectionId', '');
        $savedType = (string) SettingsStore::get('financeSectionType', 'adults');
        if ($savedId === '' && is_array($_SESSION['financeSection'] ?? null)) {
            $savedId = (string) ($_SESSION['financeSection']['sectionId'] ?? '');
            $savedType = (string) ($_SESSION['financeSection']['sectionType'] ?? 'adults');
        }

        if ($savedId === '') {
            App::render('bank-transfers-select.twig', Auth::baseContext([
                'title' => 'Bank transfers',
                'sections' => $sections,
                'message' => 'Pick a finance section once under Settings (or below).',
            ]));
            return;
        }

        $transfers = [];
        $parseHint = null;
        try {
            $resolved = self::resolveAccounts($api, $token, $sections, $savedId, $savedType);
            $sectionId = $resolved['sectionId'];
            $sectionType = $resolved['sectionType'];
            $sectionName = $resolved['sectionName'];
            $accounts = $resolved['accounts'];
            $accountCount = count($accounts);
            if ($sectionId === $savedId && $sectionType !== $savedType && $sectionType !== '') {
                SettingsStore::merge(['financeSectionType' => $sectionType]);
            }
            if ($accountCount === 0) {
                $keys = array_keys($resolved['accountsRes']);
                $parseHint = "OSM returned no bank accounts for section {$sectionName} (id {$sectionId}, type {$sectionType}; keys: "
                    . implode(', ', $keys)
                    . '). Tried configured section type variants and accounts-enabled sections. Check Settings → Tool sections and that Finance/Accounts is enabled in OSM for this section.';
            }
            $today = date('Y-m-d');
            foreach ($accounts as $account) {
                $accountId = $account['bankaccountid'] ?? $account['id'] ?? null;
                if (!$accountId) {
                    continue;
                }
                $accountName = (string) ($account['name'] ?? ('Account ' . $accountId));
                try {
                    $transRes = $api->get($token, '/ext/finances/bank/', [
                        'action' => 'getTransactions',
                        'bankaccountid' => $accountId,
                        'date_from' => '2020-01-01',
                        'date_to' => $today,
                    ]);
                    OsmDebug::log('bank_trans_' . $accountId, [
                        'accountId' => $accountId,
                        'top_keys' => array_keys($transRes),
                        'body' => $transRes,
                    ]);
                    foreach (self::bankItems($transRes) as $trans) {
                        if (!is_array($trans) || ($trans['type'] ?? '') !== 'T') {
                            continue;
                        }
                        $transfers[] = [
                            'accountName' => $accountName,
                            'date' => $trans['date'] ?? 'N/A',
                            'reference' => $trans['reference'] ?? 'N/A',
                            'amount' => number_format((float) ($trans['amount'] ?? 0), 2),
                        ];
                    }
                } catch (Throwable $e) {
                    OsmDebug::log('bank_trans_error_' . $accountId, [
                        'message' => $e->getMessage(),
                        'code' => (int) $e->getCode(),
                    ]);
                    continue;
                }
            }
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $hint = $code > 0 ? " (OSM HTTP {$code})" : '';
            App::render('error.twig', Auth::baseContext([
                'title' => 'Bank transfers',
                'message' => "Could not load bank accounts for configured section id {$savedId} (type {$savedType}){$hint}. Tried live section type variants and accounts-enabled sections. Details in storage/osm-debug.json.",
            ]));
            return;
        }

        usort($transfers, static fn ($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp($a['accountName'], $b['accountName']));
        App::render('bank-transfers.twig', Auth::baseContext([
            'title' => 'Bank transfers',
            'transfers' => $transfers,
            'sectionName' => $sectionName,
            'sectionId' => $sectionId,
            'sectionType' => $sectionType,
            'accountCount' => $accountCount,
            'parseHint' => $parseHint,
            'fetchedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('d/m/y H:i'),
        ]));
    }

    public function select(): void
    {
        Auth::requireLogin();
        $sectionId = (string) ($_POST['sectionId'] ?? '');
        $sectionType = (string) ($_POST['sectionType'] ?? 'adults');
        if (str_contains($sectionId, '|')) {
            [$sectionId, $sectionType] = explode('|', $sectionId, 2);
        }
        if ($sectionId !== '') {
            SettingsStore::merge([
                'financeSectionId' => $sectionId,
                'financeSectionType' => $sectionType,
            ]);
            $_SESSION['financeSection'] = ['sectionId' => $sectionId, 'sectionType' => $sectionType];
        }
        header('Location: /bank-transfers/');
        exit;
    }
}
