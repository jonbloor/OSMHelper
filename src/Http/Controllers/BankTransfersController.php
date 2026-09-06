<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Http\Auth;
use App\Osm\OsmApi;
use App\Osm\OsmLists;
use App\Store\SettingsStore;
use Throwable;
final class BankTransfersController
{
    /** @param array<string, mixed> $res @return list<array<string, mixed>> */
    private static function bankAccounts(array $res): array
    {
        // Node: accountsResponse.data.items
        if (isset($res['items']) && is_array($res['items'])) {
            $items = $res['items'];
            if (!array_is_list($items)) {
                $items = array_values(array_filter($items, 'is_array'));
            }
            return array_values(array_filter($items, 'is_array'));
        }
        if (isset($res['data']['items']) && is_array($res['data']['items'])) {
            $items = $res['data']['items'];
            if (!array_is_list($items)) {
                $items = array_values(array_filter($items, 'is_array'));
            }
            return array_values(array_filter($items, 'is_array'));
        }
        return OsmLists::items($res);
    }

    /** @param array<string, mixed> $res @return list<array<string, mixed>> */
    private static function bankTransactions(array $res): array
    {
        // Node: transResponse.data.items
        return self::bankAccounts($res);
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
        $sectionName = '';
        $finance = null;

        if ($savedId !== '') {
            $sectionType = $savedType !== '' ? $savedType : 'adults';
            foreach ($sections as $s) {
                if (is_array($s) && (string) ($s['section_id'] ?? '') === $savedId) {
                    $sectionType = (string) ($s['section_type'] ?? $sectionType);
                    $sectionName = (string) ($s['section_name'] ?? '');
                    break;
                }
            }
            $finance = ['sectionId' => $savedId, 'sectionType' => $sectionType];
        } elseif (is_array($_SESSION['financeSection'] ?? null)) {
            $finance = $_SESSION['financeSection'];
        }

        if ($finance === null) {
            App::render('bank-transfers-select.twig', Auth::baseContext([
                'title' => 'Bank transfers',
                'sections' => $sections,
                'message' => 'Pick a finance section once under Settings (or below).',
            ]));
            return;
        }

        $sectionId = (string) $finance['sectionId'];
        $sectionType = (string) ($finance['sectionType'] ?? 'adults');
        if ($sectionName === '') {
            $sectionName = $sectionId;
        }
        $transfers = [];
        $accountCount = 0;
        try {
            $accountsRes = $api->get($token, '/ext/finances/bank/', [
                'action' => 'getBankAccounts',
                'section' => $sectionType,
                'sectionid' => $sectionId,
            ]);
            $accounts = self::bankAccounts($accountsRes);
            $accountCount = count($accounts);
            $today = date('Y-m-d');
            foreach ($accounts as $account) {
                if (!is_array($account)) continue;
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
                    foreach (self::bankTransactions($transRes) as $trans) {
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
                'message' => "Could not load bank accounts for section {$sectionName} (id {$sectionId}, type {$sectionType}){$hint}. That section may not have OSM accounts access — pick another under Settings → Tool sections.",
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
