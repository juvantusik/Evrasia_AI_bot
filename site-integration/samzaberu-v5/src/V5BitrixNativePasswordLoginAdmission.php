<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

use ReflectionClass;
use Throwable;

/**
 * Post-hash, password-sensitive native Bitrix login checks.
 * A null result is UNKNOWN, never permission. This does not replace current
 * site lockout/security/event admission or second-factor locked rechecking.
 */
interface V5PasswordLoginAdmissionPort
{
    public function permitsAfterProof(
        int $userId,
        #[\SensitiveParameter] string $enteredPassword,
    ): ?bool;
}

/**
 * Feature-only source-pinned candidate for installed Evrasia Bitrix.
 * Mirrors the ORIGINAL-password LoginInternal branches without invoking
 * Login/LoginInternal/Update/blockUser or establishing a PHP session.
 *
 * getPolicy and UserPasswordTable::passwordExpired use native ORM reads and
 * getPolicy has a request-local cache. Source pinning is not runtime
 * side-effect, freshness, or native transaction attestation.
 */
final class V5PinnedBitrixPasswordLoginAdmission implements V5PasswordLoginAdmissionPort
{
    private const CUSER_CLASS = 'CUser';
    private const CUSER_SUFFIX = '/bitrix/modules/main/classes/general/user.php';
    private const CUSER_SHA256 = 'bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99';

    private const AGE_CLASS = 'Bitrix\\Main\\Authentication\\Internal\\UserPasswordTable';
    private const AGE_SUFFIX = '/bitrix/modules/main/lib/Authentication/Internal/UserPasswordTable.php';
    private const AGE_SHA256 = '7991b5be14638fadc0328d22e3b0be746cda2b0305c888ce95975b3139ab2688';

    public function permitsAfterProof(
        int $userId,
        #[\SensitiveParameter] string $enteredPassword,
    ): ?bool {
        if ($userId < 1 || $userId > 2147483647
            || $enteredPassword === '' || strlen($enteredPassword) > 4096) {
            return null;
        }

        try {
            if (!self::pinnedMethods(self::CUSER_CLASS, self::CUSER_SUFFIX,
                    self::CUSER_SHA256, ['getPolicy', 'CheckPasswordAgainstPolicy'])
                || !self::pinnedMethods(self::AGE_CLASS, self::AGE_SUFFIX,
                    self::AGE_SHA256, ['passwordExpired'])) {
                return null;
            }

            $policy = \CUser::getPolicy($userId);
            if (!is_object($policy)
                || !is_callable([$policy, 'getPasswordCheckPolicy'])
                || !is_callable([$policy, 'getPasswordChangeDays'])
                || !is_callable([$policy, 'getValues'])) {
                return null;
            }

            $checkComplexity = $policy->getPasswordCheckPolicy();
            $changeDays = $policy->getPasswordChangeDays();
            if (!is_bool($checkComplexity)) {
                return null;
            }
            if (is_string($changeDays) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $changeDays) === 1) {
                $changeDays = filter_var($changeDays, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0, 'max_range' => 1000000],
                ]);
            }
            if (!is_int($changeDays) || $changeDays < 0 || $changeDays > 1000000) {
                return null;
            }

            if ($checkComplexity) {
                $values = $policy->getValues();
                if (!is_array($values)) {
                    return null;
                }
                // LoginInternal passes exactly two arguments: native password
                // history restrictions with userId are not part of this call.
                $errors = \CUser::CheckPasswordAgainstPolicy($enteredPassword, $values);
                if (!is_array($errors)) {
                    return null;
                }
                if ($errors !== []) {
                    return false;
                }
            }

            if ($changeDays > 0) {
                $expired = \Bitrix\Main\Authentication\Internal\UserPasswordTable::passwordExpired(
                    $userId, $changeDays
                );
                if (!is_bool($expired)) {
                    return null;
                }
                if ($expired) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $methods */
    private static function pinnedMethods(
        string $class,
        string $suffix,
        string $expectedSha,
        array $methods,
    ): bool {
        if (!class_exists($class)) {
            return false;
        }
        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();
        if (!is_string($file) || !str_ends_with($file, $suffix)
            || !is_file($file) || is_link($file)
            || realpath($file) !== $file
            || @hash_file('sha256', $file) !== $expectedSha) {
            return false;
        }
        foreach ($methods as $methodName) {
            if (!$reflection->hasMethod($methodName)) {
                return false;
            }
            $method = $reflection->getMethod($methodName);
            if (!$method->isPublic() || !$method->isStatic()
                || $method->getDeclaringClass()->getName() !== $reflection->getName()
                || $method->getFileName() !== $file) {
                return false;
            }
        }
        return true;
    }
}
