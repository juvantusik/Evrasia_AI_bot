<?php
declare(strict_types=1);

namespace Evrasia\WebMfa;

require_once __DIR__ . '/VerifiedEmailStatus.php';
require_once __DIR__ . '/EmailOwnershipEnrollment.php';
require_once __DIR__ . '/EmailSendBudget.php';
require_once __DIR__ . '/EmailOwnershipMailTransport.php';

/**
 * Small, testable web account OTP handler. All inputs (user ID, current EMAIL,
 * current b_user.TIMESTAMP_X, current PHP session ID, verified server assurance)
 * MUST come from server-owned Bitrix state, never from POST/GET.
 *
 * Not an endpoint by itself; no direct access to global $_REQUEST and no
 * DB schema write. The separate Bitrix-facing adapter MUST check:
 * - HTTPS POST, session USER identity and check_bitrix_sessid();
 * - strong current and RECENT assurance on both request/verify actions;
 * - authenticated current b_user.EMAIL/TIMESTAMP_X (not cached arResult);
 * - Redis quota, exact pre-created mail template, and secure session lock.
 *
 * No fallback to legacy UF_CHECK_EMAIL_DATE, and no login changes.
 */
final class EmailOwnershipActionHandler
{
    /**
     * @param array<string,mixed> $session
     * @param object $redis Existing, site-approved authenticated loopback Redis
     * @param callable(array):bool $queue One-event Bitrix mail adapter
     */
    public static function send(
        array &$session,
        int $id,
        string $currentEmail,
        int $currentProfileStamp,
        string $sessionId,
        string $assuranceMethod,
        int $assuranceVerifiedAt,
        string $secret,
        object $redis,
        string $siteId,
        int $messageTemplateId,
        callable $queue,
        ?int $now = null
    ): array {
        $at = $now ?? time();
        $result = EmailOwnershipEnrollment::begin(
            $session,
            $id,
            $currentEmail,
            $currentProfileStamp,
            $sessionId,
            $assuranceMethod,
            $assuranceVerifiedAt,
            $secret,
            static fn(int $user): bool
                => EmailSendBudget::reserve($redis, $user, $at),
            static fn(string $address, string $code): bool
                => EmailOwnershipMailTransport::submit(
                    $address, $code, $siteId, $messageTemplateId, $queue
                ),
            $at
        );

        if (($result['status'] ?? '') === 'email_ownership_challenge') {
            return [
                'status' => 'sent',
                'challenge_id' => $result['challenge_id'],
                'expires_at' => $result['expires_at'],
            ];
        }

        return [
            'status' => ($result['status'] ?? '') === 'recent_verified_login_required'
                ? 'recent_login_required' : 'email_unavailable',
        ];
    }

    /**
     * Code must be removed from PHP session BEFORE a dedicated ledger write.
     * Only a server-confirmed receipt can be persisted through the injected
     * source-pinned store. On any error: never return a green state.
     *
     * @param callable(array):bool $persistProof
     * @return array{status:string}
     */
    public static function verify(
        array &$session,
        int $id,
        string $currentEmail,
        int $currentProfileStamp,
        string $sessionId,
        string $assuranceMethod,
        int $assuranceVerifiedAt,
        string $secret,
        string $challengeId,
        string $code,
        callable $persistProof,
        ?int $now = null
    ): array {
        $at = $now ?? time();
        $proof = EmailOwnershipEnrollment::verify(
            $session,
            $id,
            $currentEmail,
            $currentProfileStamp,
            $sessionId,
            $assuranceMethod,
            $assuranceVerifiedAt,
            $secret,
            $challengeId,
            $code,
            $at
        );
        if ($proof === null) {
            return ['status' => 'incorrect_or_expired'];
        }
        try {
            if ($persistProof($proof) === true) {
                return ['status' => 'verified'];
            }
        } catch (\Throwable) {
            // Never reveal DB errors / credentials / hashes to the client.
        }
        return ['status' => 'email_unavailable'];
    }
}
