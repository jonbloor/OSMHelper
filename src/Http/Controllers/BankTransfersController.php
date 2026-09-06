<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Http\Auth;
use App\Osm\OsmApi;
use App\Osm\OsmDebug;
use App\Store\SettingsStore;
use Throwable;

/**
 * Bank reads via OSM v3 accounting (Jon Network capture 2026-09-06):
 * - GET /v3/finances/accounting/bank_accounts/section/{sectionid}
 * - GET /v3/finances/accounting/bank_accounts/{id}/transactions?page=&per_page=&expense_cardholder_id=0&mode=all
 * Amounts are pence (÷100 for £). No write APIs.
 */
final class BankTransfersController
{
    private const PER_PAGE = 25;
    private const MAX_PAGES = 4; // rate-limit friendly cap when auto-paging

    /** @param array<string, mixed> $res @return list<array<string, mixed>> */
    private static function asList(array $res): array
    {
        $candidates = [
            $res['data'] ?? null,
            $res['data']['data'] ?? null,
            $res['data']['accounts'] ?? null,
            $res['accounts'] ?? null,
            $res['items'] ?? null,
            $res['data']['items'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (!is_array($c) || $c === []) {
                continue;
            }
            if (!array_is_list($c)) {
                $c = array_values(array_filter($c, 'is_array'));
            }
            if ($c !== []) {
                return $c;
            }
        }
        return [];
    }

    /** Format OSM pence (signed int/float/string) as £ display. */
    private static function penceToPounds(mixed $pence): string
    {
        if ($pence === null || $pence === '') {
            return '';
        }
        $n = is_numeric($pence) ? (float) $pence : 0.0;
        $pounds = $n / 100.0;
        $sign = $pounds < 0 ? '-' : '';
        return $sign . '£' . number_format(abs($pounds), 2);
    }

    /** @return array{sectionId: string, sectionType: string, sectionName: string} */
    private static function resolveSection(array $sections, string $savedId, string $savedType): array
    {
        $sectionType = $savedType !== '' ? $savedType : 'adults';
        $sectionName = $savedId;
        foreach ($sections as $s) {
            if (is_array($s) && (string) ($s['section_id'] ?? '') === $savedId) {
                $sectionType = (string) ($s['section_type'] ?? $sectionType);
                $sectionName = (string) ($s['section_name'] ?? $sectionName);
                break;
            }
        }
        return [
            'sectionId' => $savedId,
            'sectionType' => $sectionType,
            'sectionName' => $sectionName,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function loadAccounts(OsmApi $api, string $token, string $sectionId): array
    {
        $path = '/v3/finances/accounting/bank_accounts/section/' . rawurlencode($sectionId);
        $res = $api->get($token, $path);
        OsmDebug::log('bank_v3_accounts_' . $sectionId, [
            'sectionId' => $sectionId,
            'path' => $path,
            'top_keys' => array_keys($res),
            'body' => $res,
        ]);
        $rows = self::asList($res);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $deleted = $row['deleted_at'] ?? null;
            $isDeleted = $deleted !== null && $deleted !== '' && $deleted !== false;
            $out[] = [
                'id' => (string) $row['id'],
                'name' => (string) ($row['name'] ?? ('Account ' . $row['id'])),
                'sectionId' => (string) ($row['section_id'] ?? $sectionId),
                'currentBalancePence' => $row['current_balance'] ?? 0,
                'currentBalance' => self::penceToPounds($row['current_balance'] ?? 0),
                'openingBalance' => self::penceToPounds($row['opening_balance'] ?? null),
                'openingDate' => (string) ($row['opening_date'] ?? ''),
                'firstTransactionDate' => (string) ($row['first_transaction_date'] ?? ''),
                'lastTransactionDate' => (string) ($row['last_transaction_date'] ?? ''),
                'unprocessed' => (int) ($row['number_unprocessed_transactions'] ?? 0),
                'deleted' => $isDeleted,
                'deletedAt' => $isDeleted ? (string) $deleted : '',
                'expenseAccount' => !empty($row['expense_account']) || !empty($row['stripe_id']) || stripos((string) ($row['name'] ?? ''), 'expense') !== false,
            ];
        }
        return $out;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int|null, page: int, pagesFetched: int}
     */
    private static function loadTransactions(OsmApi $api, string $token, string $accountId, int $page, bool $autoPage): array
    {
        $all = [];
        $total = null;
        $pagesFetched = 0;
        $start = max(1, $page);
        $end = $autoPage ? ($start + self::MAX_PAGES - 1) : $start;

        for ($p = $start; $p <= $end; $p++) {
            if ($pagesFetched > 0) {
                usleep(150000);
            }
            $path = '/v3/finances/accounting/bank_accounts/' . rawurlencode($accountId) . '/transactions';
            $res = $api->get($token, $path, [
                'page' => $p,
                'per_page' => self::PER_PAGE,
                'expense_cardholder_id' => 0,
                'mode' => 'all',
            ]);
            OsmDebug::log('bank_v3_trans_' . $accountId . '_p' . $p, [
                'accountId' => $accountId,
                'page' => $p,
                'top_keys' => array_keys($res),
                'meta' => $res['meta'] ?? null,
                'body' => $res,
            ]);
            $pagesFetched++;
            if ($total === null && isset($res['meta']['number_transactions']) && is_numeric($res['meta']['number_transactions'])) {
                $total = (int) $res['meta']['number_transactions'];
            }
            $chunk = self::asList($res);
            if ($chunk === []) {
                break;
            }
            foreach ($chunk as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $isTransfer = !empty($row['is_transfer']);
                $tx = is_array($row['transaction'] ?? null) ? $row['transaction'] : null;
                $type = $isTransfer ? 'Transfer' : (string) ($tx['type'] ?? '');
                if ($type !== '') {
                    $type = ucfirst(strtolower($type));
                }
                $desc = $tx !== null ? (string) ($tx['description'] ?? '') : '';
                $all[] = [
                    'id' => (string) ($row['id'] ?? ''),
                    'date' => (string) ($row['date'] ?? ''),
                    'reference' => (string) ($row['reference'] ?? ''),
                    'amountPence' => $row['amount'] ?? 0,
                    'amount' => self::penceToPounds($row['amount'] ?? 0),
                    'type' => $type !== '' ? $type : ($isTransfer ? 'Transfer' : '—'),
                    'description' => $desc,
                    'isTransfer' => $isTransfer,
                ];
            }
            if (count($chunk) < self::PER_PAGE) {
                break;
            }
            if ($total !== null && count($all) >= $total) {
                break;
            }
            if (!$autoPage) {
                break;
            }
        }

        return [
            'rows' => $all,
            'total' => $total,
            'page' => $start,
            'pagesFetched' => $pagesFetched,
        ];
    }

    public function index(): void
    {
        $token = Auth::requireLogin();
        $api = new OsmApi();
        try {
            $sections = $api->getDynamicSections($token);
        } catch (Throwable) {
            App::render('error.twig', Auth::baseContext([
                'title' => 'Bank',
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
                'title' => 'Bank',
                'sections' => $sections,
                'message' => 'Pick a Bank / finance section under Settings (or below).',
            ]));
            return;
        }

        $resolved = self::resolveSection($sections, $savedId, $savedType);
        $sectionId = $resolved['sectionId'];
        $sectionType = $resolved['sectionType'];
        $sectionName = $resolved['sectionName'];
        $showDeleted = isset($_GET['deleted']) && (string) $_GET['deleted'] === '1';
        $accountId = isset($_GET['account']) ? trim((string) $_GET['account']) : '';
        $page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

        try {
            $accounts = self::loadAccounts($api, $token, $sectionId);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $hint = $code > 0 ? " (OSM HTTP {$code})" : '';
            OsmDebug::log('bank_v3_accounts_error', [
                'sectionId' => $sectionId,
                'message' => $e->getMessage(),
                'code' => $code,
            ]);
            App::render('error.twig', Auth::baseContext([
                'title' => 'Bank',
                'message' => "Could not load bank accounts for section {$sectionName} (id {$sectionId}){$hint}: " . $e->getMessage(),
            ]));
            return;
        }

        $active = array_values(array_filter($accounts, static fn ($a) => empty($a['deleted'])));
        $deleted = array_values(array_filter($accounts, static fn ($a) => !empty($a['deleted'])));
        $visible = $showDeleted ? $accounts : $active;

        // Account detail + transactions
        if ($accountId !== '') {
            $account = null;
            foreach ($accounts as $a) {
                if ($a['id'] === $accountId) {
                    $account = $a;
                    break;
                }
            }
            if ($account === null) {
                App::render('error.twig', Auth::baseContext([
                    'title' => 'Bank',
                    'message' => "Bank account {$accountId} was not found in section {$sectionId}.",
                ]));
                return;
            }
            try {
                $tx = self::loadTransactions($api, $token, $accountId, $page, $page === 1);
            } catch (Throwable $e) {
                $code = (int) $e->getCode();
                OsmDebug::log('bank_v3_trans_error_' . $accountId, [
                    'message' => $e->getMessage(),
                    'code' => $code,
                ]);
                App::render('error.twig', Auth::baseContext([
                    'title' => 'Bank',
                    'message' => 'Could not load transactions for ' . $account['name'] . ': ' . $e->getMessage(),
                ]));
                return;
            }
            App::render('bank-transfers.twig', Auth::baseContext([
                'title' => 'Bank — ' . $account['name'],
                'view' => 'transactions',
                'sectionName' => $sectionName,
                'sectionId' => $sectionId,
                'sectionType' => $sectionType,
                'account' => $account,
                'transactions' => $tx['rows'],
                'txTotal' => $tx['total'],
                'txPage' => $tx['page'],
                'txPerPage' => self::PER_PAGE,
                'txPagesFetched' => $tx['pagesFetched'],
                'showDeleted' => $showDeleted,
                'fetchedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('d/m/y H:i'),
            ]));
            return;
        }

        App::render('bank-transfers.twig', Auth::baseContext([
            'title' => 'Bank',
            'view' => 'accounts',
            'sectionName' => $sectionName,
            'sectionId' => $sectionId,
            'sectionType' => $sectionType,
            'accounts' => $visible,
            'activeCount' => count($active),
            'deletedCount' => count($deleted),
            'showDeleted' => $showDeleted,
            'fetchedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('d/m/y H:i'),
        ]));
    }


    /**
     * All transfer lines across active accounts (read-only), date order, with account name.
     * Helps spot missing transfer legs / balance issues.
     */
    public function allTransfers(): void
    {
        $token = Auth::requireLogin();
        $api = new OsmApi();
        try {
            $sections = $api->getDynamicSections($token);
        } catch (Throwable) {
            App::render('error.twig', Auth::baseContext([
                'title' => 'All transfers',
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
                'title' => 'All transfers',
                'sections' => $sections,
                'message' => 'Pick a Bank / finance section under Settings (or below) before viewing transfers.',
            ]));
            return;
        }

        $resolved = self::resolveSection($sections, $savedId, $savedType);
        $sectionId = $resolved['sectionId'];
        $sectionType = $resolved['sectionType'];
        $sectionName = $resolved['sectionName'];

        try {
            $accounts = self::loadAccounts($api, $token, $sectionId);
        } catch (Throwable $e) {
            App::render('error.twig', Auth::baseContext([
                'title' => 'All transfers',
                'message' => 'Could not load bank accounts: ' . $e->getMessage(),
            ]));
            return;
        }

        $active = array_values(array_filter($accounts, static fn ($a) => empty($a['deleted'])));
        $transfers = [];
        $errors = [];
        foreach ($active as $i => $account) {
            if ($i > 0) {
                usleep(150000);
            }
            try {
                $tx = self::loadTransactions($api, $token, $account['id'], 1, true);
                foreach ($tx['rows'] as $row) {
                    if (empty($row['isTransfer'])) {
                        continue;
                    }
                    $transfers[] = [
                        'date' => $row['date'],
                        'accountName' => $account['name'],
                        'accountId' => $account['id'],
                        'reference' => $row['reference'],
                        'amount' => $row['amount'],
                        'description' => $row['description'],
                        'type' => $row['type'],
                    ];
                }
            } catch (Throwable $e) {
                $errors[] = $account['name'] . ': ' . $e->getMessage();
                OsmDebug::log('bank_v3_all_trans_error_' . $account['id'], [
                    'message' => $e->getMessage(),
                    'code' => (int) $e->getCode(),
                ]);
            }
        }

        usort($transfers, static function ($a, $b) {
            return strcmp($b['date'], $a['date']) ?: strcmp($a['accountName'], $b['accountName']);
        });

        App::render('bank-all-transfers.twig', Auth::baseContext([
            'title' => 'All transfers',
            'sectionName' => $sectionName,
            'sectionId' => $sectionId,
            'sectionType' => $sectionType,
            'transfers' => $transfers,
            'accountCount' => count($active),
            'errors' => $errors,
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
