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
    /** @param array<string, mixed> $res @return list<array<string, mixed>> */
    private static function bankAccounts(array $res): array
    {
        // Node: accountsResponse.data.items
        foreach ([
            $res['items'] ?? null,
            $res['data']['items'] ?? null,
            $res['data'] ?? null,
        ] as $c) {
            if (!is_array($c) || $c === []) continue;
            if (!array_is_list($c)) {
                $vals = array_values(array_filter($c, 'is_array'));
                if ($vals === []) continue;
                $c = $vals;
            }
            $out = [];
            foreach ($c as $row) {
                if (!is_array($row)) continue;
                if (isset($row['bankaccountid']) || isset($row['id']) || isset($row['name'])) {
                    $out[] = $row;
                }
            }
            if ($out !== []) return $out;
        }
        return OsmLists::items($res);
    }

    /**
     * Try getBankAccounts with type variants; on failure probe upgrades.accounts sections (Node).
     * @param list<array<string, mixed>> $sections
     * @return array{sectionId: string, sectionType: string, sectionName: string, accountsRes: array<string, mixed>}
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
            foreach (array_unique(array_filter([$liveType, $preferType, 'adults', ''])) as $type) {
                $attempts[] = ['id' => $preferId, 'type' => $type !== '' ? $type : 'adults', 'name' => $liveName];
            }
        }
        foreach ($sections as $s) {
            if (!is_array($s) || empty($s['section_id'])) continue;
            if (($s['upgrades']['accounts'] ?? false) !== true) continue;
            $id = (string) $s['section_id'];
            $type = (string) ($s['section_type'] ?? 'adults');
            $attempts[] = ['id' => $id, 'type' => $type !== '' ? $type : 'adults', 'name' => (string) ($s['section_name'] ?? $id)];
        }

        $seen = [];
        $lastError = null;
        foreach ($attempts as $a) {
            $key = $a['id'] . '|' . $a['type'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            try {
                $res = $api->get($token, '/ext/finances/bank/', [
                    'action' => 'getBankAccounts',
                    'section' => $a['type'],
                    'sectionid' => $a['id'],
                ]);
                OsmDebug::log('bank_accounts_' . $a['id'], [
                    'sectionId' => $a['id'],
                    'sectionType' => $a['type'],
                    'top_keys' => array_keys($res),
                    'body' => $res,
                ]);
                $accounts = self::bankAccounts($res);
                if ($accounts !== [] || $preferId === $a['id']) {
                    // Prefer configured section even if empty accounts (distinguish empty vs 404)
                    return [
                        'sectionId' => $a['id'],
                        'sectionType' => $a['type'],
                        'sectionName' => $a['name'],
                        'accountsRes' => $res,
                        'accounts' => $accounts,
                    ];
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
        if ($lastError) throw $lastError;
        throw new \RuntimeException('No accessible finance section found after probes.');
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
        try {
            $resolved = self::resolveAccounts($api, $token, $sections, $savedId, $savedType);
            $sectionId = $resolved['sectionId'];
            $sectionType = $resolved['sectionType'];
            $sectionName = $resolved['sectionName'];
            $accounts = $resolved['accounts'];
            $accountCount = count($accounts);
            // Persist working type if probe corrected it
            if ($sectionId === $savedId && $sectionType !== $savedType) {
                SettingsStore::merge(['financeSectionType' => $sectionType]);
            }
            $today = date('Y-m-d');
            foreach ($accounts as $account) {
                $accountId = $account['bankaccountid'] ?? $account['id'] ?? null;
                if (!$accountId) continue;
                $accountName = (string) ($account['name'] ?? ('Account ' . $accountId));
                try {
                    $transRes = $api->get($token, '/ext/finances/bank/', [
                        'action' => 'getTransactions',
                        'bankaccountid' => $accountId,
                        'date_from' => '2020-01-01',
                        'date_to' => $today,
                    ]);
                    $items = self::bankAccounts($transRes); // same items shape
                    foreach ($items as $trans) {
                        if (!is_array($trans) || ($trans['type'] ?? '') !== 'T') continue;
                        $transfers[] = [
                            'accountName' => $accountName,
                            'date' => $trans['date'] ?? 'N/A',
                            'reference' => $trans['reference'] ?? 'N/A',
                            'amount' => number_format((float) ($trans['amount'] ?? 0), 2),
                        ];
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $hint = $code > 0 ? " (OSM HTTP {$code})" : '';
            App::render('error.twig', Auth::baseContext([
                'title' => 'Bank transfers',
                'message' => "Could not load bank accounts for configured section id {$savedId}{$hint}. Tried live section type and accounts-enabled sections. Details logged for diagnosis.",
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
