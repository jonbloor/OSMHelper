<?php
declare(strict_types=1);
namespace App\Waiting;
use App\Osm\OsmApi;
use App\Osm\OsmOAuth;
use App\Store\WaitingFieldMapStore;
use App\Store\WordpressSiteKeyStore;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Throwable;
/**
 * Pass-through write: validated WordPress waiting-list payload → OSM member create.
 * Does not keep child/parent data after the OSM calls finish.
 *
 * OSM write sequence (form-urlencoded, never JSON):
 *  1. POST /ext/members/contact/actions/?action=newMember  → scoutid
 *  2. POST /ext/customdata/?action=update&section_id=S     → member details (group_id 6)
 *  3. POST /ext/customdata/?action=update&section_id=S     → primary contact 1 (group_id 1)
 *  4. optional same for contact 2 (group_id 2)
 *  5. optional parent note → the waiting list's mapped Notes custom field
 *     (POST /ext/customdata/?action=updateColumn, group 5 "customisable_data", column chosen by
 *     leaders in OSM Helper → Waiting list → Rank & notes settings). Best effort: a failed or
 *     skipped note never undoes or fails the member creation; the result reports it instead.
 *
 * Contact updates use /ext/customdata/ (osm-extender + Newcastle docs), NOT
 * /ext/members/contact/?action=update (that path is for column/value member fields).
 */
final class WaitingListIntake
{
    /** UK OSM contact field aliases (NZ docs used line_*; UK UI uses address*). */
    private const FIELD_ALIASES = [
        'line_1' => 'address1',
        'line_2' => 'address2',
        'line_3' => 'address3',
        'line_4' => 'address4',
        'address' => 'address1',
        'first_name' => 'firstname',
        'last_name' => 'lastname',
    ];

    /** Longest parent note accepted from WordPress (characters, after cleaning). */
    public const PARENT_NOTE_MAX_LEN = 1000;
    /** Who the note line is attributed to in the OSM Notes history. */
    public const PARENT_NOTE_AUTHOR = 'Parent (website form)';
    /** Pause before the note write; OSM can drop close writes to the same member (see WaitingListController). */
    public const NOTE_WRITE_DELAY_US = 1200000;

    /**
     * @param array<string, mixed> $payload member / member_details / contact1 / contact2 / parent_note
     * @return array{scoutid: int, note_status: string, warnings: list<string>}
     */
    public static function submitWithSiteKey(string $siteKey, array $payload): array
    {
        $row = WordpressSiteKeyStore::findBySiteKey($siteKey);
        if ($row === null) {
            throw new WaitingListIntakeException('Invalid site key.', 401);
        }
        if (!empty($row['blocked_at'])) {
            throw new WaitingListIntakeException(
                'OSM blocked this application (X-Blocked). Sign in to OSM Helper, clear the WordPress waiting-list block in Settings after fixing the cause, then retry.',
                503
            );
        }

        $sectionId = (string) ($row['section_id'] ?? '');
        if ($sectionId === '' || !ctype_digit($sectionId)) {
            throw new WaitingListIntakeException('Waiting list section is not configured in OSM Helper.', 503);
        }

        self::assertPayload($payload);

        $api = new OsmApi();
        $token = self::accessTokenForRow($row, $api);

        $noteTarget = null;
        if (self::cleanParentNote($payload['parent_note'] ?? null) !== '') {
            $noteTarget = self::noteTargetForSection($sectionId);
        }

        try {
            return self::createMember($api, $token, $sectionId, $payload, (int) $row['id'], $noteTarget);
        } catch (WaitingListIntakeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            if ($code === 429) {
                throw new WaitingListIntakeException(
                    'OSM rate limited this request (HTTP 429). Try again later. OSM Helper does not retry automatically.',
                    429,
                    $e
                );
            }
            throw new WaitingListIntakeException(
                'Could not write the waiting-list member to OSM.',
                $code >= 400 && $code < 600 ? $code : 502,
                $e
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{group_id:string,column_id:string,label:string}|null $noteTarget mapped Notes field (null = none)
     * @return array{scoutid: int, note_status: string, warnings: list<string>}
     *   note_status: none (no note sent) | written | skipped (no Notes field mapped) | failed (OSM write failed)
     */
    public static function createMember(
        OsmApi $api,
        string $token,
        string $sectionId,
        array $payload,
        int $siteKeyId,
        ?array $noteTarget = null,
        int $noteDelayUs = self::NOTE_WRITE_DELAY_US
    ): array {
        $member = $payload['member'];
        $body = [
            'firstname' => (string) $member['firstname'],
            'lastname' => (string) $member['lastname'],
            'dob' => (string) $member['dob'],
            'started' => (string) ($member['started'] ?? gmdate('Y-m-d')),
            'startedsection' => (string) ($member['startedsection'] ?? gmdate('Y-m-d')),
            'sectionid' => $sectionId,
            'originating_section_id' => $sectionId,
        ];

        $step = 'newMember';
        try {
            $created = $api->post($token, '/ext/members/contact/actions/?action=newMember', $body);
        } catch (Throwable $e) {
            self::logStepFailure($step, $sectionId, null);
            self::rethrowOsm($e, $siteKeyId);
        }

        $scoutid = self::parseNewMemberScoutId($created);
        if ($scoutid <= 0) {
            self::logStepFailure($step . ':missing-scoutid', $sectionId, null);
            throw new WaitingListIntakeException('OSM did not return a member ID.', 502);
        }

        $memberDetails = $payload['member_details'] ?? [];
        if (is_array($memberDetails) && $memberDetails !== []) {
            $step = 'update-member-details';
            self::updateContact($api, $token, $sectionId, $scoutid, 6, $memberDetails, $siteKeyId, $step);
        }

        $contact1 = $payload['contact1'] ?? [];
        if (is_array($contact1) && $contact1 !== []) {
            $step = 'update-contact1';
            self::updateContact($api, $token, $sectionId, $scoutid, 1, $contact1, $siteKeyId, $step);
        }

        $contact2 = $payload['contact2'] ?? null;
        if (is_array($contact2) && $contact2 !== []) {
            $step = 'update-contact2';
            self::updateContact($api, $token, $sectionId, $scoutid, 2, $contact2, $siteKeyId, $step);
        }

        // The member now exists with contacts. Everything below is best effort and never throws.
        $note = self::cleanParentNote($payload['parent_note'] ?? null);
        if ($note === '') {
            return ['scoutid' => $scoutid, 'note_status' => 'none', 'warnings' => []];
        }
        $noteResult = self::writeParentNote($api, $token, $sectionId, $scoutid, $note, $noteTarget, $siteKeyId, $noteDelayUs);
        return ['scoutid' => $scoutid] + $noteResult;
    }

    /**
     * Clean a free-text parent note: plain text only (tags and control characters removed,
     * line breaks kept as spaces later by WL::noteEntry), trimmed, capped at PARENT_NOTE_MAX_LEN.
     */
    public static function cleanParentNote(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $s = str_replace(["\r\n", "\r"], "\n", $raw);
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        $s = strip_tags($s);
        // Drop control characters except newline and tab.
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        $s = trim($s);
        if (mb_strlen($s) > self::PARENT_NOTE_MAX_LEN) {
            $s = rtrim(mb_substr($s, 0, self::PARENT_NOTE_MAX_LEN));
        }
        return $s;
    }

    /**
     * Value written to the Notes field, in the same history format the Rank & notes screen uses:
     * `dd/mm/yy hh:mm - Parent (website form) - "note"` (Europe/London).
     */
    public static function composeParentNote(string $note, ?\DateTimeImmutable $now = null): string
    {
        return WaitingListService::composeNote(
            $note,
            self::PARENT_NOTE_AUTHOR,
            '',
            $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
    }

    /**
     * The waiting list's mapped Notes field (OSM Helper → Waiting list → Rank & notes settings).
     *
     * @return array{group_id:string,column_id:string,label:string}|null
     */
    public static function noteTargetForSection(string $sectionId): ?array
    {
        try {
            $map = WaitingFieldMapStore::findBySection($sectionId);
        } catch (Throwable $e) {
            error_log('OSMHelper waiting-list intake: could not load the Notes field mapping for section_id=' . $sectionId);
            return null;
        }
        if (!is_array($map) || !ctype_digit((string) ($map['notes_column_id'] ?? ''))) {
            return null;
        }
        $group = (string) ($map['field_group_id'] ?? '');
        return [
            'group_id' => ctype_digit($group) ? $group : WaitingListService::FIELD_GROUP_ID,
            'column_id' => (string) $map['notes_column_id'],
            'label' => (string) ($map['notes_label'] ?? ''),
        ];
    }

    /**
     * @param array{group_id:string,column_id:string,label:string}|null $target
     * @return array{note_status: string, warnings: list<string>}
     */
    private static function writeParentNote(
        OsmApi $api,
        string $token,
        string $sectionId,
        int $scoutid,
        string $note,
        ?array $target,
        int $siteKeyId,
        int $delayUs
    ): array {
        if ($target === null) {
            self::logStepFailure('update-parent-note:no-notes-field-mapped', $sectionId, $scoutid);
            return [
                'note_status' => 'skipped',
                'warnings' => ['The child was added, but the parent note was not saved: no Notes field is chosen for this waiting list in OSM Helper (Waiting list → Rank & notes settings).'],
            ];
        }
        if ($delayUs > 0) {
            usleep($delayUs);
        }
        try {
            $res = WaitingListService::writeColumn(
                $api,
                $token,
                $sectionId,
                (string) $scoutid,
                $target['group_id'],
                $target['column_id'],
                self::composeParentNote($note)
            );
        } catch (Throwable $e) {
            $res = ['ok' => false, 'message' => $e->getMessage()];
        }
        if (!empty($res['ok'])) {
            return ['note_status' => 'written', 'warnings' => []];
        }

        $msg = (string) ($res['message'] ?? '');
        if (str_contains($msg, 'X-Blocked') || str_contains($msg, 'OSM_BLOCKED')) {
            // Honour the block for later submissions, but this member was already created.
            try {
                WordpressSiteKeyStore::markBlocked($siteKeyId, '1');
            } catch (Throwable) {
                // Logged below; the member write already succeeded.
            }
            self::logStepFailure('update-parent-note:blocked', $sectionId, $scoutid);
        } elseif (str_contains($msg, '429')) {
            self::logStepFailure('update-parent-note:rate-limited', $sectionId, $scoutid);
        } else {
            self::logStepFailure('update-parent-note', $sectionId, $scoutid);
        }
        return [
            'note_status' => 'failed',
            'warnings' => ['The child was added, but OSM did not accept the parent note. Add it by hand in OSM if needed.'],
        ];
    }

    /**
     * Pull the new scout id from a newMember response.
     * Observed shapes: {result:"ok", scoutid:N} (top-level) or nested under data.
     *
     * @param array<string, mixed> $created
     */
    public static function parseNewMemberScoutId(array $created): int
    {
        $candidates = [
            $created['scoutid'] ?? null,
            $created['scout_id'] ?? null,
            $created['member_id'] ?? null,
            $created['id'] ?? null,
        ];
        $data = $created['data'] ?? null;
        if (is_array($data)) {
            $candidates[] = $data['scoutid'] ?? null;
            $candidates[] = $data['scout_id'] ?? null;
            $candidates[] = $data['member_id'] ?? null;
            $candidates[] = $data['id'] ?? null;
        }
        foreach ($candidates as $raw) {
            if (is_int($raw) && $raw > 0) {
                return $raw;
            }
            if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
                return (int) $raw;
            }
            if (is_float($raw) && (int) $raw > 0 && (float) (int) $raw === $raw) {
                return (int) $raw;
            }
        }
        return 0;
    }

    /**
     * Normalise contact field keys to UK OSM varnames used by data[…].
     *
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    public static function normalizeContactFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $canon = self::FIELD_ALIASES[$key] ?? $key;
            $out[$canon] = (string) $value;
        }
        return $out;
    }

    /** Path for bulk contact-detail updates (group_id 1/2/6). */
    public static function contactUpdatePath(string $sectionId): string
    {
        return '/ext/customdata/?action=update&section_id=' . rawurlencode($sectionId);
    }

    /**
     * Form body for a contact update (no PII logging — call sites only).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function buildContactUpdateForm(int $scoutid, int $groupId, array $fields): array
    {
        $body = [
            'associated_type' => 'member',
            'associated_id' => (string) $scoutid,
            'group_id' => (string) $groupId,
            'context' => 'members',
        ];
        foreach (self::normalizeContactFields($fields) as $key => $value) {
            // Literal data[key] keys match osm-extender and OSM's form parser.
            $body['data[' . $key . ']'] = $value;
        }
        return $body;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function updateContact(
        OsmApi $api,
        string $token,
        string $sectionId,
        int $scoutid,
        int $groupId,
        array $fields,
        int $siteKeyId,
        string $step
    ): void {
        $path = self::contactUpdatePath($sectionId);
        $body = self::buildContactUpdateForm($scoutid, $groupId, $fields);
        if (count($body) <= 4) {
            // Only the envelope keys — nothing to write.
            return;
        }
        try {
            $res = $api->post($token, $path, $body);
        } catch (Throwable $e) {
            self::logStepFailure($step, $sectionId, $scoutid);
            self::rethrowOsm($e, $siteKeyId);
        }
        // customdata update returns {status:true,…}; treat missing/false as failure and abort.
        if (array_key_exists('status', $res) && $res['status'] !== true) {
            self::logStepFailure($step . ':status-not-true', $sectionId, $scoutid);
            throw new WaitingListIntakeException(
                'Could not write the waiting-list member to OSM.',
                502
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function accessTokenForRow(array $row, OsmApi $api): string
    {
        $access = (string) ($row['access_token'] ?? '');
        $expires = isset($row['token_expires_at']) && is_numeric($row['token_expires_at'])
            ? (int) $row['token_expires_at']
            : 0;
        $fresh = $access !== '' && ($expires === 0 || $expires > time() + 60);
        if ($fresh) {
            return $access;
        }

        $refresh = isset($row['refresh_token']) && is_string($row['refresh_token']) ? $row['refresh_token'] : '';
        if ($refresh === '') {
            throw new WaitingListIntakeException(
                'OSM token expired. Sign in to OSM Helper and save WordPress waiting-list settings again.',
                503
            );
        }

        try {
            $oauth = new OsmOAuth();
            $token = $oauth->getProvider()->getAccessToken('refresh_token', [
                'refresh_token' => $refresh,
            ]);
        } catch (IdentityProviderException $e) {
            throw new WaitingListIntakeException(
                'Could not refresh the OSM token. Sign in to OSM Helper and save WordPress waiting-list settings again.',
                503,
                $e
            );
        } catch (Throwable $e) {
            throw new WaitingListIntakeException(
                'Could not refresh the OSM token. Sign in to OSM Helper and save WordPress waiting-list settings again.',
                503,
                $e
            );
        }

        $newAccess = $token->getToken();
        $newRefresh = $token->getRefreshToken();
        $newExpires = $token->getExpires();
        WordpressSiteKeyStore::updateTokens(
            (int) $row['id'],
            $newAccess,
            is_string($newRefresh) && $newRefresh !== '' ? $newRefresh : $refresh,
            is_int($newExpires) && $newExpires > 0 ? $newExpires : null
        );
        // Touch OsmApi so rate-limit session state stays consistent if a session exists.
        unset($api);
        return $newAccess;
    }

    /** @param array<string, mixed> $payload */
    private static function assertPayload(array $payload): void
    {
        $member = $payload['member'] ?? null;
        if (!is_array($member)
            || trim((string) ($member['firstname'] ?? '')) === ''
            || trim((string) ($member['lastname'] ?? '')) === ''
            || trim((string) ($member['dob'] ?? '')) === ''
        ) {
            throw new WaitingListIntakeException('Member payload is incomplete.', 422);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $member['dob'])) {
            throw new WaitingListIntakeException('Member date of birth must be yyyy-mm-dd.', 422);
        }

        $details = $payload['member_details'] ?? null;
        if (!is_array($details) || trim((string) ($details['postcode'] ?? '')) === '') {
            throw new WaitingListIntakeException('Postcode is required.', 422);
        }

        $c1 = $payload['contact1'] ?? null;
        if (!is_array($c1)
            || trim((string) ($c1['firstname'] ?? '')) === ''
            || trim((string) ($c1['lastname'] ?? '')) === ''
            || trim((string) ($c1['email1'] ?? '')) === ''
            || trim((string) ($c1['phone1'] ?? '')) === ''
        ) {
            throw new WaitingListIntakeException('Parent 1 details are incomplete.', 422);
        }

        $note = $payload['parent_note'] ?? null;
        if ($note !== null && !is_string($note)) {
            throw new WaitingListIntakeException('Parent note must be text.', 422);
        }

        $c2 = $payload['contact2'] ?? null;
        if ($c2 !== null) {
            if (!is_array($c2)
                || trim((string) ($c2['firstname'] ?? '')) === ''
                || trim((string) ($c2['lastname'] ?? '')) === ''
                || trim((string) ($c2['email1'] ?? '')) === ''
            ) {
                throw new WaitingListIntakeException('Parent 2 details are incomplete.', 422);
            }
        }
    }

    private static function logStepFailure(string $step, string $sectionId, ?int $scoutid): void
    {
        // Step + section + scoutid only — never child/parent names, emails, phones, or addresses.
        error_log(
            'OSMHelper waiting-list intake step failed: ' . $step
            . ' section_id=' . $sectionId
            . ' scoutid=' . ($scoutid !== null ? (string) $scoutid : 'null')
        );
    }

    private static function rethrowOsm(Throwable $e, int $siteKeyId): never
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'X-Blocked') || str_contains($msg, 'OSM_BLOCKED')) {
            WordpressSiteKeyStore::markBlocked($siteKeyId, '1');
            throw new WaitingListIntakeException(
                'OSM blocked this application (X-Blocked). Further OSM writes are stopped until a leader clears the block in OSM Helper Settings.',
                503,
                $e
            );
        }
        if ((int) $e->getCode() === 429 || str_contains($msg, 'HTTP 429')) {
            throw new WaitingListIntakeException(
                'OSM rate limited this request (HTTP 429). Try again later. OSM Helper does not retry automatically.',
                429,
                $e
            );
        }
        throw $e;
    }
}
