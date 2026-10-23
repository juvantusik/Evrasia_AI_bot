<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixPasswordProofPort.php';

use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Exact installed Bitrix password comparator, source-pinned to the previously
 * read-only verified Evrasia module. This is NOT a second password algorithm.
 *
 * A Bitrix upgrade invalidates the pin and requires review before V5 first
 * factor acceptance. Unknown/unreadable/symlinked sources fail closed.
 * Never calls CUser::Login(), makes an HTTP request or opens a session.
 */
final class V5PinnedBitrixPasswordComparator implements V5PasswordHashComparatorPort
{
    private const NATIVE_CLASS = 'Bitrix\Main\Security\Password';
    private const NATIVE_SUFFIX = '/bitrix/modules/main/lib/Security/Password.php';
    private const NATIVE_SHA256 = 'a1ffbba30510caa373087127d3af5714d36be526f5df854610f80ff227a10296';

    public function matches(
        #[\SensitiveParameter] string $storedHash,
        #[\SensitiveParameter] string $enteredPassword,
    ): bool {
        try {
            if ($storedHash === '' || strlen($storedHash) > 4096
                || $enteredPassword === '' || strlen($enteredPassword) > 4096
                || !class_exists(self::NATIVE_CLASS)
                || !is_callable([self::NATIVE_CLASS, 'equals'])) {
                throw new RuntimeException('native_password_unavailable');
            }
            $source = (new ReflectionClass(self::NATIVE_CLASS))->getFileName();
            if (!is_string($source) || !str_ends_with($source, self::NATIVE_SUFFIX)
                || !is_file($source) || is_link($source)
                || realpath($source) !== $source
                || @hash_file('sha256', $source) !== self::NATIVE_SHA256) {
                throw new RuntimeException('native_password_source_unverified');
            }
            return (new V5NativeBitrixPasswordComparator())
                ->matches($storedHash, $enteredPassword);
        } catch (Throwable) {
            // Missing or unapproved source is NOT an incorrect password
            // verdict: reject the whole request as an unavailable provider.
            throw new RuntimeException('v5_native_password_proof_unavailable');
        }
    }
}
