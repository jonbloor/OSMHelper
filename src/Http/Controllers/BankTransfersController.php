<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Http\Auth;
use App\Osm\OsmApi;
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
        $finance = $_SESSION['financeSection'] ?? null;
        if (!is_array($finance)) {
            $finance = null;
            foreach ($sections as $sec) {
                if (!is_array($sec) || empty($sec['section_id'])) continue;
                $up = $sec['upgrades'] ?? [];
                if (!is_array($up) || empty($up['accounts'])) continue;
                $sectionId = $sec['section_id'];
                $sectionType = $sec['section_type'] ?? 'adults';
                try {
                    $api->get($token, '/ext/finances/bank/', [
                        'action' => 'getBankAccounts', 'section' => $sectionType, 'sectionid' => $sectionId,
                    ]);
                    $finance = ['sectionId' => $sectionId, 'sectionType' => $sectionType];
                    break;
                } catch (Throwable $e) {
                    $code = (int) $e->getCode();
                    if (!in_array($code, [403, 404], true)) {
                        // keep looking on auth failures only
                    }
                }
            }
        }
        if ($finance === null) {
            App::render('bank-transfers-select.twig', Auth::baseContext([
                'title' => 'Bank transfers',
                'sections' => $sections,
            ]));
            return;
        }
        $sectionId = $finance['sectionId'];
        $sectionType = $finance['sectionType'] ?? 'adults';
        $transfers = [];
        try {
            $accountsRes = $api->get($token, '/ext/finances/bank/', [
                'action' => 'getBankAccounts', 'section' => $sectionType, 'sectionid' => $sectionId,
            ]);
            $accounts = $accountsRes['items'] ?? [];
            if (!is_array($accounts)) $accounts = [];
            $today = date('Y-m-d');
            foreach ($accounts as $account) {
                if (!is_array($account) || empty($account['bankaccountid'])) continue;
                $accountId = $account['bankaccountid'];
                $accountName = $account['name'] ?? ('Account ' . $accountId);
                try {
                    $transRes = $api->get($token, '/ext/finances/bank/', [
                        'action' => 'getTransactions',
                        'bankaccountid' => $accountId,
                        'date_from' => '2020-01-01',
                        'date_to' => $today,
                    ]);
                    $items = $transRes['items'] ?? [];
                    if (!is_array($items)) $items = [];
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
            App::render('error.twig', Auth::baseContext(['title' => 'Bank transfers', 'message' => 'Could not load bank accounts.']));
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
        $sectionId = $_POST['sectionId'] ?? '';
        $sectionType = $_POST['sectionType'] ?? 'adults';
        if ($sectionId !== '') {
            $_SESSION['financeSection'] = ['sectionId' => $sectionId, 'sectionType' => $sectionType];
        }
        header('Location: /bank-transfers/');
        exit;
    }
}
