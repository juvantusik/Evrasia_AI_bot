<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SmsLoginChallenge.php';

use RuntimeException;
use Throwable;

/**
 * Storage boundary: implementations MUST lock the attempt row, load it,
 * calculate verify(), and durably store its replacement state in ONE atomic
 * transaction. Return true ONLY if the successful consumption was committed.
 * A failed guess must also be durably recorded before accepting more guesses.
 * The implementation must not invoke any session/token writer.
 */
interface V5AtomicSmsChallengeStore
{
    public function verifyAndConsume(
        string $attemptId, int $accountId, string $code,
        string $serverSecret, int $now,
    ): bool;
}

/**
 * Boundary for the future login handler. No direct challenge->verify()
 * result can authorize a session; only committed store consumption may.
 * Safe default is DENY; this class never issues tokens or sends SMS.
 */
final class V5SmsChallengeVerifier
{
    public function __construct(private V5AtomicSmsChallengeStore $store) {}

    public function verified(
        string $attemptId, int $userId, string $code,
        string $secret, int $now,
    ): bool {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $attemptId) !== 1
            || $userId < 1 || $userId > 2147483647
            || preg_match('/\A[0-9]{6}\z/D', $code) !== 1
            || strlen($secret) < 32 || $now < 1) {
            return false;
        }
        try {
            return $this->store->verifyAndConsume(
                $attemptId, $userId, $code, $secret, $now
            ) === true;
        } catch (Throwable) {
            return false;
        }
    }
}
