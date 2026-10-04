<?php
declare(strict_types=1);
namespace App\Waiting;
use App\Osm\OsmApi;
use App\Osm\OsmOAuth;
use App\Store\WordpressSiteKeyStore;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Throwable;
/**
 * Pass-through write: validated WordPress waiting-list payload → OSM member create.
 * Does not keep child/parent data after the OSM calls finish.
 */
final class WaitingListIntake
{
    /**
     * @param array<string, mixed> $payload member / member_details / contact1 / contact2
     * @return array{scoutid: int}
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

        try {
            return self::createMember($api, $token, $sectionId, $payload, (int) $row['id']);
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
     * @return array{scoutid: int}
     */
    private static function createMember(OsmApi $api, string $token, string $sectionId, array $payload, int $siteKeyId): array
    {
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

        try {
            $created = $api->post($token, '/ext/members/contact/actions/?action=newMember', $body);
        } catch (Throwable $e) {
            self::rethrowOsm($e, $siteKeyId);
        }

        $scoutid = isset($created['scoutid']) ? (int) $created['scoutid'] : 0;
        if ((!isset($created['result']) || $created['result'] !== 'ok') && $scoutid <= 0) {
            throw new WaitingListIntakeException('OSM did not confirm member creation.', 502);
        }
        if ($scoutid <= 0) {
            throw new WaitingListIntakeException('OSM did not return a member ID.', 502);
        }

        $memberDetails = $payload['member_details'] ?? [];
        if (is_array($memberDetails) && $memberDetails !== []) {
            self::updateContact($api, $token, $sectionId, $scoutid, 6, $memberDetails, $siteKeyId);
        }

        $contact1 = $payload['contact1'] ?? [];
        if (is_array($contact1) && $contact1 !== []) {
            self::updateContact($api, $token, $sectionId, $scoutid, 1, $contact1, $siteKeyId);
        }

        $contact2 = $payload['contact2'] ?? null;
        if (is_array($contact2) && $contact2 !== []) {
            self::updateContact($api, $token, $sectionId, $scoutid, 2, $contact2, $siteKeyId);
        }

        return ['scoutid' => $scoutid];
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
        int $siteKeyId
    ): void {
        $body = [
            'associated_type' => 'member',
            'associated_id' => (string) $scoutid,
            'group_id' => (string) $groupId,
            'context' => 'members',
            'sectionid' => $sectionId,
        ];
        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $body['data[' . $key . ']'] = $value;
        }
        try {
            $api->post($token, '/ext/members/contact/?action=update', $body);
        } catch (Throwable $e) {
            self::rethrowOsm($e, $siteKeyId);
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
