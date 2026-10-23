<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5NativeTotpStatus.php';
require_once __DIR__ . '/V5NoMfaFallbackPolicy.php';

use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Native Bitrix MFA code source attestation. The exact sources below were
 * inspected read-only on the installed Evrasia server, not inferred from an
 * upstream Bitrix version number or a client-supplied property.
 *
 * Source SHA protects WHICH implementation we invoke; it does not prove
 * per-user mandatory-group rights or the entire ten-path AUTH_EPOCH coverage.
 * Review the actual installed implementation again after any Bitrix upgrade.
 */
final class V5PinnedBitrixMfaSources
{
    private const SOURCE_MAP = [
        'Bitrix\Security\Mfa\Otp' => [
            '/bitrix/modules/security/lib/Mfa/Otp.php',
            '285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c',
        ],
        'Bitrix\Security\Mfa\UserTable' => [
            '/bitrix/modules/security/lib/Mfa/UserTable.php',
            'cf3979c6bed5684bacc758656c55431a60cefe2e356b1b2a4e63fb323a283a64',
        ],
    ];

    public static function assertInstalled(string $class): void
    {
        if (!isset(self::SOURCE_MAP[$class]) || !class_exists($class)) {
            throw new RuntimeException('v5_native_mfa_source_unavailable');
        }
        [$suffix, $sha] = self::SOURCE_MAP[$class];
        try {
            $file = (new ReflectionClass($class))->getFileName();
            if (!is_string($file) || !str_ends_with($file, $suffix)
                || !is_file($file) || is_link($file)
                || realpath($file) !== $file
                || @hash_file('sha256', $file) !== $sha) {
                throw new RuntimeException('v5_native_mfa_source_changed');
            }
        } catch (Throwable) {
            throw new RuntimeException('v5_native_mfa_source_unavailable');
        }
    }
}

/**
 * Exactly the native Bitrix b_sec_user ORM reader, only after verifying its
 * installed UserTable.php source. A missing/changed source is NOT "no MFA".
 */
final class V5PinnedBitrixMfaRowSource implements V5MfaAccountRowSourcePort
{
    public function findCurrentRow(int $userId): ?array
    {
        if ($userId <= 0) {
            throw new RuntimeException('v5_native_mfa_user_unavailable');
        }
        V5PinnedBitrixMfaSources::assertInstalled('Bitrix\Security\Mfa\UserTable');
        return (new V5NativeBitrixMfaRowSource())->findCurrentRow($userId);
    }
}

/**
 * Native global OTP feature/mandatory flags with independently checked
 * Bitrix Otp.php. This is NOT a per-user canSkipMandatory() proof.
 * Any unknown source or runtime option returns null (deny SMS downgrade).
 */
final class V5PinnedBitrixGlobalOtpConfig implements V5GlobalOtpConfigPort
{
    public function read(): ?V5GlobalOtpConfig
    {
        try {
            V5PinnedBitrixMfaSources::assertInstalled('Bitrix\Security\Mfa\Otp');
            return (new V5NativeBitrixGlobalOtpConfig())->read();
        } catch (Throwable) {
            return null;
        }
    }
}
