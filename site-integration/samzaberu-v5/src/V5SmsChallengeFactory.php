<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

use RuntimeException;

/**
 * Server-only cryptographic material for login SMS challenges.
 * No identifiers or codes are accepted from clients.
 */
final class V5SmsChallengeFactory
{
    /** 128-bit unpredictable login-attempt identifier. */
    public static function attemptId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Uniform six-digit decimal OTP, including leading zeros. */
    public static function code(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Long-lived secret must come from server-side secret management.
     * Never derive the MAC key from phone, password, or client input.
     */
    public static function validateServerSecret(mixed $secret): string
    {
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('v5_sms_mac_secret_missing');
        }
        return $secret;
    }
}
