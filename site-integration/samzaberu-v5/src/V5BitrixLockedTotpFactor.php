<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlChallengeConsumption.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';
require_once __DIR__ . '/V5VerifiedBitrixNativeTransactionDepth.php';
require_once __DIR__ . '/V5NativeTotpStagingIdentity.php';

use PDO;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Stateless rules for the installed Bitrix native TOTP replay ledger (PARAMS).
 * No parallel OTP secret, time code, recovery-code fallback or synthetic OTP
 * algorithm is introduced here. The native Otp::verify is the only verifier.
 *
 * The installed Bitrix TotpAlgorithm records "offset:last_used_timecode".
 * We REQUIRE a strictly increasing persisted last_used_timecode after a true
 * native Otp::verify($code, true); an optimistic true alone gives no proof.
 * This source-derived representation MUST be reconfirmed against the exact
 * installed TotpAlgorithm source in independently isolated Bitrix staging.
 */
final class V5NativeTotpReplayEvidence
{
    /** @return array{offset:int,last:int}|null */
    public static function params(mixed $value): ?array
    {
        if (!is_string($value)
            || preg_match('/\A(-?(?:0|[1-9][0-9]{0,2})):(0|[1-9][0-9]{0,9})\z/D', $value, $m) !== 1) {
            return null;
        }
        if ($m[1] === '-0') {
            return null;
        }
        $offset = (int) $m[1];
        $last = (int) $m[2];
        if ($offset < -180 || $offset > 180 || $last < 0 || $last > 2147483647) {
            return null;
        }
        return ['offset' => $offset, 'last' => $last];
    }

    public static function eligible(array $row, int $userId): bool
    {
        $id = $row['USER_ID'] ?? null;
        $validId = is_int($id) ? $id === $userId
            : (is_string($id) && ctype_digit($id)
                && $id !== '0' && $id[0] !== '0' && (string) $userId === $id);
        $secret = $row['SECRET'] ?? null;
        return $userId > 0
            && $validId
            && ($row['ACTIVE'] ?? null) === 'Y'
            && ($row['TYPE'] ?? null) === 'totp'
            && array_key_exists('DEACTIVATE_UNTIL', $row)
            && $row['DEACTIVATE_UNTIL'] === null
            && is_string($secret)
            && preg_match('/\A[a-fA-F0-9]{40}\z/D', $secret) === 1
            && self::params($row['PARAMS'] ?? null) !== null
            && self::nonnegative($row['ATTEMPTS'] ?? null);
    }

    public static function advanced(array $before, array $after, int $userId): bool
    {
        if (!self::eligible($before, $userId)
            || !self::eligible($after, $userId)) {
            return false;
        }
        $a = self::params($before['PARAMS']);
        $b = self::params($after['PARAMS']);
        return $a !== null && $b !== null
            && $b['last'] > $a['last']
            && hash_equals(strtolower($before['SECRET']), strtolower($after['SECRET']))
            && (string) $before['ATTEMPTS'] === (string) $after['ATTEMPTS'];
    }

    private static function nonnegative(mixed $number): bool
    {
        return (is_int($number) && $number >= 0)
            || (is_string($number)
                && preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D', $number) === 1);
    }
}

/**
 * Isolated STAGING candidate for the existing V5LockedChallengeNativeFactorPort.
 * This is deliberately NOT wired into the public router or any production
 * site-factory. Native OTP is checked by the existing installed Bitrix class.
 *
 * The companion eligibility provider MUST already prove current active user,
 * account lock, native MFA, challenge epoch, strong mutation-hook coverage
 * and verified phone rules. Here only TOTP can pass; SMS remains disabled.
 *
 * Caller (V5MySqlChallengeConsumption) owns the bridge transaction and its
 * SQL SAVEPOINT. Native user + OTP rows and the source-pinned ORM must use
 * precisely the SAME Bitrix Application mysqli connection. A hidden COMMIT
 * that destroys the caller savepoint is denied by the outer consumer.
 */
final class V5BitrixLockedTotpFactor implements V5LockedChallengeNativeFactorPort
{
    private const OTP_CLASS = 'Bitrix\\Security\\Mfa\\Otp';
    private const OTP_FILE = '/bitrix/modules/security/lib/Mfa/Otp.php';
    private const OTP_SHA256 = '285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c';
    private const ALGO_CLASS = 'Bitrix\\Main\\Security\\Mfa\\TotpAlgorithm';
    private const ALGO_FILE = '/bitrix/modules/main/lib/Security/Mfa/TotpAlgorithm.php';

    private ?int $eligibleUser = null;
    private ?string $nativeSessionId = null;

    public function __construct(
        private V5BitrixPdoCompatibilityBridge $bridge,
        private object $nativeConnection,
        private V5LockedChallengeNativeFactorPort $eligibility,
        private V5NativeSecurityHookCoveragePort $coverage,
        private V5VerifiedNativeTransactionDepthPort $nativeDepth,
        private string $reviewedAlgorithmSha256,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $reviewedAlgorithmSha256) !== 1) {
            throw new RuntimeException('v5_native_totp_algorithm_source_not_reviewed');
        }
    }

    public function currentFactorEligibleWithLocks(
        PDO $db, int $userId, ChallengeKind $kind,
        string $challengeAuthEpoch, ?string $verifiedPhoneStamp
    ): bool {
        $this->eligibleUser = null;
        $this->nativeSessionId = null;
        if ($kind !== ChallengeKind::TOTP
            || $verifiedPhoneStamp !== null
            || $userId < 1 || $userId > 2147483647
            || preg_match('/\A[a-f0-9]{64}\z/D', $challengeAuthEpoch) !== 1
            || !$this->attestedScope($db)
            || $this->coverage->fullyCovered($db, $userId) !== true
            || $this->eligibility->currentFactorEligibleWithLocks(
                $db, $userId, $kind, $challengeAuthEpoch, null
            ) !== true) {
            return false;
        }

        $row = $this->lockedMfa($userId);
        if ($row === null || !V5NativeTotpReplayEvidence::eligible($row, $userId)
            || !$this->attestedScope($db)) {
            return false;
        }

        $this->eligibleUser = $userId;
        return true;
    }

    public function consumeNativeTotpWithLocks(
        PDO $db, int $userId, #[\SensitiveParameter] string $code
    ): bool {
        if ($this->eligibleUser !== $userId
            || preg_match('/\A[0-9]{6}\z/D', $code) !== 1
            || !$this->attestedScope($db)) {
            return false;
        }
        // A second call, even within the same request, must re-earn eligibility.
        $this->eligibleUser = null;

        try {
            $before = $this->lockedMfa($userId);
            if ($before === null || !V5NativeTotpReplayEvidence::eligible($before, $userId)) {
                return false;
            }

            // getByUser() must represent exactly the currently locked native
            // record. Cached or re-enrolled MFA material is denied.
            $otp = \Bitrix\Security\Mfa\Otp::getByUser($userId);
            if (get_class($otp) !== self::OTP_CLASS
                || $otp->getUserId() !== $userId
                || $otp->getType() !== 'totp'
                || !$otp->isActivated()
                || !$otp->isInitialized()
                || $otp->isAttemptsReached()
                || !hash_equals(strtolower($before['SECRET']), strtolower((string)$otp->getHexSecret()))
                || !hash_equals($before['PARAMS'], (string)$otp->getParams())
                || !$this->attestedScope($db)) {
                return false;
            }

            // This is the actual Bitrix-native TOTP algorithm + replay update,
            // NOT verification against our own stored copies of OTP secrets.
            $valid = $otp->verify($code, true) === true;

            if (!$this->attestedScope($db)) {
                throw new RuntimeException('v5_native_totp_transaction_changed');
            }
            $after = $this->lockedMfa($userId);
            if ($after === null) {
                throw new RuntimeException('v5_native_totp_row_disappeared');
            }
            if (!$valid) {
                // A bad code must never mutate replay state invisibly.
                if ($before !== $after) {
                    throw new RuntimeException('v5_native_totp_invalid_code_mutated_state');
                }
                return false;
            }
            if (!V5NativeTotpReplayEvidence::advanced($before, $after, $userId)
                || !hash_equals((string)$after['PARAMS'], (string)$otp->getParams())
                || !$this->attestedScope($db)) {
                throw new RuntimeException('v5_native_totp_replay_advance_unproven');
            }
            return true;
        } catch (Throwable) {
            // Outer V5 challenge consumer MUST ROLLBACK all native and V5
            // changes, never issue a consumed grant or a JWT on uncertainty.
            throw new RuntimeException('v5_native_totp_verification_unavailable');
        }
    }

    private function attestedScope(PDO $db): bool
    {
        if ($db !== $this->bridge || !($db instanceof V5BitrixPdoCompatibilityBridge)
            || PHP_SAPI !== 'cli'
            || getenv('V5_STAGE_WRITE_ACK') !== 'ISOLATED_STAGING_ONLY'
            || !$this->bridge->ownsCurrentNativeTransaction($this->nativeConnection)
            || !class_exists(\Bitrix\Main\Application::class)
            || \Bitrix\Main\Application::getConnection() !== $this->nativeConnection
            || get_class($this->nativeConnection) !== 'Bitrix\\Main\\DB\\MysqliConnection') {
            return false;
        }

        // A staging ACK and a non-production PHP hostname cannot prove that
        // the active MySQL session does not actually belong to production.
        // Require a canonical, explicitly selected staging Bitrix docroot and
        // a dedicated evrasia_v5_test_* schema on the exact native connection.
        $stageRoot = getenv('V5_STAGE_BITRIX_ROOT');
        $schema = getenv('V5_STAGE_DB_NAME');
        $dbHost = getenv('V5_STAGE_DB_HOSTNAME');
        if (!V5NativeTotpStagingIdentity::approved(
                $stageRoot, $_SERVER['DOCUMENT_ROOT'] ?? null,
                $schema, $dbHost, gethostname()
            )
            || !is_dir($stageRoot) || is_link($stageRoot)
            || realpath($stageRoot) !== $stageRoot
            || $this->nativeDepth->depthFor($this->nativeConnection) !== 1
            || !$this->sourceMatches(self::OTP_CLASS, self::OTP_FILE, self::OTP_SHA256, $stageRoot)
            || !$this->sourceMatches(
                self::ALGO_CLASS, self::ALGO_FILE, $this->reviewedAlgorithmSha256, $stageRoot
            )) {
            return false;
        }

        try {
            $query = $this->nativeConnection->query(
                'SELECT DATABASE() AS schema_name, @@hostname AS server_hostname, '
                . 'CONNECTION_ID() AS connection_id, '
                . '@@session.autocommit AS autocommit_value, '
                . '@@session.in_transaction AS in_transaction_value'
            );
            if (!is_object($query) || !method_exists($query, 'fetch')) {
                return false;
            }
            $row = $query->fetch();
            if (!is_array($row) || $query->fetch() !== false) {
                return false;
            }
            $id = V5NativeTotpStagingIdentity::verifiedNativeSession(
                $row, $schema, $dbHost
            );
            if ($id === null || ($this->nativeSessionId !== null
                && !hash_equals($this->nativeSessionId, $id))) {
                return false;
            }
            $this->nativeSessionId = $id;
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function sourceMatches(string $name, string $suffix, string $hash, string $stageRoot): bool
    {
        try {
            if (!class_exists($name)) {
                return false;
            }
            $reflection = new ReflectionClass($name);
            $file = $reflection->getFileName();
            return $reflection->getName() === $name
                && is_string($file) && $file === $stageRoot . $suffix
                && is_file($file) && !is_link($file)
                && realpath($file) === $file
                && @hash_file('sha256', $file) === $hash;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    private function lockedMfa(int $userId): ?array
    {
        // The ID is a previously range-checked int. There is no client SQL.
        $result = $this->nativeConnection->query(
            'SELECT USER_ID,ACTIVE,TYPE,SECRET,PARAMS,ATTEMPTS,DEACTIVATE_UNTIL '
            . 'FROM b_sec_user WHERE USER_ID = ' . $userId . ' LIMIT 2 FOR UPDATE'
        );
        if (!is_object($result) || !method_exists($result, 'fetch')) {
            throw new RuntimeException('v5_native_totp_locked_mfa_query_unavailable');
        }
        $first = $result->fetch();
        if ($first === false || $first === null) {
            return null;
        }
        if (!is_array($first) || $result->fetch() !== false) {
            throw new RuntimeException('v5_native_totp_locked_row_ambiguous');
        }
        return $first;
    }
}
