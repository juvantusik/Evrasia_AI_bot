<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SmsDispatchBudget.php';
require_once __DIR__ . '/ChallengeRules.php';

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Site adapter MUST recheck current account, auth epoch, no native OTP downgrade,
 * and the verified-phone row/version while holding appropriate locks ON THIS
 * SAME PDO transaction. Return false on any unknown/denied state. This is a
 * deliberately mandatory integration port: there is no default permissive one.
 */
interface V5SmsLockedEligibilityPort
{
    public function stillEligibleWithLocks(
        PDO $db,
        V5SmsQueueReservation $request,
        string $challengeAuthEpoch,
    ): bool;
}

/** An encrypted delivery and an HMAC-only six-digit code verifier. */
final readonly class V5PreparedSmsDelivery
{
    public function __construct(
        public string $deliveryId,
        public string $encryptedEnvelope,
        public string $codeHmac,
    ) {
        if (!V5LoginWorkflow::validChallengeId($deliveryId)
            || $encryptedEnvelope === ''
            || preg_match('/\A[a-f0-9]{64}\z/D', $codeHmac) !== 1
        ) {
            throw new InvalidArgumentException('Invalid private SMS envelope');
        }
    }
}

interface V5SmsMessageFactoryPort
{
    public function prepare(V5SmsQueueReservation $reservation): V5PreparedSmsDelivery;
}

/**
 * Explicit synthetic/staging implementation. The transport key is an
 * independent 32-byte sodium key; the HMAC key is DIFFERENT and server-only.
 * A real dispatcher must read this envelope after commit, check the phone
 * verification stamp again, and never auto-retry an uncertain paid send.
 */
final readonly class V5SodiumSmsMessageFactory implements V5SmsMessageFactoryPort
{
    public function __construct(
        #[\SensitiveParameter] private string $envelopeKey,
        #[\SensitiveParameter] private string $codeHmacKey,
    ) {
        if (strlen($envelopeKey) !== 32 || strlen($codeHmacKey) < 32
            || hash_equals($envelopeKey, $codeHmacKey)
            || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
        ) {
            throw new InvalidArgumentException('V5 SMS encryption keys unavailable');
        }
    }

    public function prepare(V5SmsQueueReservation $reservation): V5PreparedSmsDelivery
    {
        $deliveryId = bin2hex(random_bytes(32));
        $code = sprintf('%06d', random_int(0, 999999));
        $payload = json_encode([
            'v' => 1,
            'phone' => $reservation->destination->e164,
            'verification_stamp' => $reservation->destination->verificationStamp,
            'text' => 'Код подтверждения Евразия: ' . $code . '. Никому его не сообщайте.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $payload, "evrasia:v5:sms-outbox:v1\0" . $deliveryId,
            $nonce, $this->envelopeKey
        );
        $codeHash = hash_hmac(
            'sha256', "evrasia:v5:sms-code:v1\0" . $reservation->challengeId
                . "\0" . $deliveryId . "\0" . $code,
            $this->codeHmacKey,
        );
        sodium_memzero($code);
        sodium_memzero($payload);
        return new V5PreparedSmsDelivery(
            $deliveryId, base64_encode($nonce . $ciphertext), $codeHash,
        );
    }
}

/**
 * STAGING-ONLY PDO/MySQL candidate, no DB connection or migration is created.
 * Requires THREE NEW InnoDB tables defined in proposed V5 SMS schema file.
 * No real account-reader, Bitrix-MFA or SMS-provider implementation is wired.
 *
 * Lock order: challenge -> locked site eligibility -> sorted quota keys.
 * A denied/exhausted budget rolls back challenge, ALL quotas and encrypted
 * outbox in one transaction. Do not reuse a PDO already in a transaction.
 */
final readonly class V5MySqlSmsOutbox implements V5AtomicSmsOutboxPort
{
    private const REQUIRED_TABLES = [
        'ev_v5_auth_challenges',
        'ev_v5_sms_budget_windows',
        'ev_v5_sms_outbox',
    ];

    public function __construct(
        private PDO $db,
        private V5SmsLockedEligibilityPort $site,
        private V5SmsMessageFactoryPort $messages,
        #[\SensitiveParameter] private string $deviceBindingKey,
    ) {
        if (strlen($deviceBindingKey) < 32) {
            throw new InvalidArgumentException('Challenge binding key unavailable');
        }
    }

    /** This same function MUST be used when creating the original challenge. */
    public static function installationBinding(
        string $deviceId,
        string $platform,
        #[\SensitiveParameter] string $bindingKey,
    ): string {
        if (!V5LoginWorkflow::validInstallation($deviceId, $platform)
            || strlen($bindingKey) < 32
        ) {
            throw new InvalidArgumentException('Invalid challenge installation');
        }
        return hash_hmac('sha256', "evrasia:v5:challenge-install:v1\0"
            . $deviceId . "\0" . $platform, $bindingKey);
    }

    public function reserveAndQueue(V5SmsQueueReservation $reservation): V5SmsQueuedDelivery
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $this->db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $this->db->inTransaction()
        ) {
            throw new RuntimeException('sms_outbox_connection_unavailable');
        }
        $this->assertWindows($reservation);
        try {
            // Do not write to an unexpected or nontransactional table.
            $engine = $this->prepare('SELECT ENGINE FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
            foreach (self::REQUIRED_TABLES as $name) {
                $this->execute($engine, [$name]);
                if ($engine->fetchColumn() !== 'InnoDB') {
                    throw new RuntimeException('sms_outbox_table_unavailable');
                }
            }
            if (!$this->db->beginTransaction()) {
                throw new RuntimeException('sms_outbox_begin_failed');
            }
            // DB time prevents multi-node clock drift bypassing the cooldown.
            $time = $this->prepare('SELECT UNIX_TIMESTAMP()');
            $this->execute($time, []);
            $now = self::integer($time->fetchColumn());
            if ($now === null || $now < 1 || $now > PHP_INT_MAX - 2592000) {
                throw new RuntimeException('sms_outbox_clock_unavailable');
            }
            $lock = $this->prepare('SELECT user_id, device_binding, platform, kind, '
                . 'status, password_proven, issued_at, expires_at, failed_attempts, '
                . 'sms_sent_count, last_sms_sent_at, verification_stamp, auth_epoch '
                . 'FROM ev_v5_auth_challenges WHERE challenge_id = ? FOR UPDATE');
            $this->execute($lock, [$reservation->challengeId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            $challenge = self::validateChallengeRow($row);
            $binding = self::installationBinding(
                $reservation->deviceId, $reservation->platform, $this->deviceBindingKey
            );
            if ($challenge === null
                || $challenge['user_id'] !== $reservation->userId
                || $challenge['platform'] !== $reservation->platform
                || !hash_equals($challenge['device_binding'], $binding)
                || !ChallengeRules::canVerify(
                    $challenge, $reservation->userId, $binding, 'sms', $now
                )
            ) {
                throw new AuthenticationRejected('challenge_unavailable');
            }
            $count = $challenge['sms_sent_count'];
            if ($reservation->isResend) {
                if ($count < 1 || !ChallengeRules::canResendSms(
                    $challenge, $reservation->userId, $binding, $now, true
                ) || $challenge['verification_stamp'] === null
                    || !hash_equals(
                        $challenge['verification_stamp'],
                        $reservation->destination->verificationStamp
                    )
                ) {
                    throw new AuthenticationRejected('challenge_unavailable');
                }
            } elseif ($count !== 0 || $challenge['last_sms_sent_at'] !== null
                || $challenge['verification_stamp'] !== null
            ) {
                throw new AuthenticationRejected('challenge_unavailable');
            }
            // This callback must lock actual b_user/MFA/verified-phone rows
            // on THE SAME PDO connection and reject changed/stale versions.
            if (!$this->site->stillEligibleWithLocks(
                $this->db, $reservation, $challenge['auth_epoch']
            )) {
                throw new AuthenticationRejected('challenge_unavailable');
            }

            $upsert = $this->prepare('INSERT INTO ev_v5_sms_budget_windows '
                . '(bucket_key, used_units, expires_at) VALUES (?, 0, ?) '
                . 'ON DUPLICATE KEY UPDATE bucket_key = bucket_key');
            $readBudget = $this->prepare('SELECT used_units, expires_at '
                . 'FROM ev_v5_sms_budget_windows WHERE bucket_key = ? FOR UPDATE');
            $increment = $this->prepare('UPDATE ev_v5_sms_budget_windows '
                . 'SET used_units = used_units + ? WHERE bucket_key = ? AND used_units = ?');
            foreach ($reservation->windows as $window) {
                // Never reserve an expired/far-future bucket when app and DB
                // clocks disagree or the caller supplied stale budgets.
                if ($window->expiresAt <= $now
                    || $window->expiresAt > $now + 2592000
                ) {
                    throw new AuthenticationRejected('challenge_unavailable');
                }
                $this->execute($upsert, [$window->bucketKey, $window->expiresAt]);
                $this->execute($readBudget, [$window->bucketKey]);
                $data = $readBudget->fetch(PDO::FETCH_ASSOC);
                if (!is_array($data)) {
                    throw new RuntimeException('sms_outbox_budget_missing');
                }
                $used = self::integer($data['used_units'] ?? null);
                $expires = self::integer($data['expires_at'] ?? null);
                if ($used === null || $expires !== $window->expiresAt) {
                    throw new RuntimeException('sms_outbox_budget_invalid');
                }
                if ($used > $window->maxUnits - $window->chargeUnits) {
                    throw new AuthenticationRejected('challenge_unavailable');
                }
                $this->execute($increment, [$window->chargeUnits, $window->bucketKey, $used]);
                if ($increment->rowCount() !== 1) {
                    throw new RuntimeException('sms_outbox_budget_race');
                }
            }

            $message = $this->messages->prepare($reservation);
            $update = $this->prepare('UPDATE ev_v5_auth_challenges '
                . 'SET sms_sent_count = ?, last_sms_sent_at = ?, verification_stamp = ?, '
                . 'sms_code_hmac = ?, current_delivery_id = ? '
                . 'WHERE challenge_id = ? AND status = ? AND sms_sent_count = ?');
            $this->execute($update, [
                $count + 1, $now, $reservation->destination->verificationStamp,
                $message->codeHmac, $message->deliveryId,
                $reservation->challengeId, 'pending', $count,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('sms_outbox_challenge_race');
            }
            $insert = $this->prepare('INSERT INTO ev_v5_sms_outbox '
                . '(delivery_id, challenge_id, user_id, send_ordinal, encrypted_envelope, '
                . 'provider_idempotency_key, verification_stamp, max_price_minor, '
                . 'currency, status, queued_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $this->execute($insert, [
                $message->deliveryId, $reservation->challengeId, $reservation->userId,
                $count + 1, $message->encryptedEnvelope, $message->deliveryId,
                $reservation->destination->verificationStamp,
                $reservation->price->maxMinorUnits, $reservation->price->currency,
                'queued', $now,
            ]);
            if ($insert->rowCount() !== 1) {
                throw new RuntimeException('sms_outbox_insert_failed');
            }
            if (!$this->db->commit()) {
                throw new RuntimeException('sms_outbox_commit_failed');
            }
            return new V5SmsQueuedDelivery($message->deliveryId);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                try {
                    $this->db->rollBack();
                } catch (Throwable) {
                    // Uncertain connection state MUST NEVER authorize SMS.
                }
            }
            if ($error instanceof AuthenticationRejected) {
                throw new AuthenticationRejected('challenge_unavailable');
            }
            // Never include SQL/account/provider details in the public error.
            throw new RuntimeException('sms_outbox_unavailable');
        }
    }

    private static function validateChallengeRow(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $id = self::integer($row['user_id'] ?? null);
        $issued = self::integer($row['issued_at'] ?? null);
        $expires = self::integer($row['expires_at'] ?? null);
        $failed = self::integer($row['failed_attempts'] ?? null);
        $sent = self::integer($row['sms_sent_count'] ?? null);
        $last = ($row['last_sms_sent_at'] ?? null) === null
            ? null : self::integer($row['last_sms_sent_at']);
        if ($id === null || $id < 1 || $issued === null || $expires === null
            || $failed === null || $sent === null || $sent > 3
            || (($row['last_sms_sent_at'] ?? null) !== null && $last === null)
            || !is_string($row['device_binding'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $row['device_binding']) !== 1
            || !in_array($row['platform'] ?? null, ['ios', 'android'], true)
            || !in_array($row['password_proven'] ?? null, ['Y', 'N'], true)
            || !is_string($row['auth_epoch'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $row['auth_epoch']) !== 1
            || !(($row['verification_stamp'] ?? null) === null
                || (is_string($row['verification_stamp'])
                    && preg_match('/\A[a-f0-9]{64}\z/D', $row['verification_stamp']) === 1))
        ) {
            return null;
        }
        return [
            'user_id' => $id, 'device_binding' => $row['device_binding'],
            'platform' => $row['platform'], 'kind' => $row['kind'] ?? null,
            'status' => $row['status'] ?? null,
            'password_proven' => $row['password_proven'] === 'Y',
            'issued_at' => $issued, 'expires_at' => $expires,
            'failed_attempts' => $failed, 'sms_sent_count' => $sent,
            'last_sms_sent_at' => $last,
            'verification_stamp' => $row['verification_stamp'] ?? null,
            'auth_epoch' => $row['auth_epoch'],
        ];
    }

    private function assertWindows(V5SmsQueueReservation $reservation): void
    {
        // The coordinator is the sole source of these trusted windows.
        if (count($reservation->windows) !== count(V5SmsBudgetPolicy::SCOPES)) {
            throw new RuntimeException('sms_budget_count_invalid');
        }
        $last = '';
        $expensive = 0;
        foreach ($reservation->windows as $window) {
            if (!$window instanceof V5SmsBudgetWindow
                || strcmp($window->bucketKey, $last) <= 0
                || $window->chargeUnits > $window->maxUnits
            ) {
                throw new RuntimeException('sms_budget_invalid');
            }
            $last = $window->bucketKey;
            if ($window->chargeUnits !== 1) {
                $expensive++;
                if ($window->chargeUnits !== $reservation->price->maxMinorUnits) {
                    throw new RuntimeException('sms_budget_cost_invalid');
                }
            }
        }
        if ($expensive !== ($reservation->price->maxMinorUnits === 1 ? 0 : 1)) {
            throw new RuntimeException('sms_budget_cost_invalid');
        }
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            return null;
        }
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $value === false ? null : $value;
    }

    private function prepare(string $sql): PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt instanceof PDOStatement) {
            throw new RuntimeException('sms_outbox_query_unavailable');
        }
        return $stmt;
    }

    private static function execute(PDOStatement $stmt, array $params): void
    {
        if (!$stmt->execute($params)) {
            throw new RuntimeException('sms_outbox_query_unavailable');
        }
    }
}
