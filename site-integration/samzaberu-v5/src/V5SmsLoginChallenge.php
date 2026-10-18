<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

use RuntimeException;

/**
 * Pure server-side SMS challenge state machine. Not a gateway, repository,
 * phone resolver or HTTP route. All mutations must be persisted atomically
 * with compare-and-swap (row lock / version check), never client session data.
 *
 * Only create this object AFTER verified password and REQUIRE_SMS policy.
 * Destination is resolved from the authenticated Bitrix account by a trusted
 * adapter and MUST NOT be accepted from the API caller.
 */
final readonly class V5SmsLoginChallenge
{
    private const TTL_SECONDS = 300;
    private const MAX_ATTEMPTS = 5;

    private function __construct(
        public string $attemptId,
        public int $userId,
        public string $codeMac,
        public int $expiresAt,
        public int $failedAttempts,
        public bool $consumed,
    ) {}

    public static function issue(
        string $attemptId,
        int $userId,
        string $code,
        string $serverSecret,
        int $now,
    ): self {
        self::validInputs($attemptId, $userId, $code, $serverSecret, $now);
        return new self(
            $attemptId, $userId,
            self::mac($attemptId, $userId, $code, $serverSecret),
            $now + self::TTL_SECONDS, 0, false
        );
    }

    /**
     * @return array{challenge:self,accepted:bool}
     * Caller MUST atomically persist returned state before considering the
     * verification result valid. On failed persistence, deny access.
     */
    public function verify(
        string $attemptId,
        int $userId,
        string $code,
        string $serverSecret,
        int $now,
    ): array {
        self::validInputs($attemptId, $userId, $code, $serverSecret, $now);
        if ($this->consumed || $now >= $this->expiresAt
            || $this->failedAttempts >= self::MAX_ATTEMPTS
            || !hash_equals($this->attemptId, $attemptId)
            || $this->userId !== $userId) {
            return ['challenge' => $this, 'accepted' => false];
        }
        $correct = hash_equals(
            $this->codeMac,
            self::mac($attemptId, $userId, $code, $serverSecret)
        );
        return [
            'challenge' => new self(
                $this->attemptId, $this->userId, $this->codeMac,
                $this->expiresAt,
                $correct ? $this->failedAttempts : $this->failedAttempts + 1,
                $correct
            ),
            'accepted' => $correct,
        ];
    }

    private static function validInputs(
        string $attemptId, int $userId, string $code,
        string $secret, int $now,
    ): void {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $attemptId) !== 1
            || $userId < 1 || $userId > 2147483647
            || preg_match('/\A[0-9]{6}\z/D', $code) !== 1
            || strlen($secret) < 32 || $now < 1
            || $now > PHP_INT_MAX - self::TTL_SECONDS) {
            throw new RuntimeException('v5_sms_challenge_invalid_server_input');
        }
    }

    private static function mac(
        string $attemptId, int $userId, string $code, string $secret,
    ): string {
        return hash_hmac('sha256',
            'evrasia-v5-sms-login' . "\0" . $attemptId . "\0"
            . $userId . "\0" . $code, $secret);
    }
}
