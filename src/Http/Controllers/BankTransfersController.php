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
    public function index(): void
    {
        $token = Auth::requireLogin();
        $api = new OsmApi();
        try {
            $sections = $api->getDynamicSections($token);
        } catch (Throwable) {
            App::render('error.twig', Auth::baseContext(['title' => 'Bank transfers', 'message' => 'Could not load sections.']));
            return;
        }

        $savedId = (string) SettingsStore::get('financeSectionId', '');
        $savedType = (string) SettingsStore::get('financeSectionType', 'adults');
        $finance = null;
        if ($savedId !== '') {
            $finance = ['sectionId' => $savedId, 'sectionType' => $savedType !== '' ? $savedType : 'adults'];
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

        $sectionId = $finance['sectionId'];
        $sectionType = $finance['sectionType'] ?? 'adults';
        $transfers = [];
        try {
            $accountsRes = $api->get($token, '/ext/finances/bank/', [
                'action' => 'getBankAccounts',
                'section' => $sectionType,
                'sectionid' => $sectionId,
            ]);
            $accounts = OsmLists::items($accountsRes);
            if ($accounts === [] && isset($accountsRes['items']) && is_array($accountsRes['items'])) {
                $accounts = array_values(array_filter($accountsRes['items'], 'is_array'));
            }
            $today = date('Y-m-d');
            foreach ($accounts as $account) {
                if (!is_array($account)) continue;
                $accountId = $account['bankaccountid'] ?? $account['id'] ?? null;
                if (!$accountId) continue;
                $accountName = $account['name'] ?? ('Account ' . $accountId);
                try {
                    $transRes = $api->get($token, '/ext/finances/bank/', [
                        'action' => 'getTransactions',
                        'bankaccountid' => $accountId,
                        'date_from' => '2020-01-01',
                        'date_to' => $today,
                    ]);
                    $items = OsmLists::items($transRes);
                    foreach ($items as $trans) {
                        if (!is_array($trans) || ($trans['type'] ?? '') !== 'T') continue;
                        $transfers[] = [
                            'accountName' => $accountName,
                            'date' => $trans['date'] ?? 'N/A',
                            'reference' => $trans['reference'] ?? 'N/A',
                            'amount' => number_format((float) ($trans['amount'] ?? 0), 2),
                        ];
                    }
                } catch (Throwable) { continue; }
            }
        } catch (Throwable) {
            App::render('error.twig', Auth::baseContext([
                'title' => 'Bank transfers',
                'message' => 'Could not load bank accounts for the configured section. Check Settings.',
            ]));
            return;
        }
        usort($transfers, static fn ($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp($a['accountName'], $b['accountName']));
        App::render('bank-transfers.twig', Auth::baseContext([
            'title' => 'Bank transfers',
            'transfers' => $transfers,
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
