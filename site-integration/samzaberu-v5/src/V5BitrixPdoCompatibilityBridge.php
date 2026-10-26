<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * REVIEW/STAGING ONLY: evidence that the Bitrix application mysqli connection
 * is free of ALL other transactional owners at begin time, and that the V5
 * workflow owns its full scope. A boolean flag or config toggle is NOT proof.
 * No site/production implementation is installed with this prototype.
 */
interface V5NativeBitrixExclusiveTransactionPermitPort
{
    public function safeToBeginOn(object $nativeBitrixConnection): ?bool;
}

/**
 * Compatibility bridge, deliberately limited to the existing V5 SQL shapes.
 * It is NOT a native PDO driver or a native server-prepared statement layer.
 * Positional arguments are encoded by the Bitrix SqlHelper, so the existing
 * V5MySqlSmsOutbox, V5BitrixLockedSmsEligibility and auth epoch adapter may
 * all use the SAME physical Application::getConnection() mysqli transaction.
 *
 * This implementation makes NO connection or Bitrix bootstrap. Staging-only
 * until the exact version's SqlHelper/transaction contracts and native MFA
 * mutation paths have been verified with live isolated MySQL concurrency.
 */
final class V5BitrixPdoCompatibilityBridge extends PDO
{
    private bool $opened = false;
    private bool $poisoned = false;
    private bool $guardedTerminalInProgress = false;
    private bool $verifiedHttpIngressAtConstruction = false;
    private ?V5ProductionNativeTransactionContinuity $continuityGuard = null;

    public function __construct(
        private object $native,
        private V5NativeBitrixExclusiveTransactionPermitPort $permit,
        private bool $requireNativeContinuity = false,
    ) {
        if (!is_a($native, 'Bitrix\\Main\\DB\\MysqliConnection')
            || !method_exists($native, 'getSqlHelper')
            || !method_exists($native, 'query')
            || !method_exists($native, 'queryExecute')
            || !method_exists($native, 'getAffectedRowsCount')
            || !method_exists($native, 'startTransaction')
            || !method_exists($native, 'commitTransaction')
            || !method_exists($native, 'rollbackTransaction')
        ) {
            throw new RuntimeException('v5_bitrix_native_driver_unavailable');
        }
        $helper = $native->getSqlHelper();
        if (!is_object($helper) || !method_exists($helper, 'forSql')) {
            throw new RuntimeException('v5_bitrix_sql_helper_unavailable');
        }
        $this->assertCurrentApplicationConnection();

        // The legacy non-strict constructor is kept for CLI diagnostics
        // only. Every real V5 HTTP request must arrive through its own
        // canonical protected route and use the guarded transaction mode.
        // This does NOT grant a production permit: the separately injected
        // permit must still prove safeToBeginOn() before any BEGIN.
        if (PHP_SAPI !== 'cli') {
            if (PHP_SAPI !== 'fpm-fcgi' || !$this->requireNativeContinuity) {
                throw new RuntimeException('v5_bitrix_http_continuity_required');
            }
            require_once __DIR__ . '/V5ProductionNativeRequestBoundaryGuard.php';
            $boundary = new V5ProductionNativeRequestBoundaryGuard(
                new V5ProductionNativeTransactionPreflight()
            );
            if (!$boundary->isEligible($this->native)) {
                throw new RuntimeException('v5_bitrix_http_scope_unverified');
            }
            $this->verifiedHttpIngressAtConstruction = true;
        }
    }

    /**
     * Strict HTTP ingress eligibility established on THIS native connection
     * at construction. Not a transaction permit, not an attestation of
     * native security mutation hooks or user authentication.
     */
    public function hasStrictV5HttpIngress(): bool
    {
        return PHP_SAPI === 'fpm-fcgi'
            && $this->verifiedHttpIngressAtConstruction
            && $this->requireNativeContinuity
            && !$this->poisoned
            && !$this->opened
            && $this->native === \Bitrix\Main\Application::getConnection();
    }

    /**
     * Checkout-only strict transaction proof. Unlike inTransaction(), this
     * verifies the exact native Bitrix application connection, live SQL
     * savepoint continuity and exclusive HTTP V5 ingress. Staging CLI and
     * non-strict adapters must never delete real checkout cart contents.
     */
    public function assertOwnedCheckoutTransactionContinuity(): void
    {
        if (PHP_SAPI !== 'fpm-fcgi'
            || !$this->verifiedHttpIngressAtConstruction
            || !$this->requireNativeContinuity
            || !$this->opened || $this->poisoned
            || !$this->ownsCurrentNativeTransaction($this->native)
        ) {
            throw new RuntimeException('v5_checkout_shared_transaction_unverified');
        }
        $this->assertCurrentApplicationConnection();
        $this->assertStrictStatementContinuity();
        if (!$this->ownsCurrentNativeTransaction($this->native)) {
            throw new RuntimeException('v5_checkout_shared_transaction_lost');
        }
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            default => throw new RuntimeException('v5_bitrix_attribute_unsupported'),
        };
    }

    public function inTransaction(): bool
    {
        // Only a transaction successfully STARTED by this bridge is owned.
        // A failed/denied begin MUST NOT cause the caller to roll back a
        // transaction belonging to an unrelated Bitrix component.
        return $this->opened;
    }

    /**
     * Restrict staged native OTP writers to the EXACT Bitrix mysqli connection
     * with an active transaction opened by THIS bridge. A different bridge,
     * PHP global Bitrix connection or a poisoned scope must never claim it.
     * This is necessary but not sufficient: native depth, source identity and
     * SAVEPOINT continuity are checked independently by the TOTP consumer.
     */
    public function ownsCurrentNativeTransaction(object $candidate): bool
    {
        return $this->opened && !$this->poisoned
            && $candidate === $this->native;
    }

    /**
     * Terminal quarantine after a native callback lost its V5-owned SQL
     * savepoint. A hidden native COMMIT+BEGIN may now own this connection:
     * never let V5 roll back, commit or reuse that possibly FOREIGN scope.
     * This DOES NOT close/disconnect Bitrix; the staging driver must discard
     * the entire native connection before any next authentication operation.
     */
    public function abandonUncertainNativeTransaction(): void
    {
        $this->poisoned = true;
        $this->opened = false;
    }

    public function beginTransaction(): bool
    {
        if ($this->opened || $this->poisoned) {
            throw new RuntimeException('v5_bitrix_nested_transaction_forbidden');
        }
        $this->assertCurrentApplicationConnection();
        try {
            if ($this->permit->safeToBeginOn($this->native) !== true) {
                throw new RuntimeException('v5_bitrix_exclusive_scope_unverified');
            }
            if ($this->native->startTransaction() === false) {
                throw new RuntimeException('Native begin failed');
            }
            $this->opened = true;
            if ($this->requireNativeContinuity) {
                require_once __DIR__ . '/V5ProductionNativeTransactionContinuity.php';
                $this->continuityGuard =
                    V5ProductionNativeTransactionContinuity::arm($this->native, $this);
                if (!$this->continuityGuard instanceof V5ProductionNativeTransactionContinuity) {
                    throw new RuntimeException('v5_bitrix_native_continuity_unavailable');
                }
            }
            return true;
        } catch (Throwable) {
            // Unknown BEGIN outcome: never advertise V5 ownership after
            // a failed strict continuity arm. Never roll back an unknown owner.
            $this->poisoned = true;
            if ($this->requireNativeContinuity) {
                $this->opened = false;
            }
            throw new RuntimeException('v5_bitrix_begin_unavailable');
        }
    }

    public function commit(): bool
    {
        if (!$this->opened || $this->poisoned) {
            throw new RuntimeException('v5_bitrix_commit_not_owned');
        }
        $this->assertCurrentApplicationConnection();
        if ($this->requireNativeContinuity && !$this->guardedTerminalInProgress) {
            $guard = $this->continuityGuard;
            $this->continuityGuard = null;
            if ($guard === null || !$guard->isArmed()) {
                $this->abandonUncertainNativeTransaction();
                throw new RuntimeException('v5_bitrix_commit_continuity_unavailable');
            }
            $this->guardedTerminalInProgress = true;
            try {
                if (!$guard->finishCommit()) {
                    throw new RuntimeException('v5_bitrix_commit_continuity_lost');
                }
                return true;
            } finally {
                $this->guardedTerminalInProgress = false;
            }
        }
        try {
            if ($this->native->commitTransaction() === false) {
                throw new RuntimeException('Native commit failed');
            }
            $this->opened = false;
            return true;
        } catch (Throwable) {
            $this->poisoned = true;
            throw new RuntimeException('v5_bitrix_commit_uncertain');
        }
    }

    public function rollBack(): bool
    {
        if (!$this->opened) {
            // In particular, never roll back somebody else's native tx
            // after a denied/uncertain begin attempt.
            throw new RuntimeException('v5_bitrix_rollback_not_owned');
        }
        if ($this->requireNativeContinuity && !$this->guardedTerminalInProgress) {
            $guard = $this->continuityGuard;
            $this->continuityGuard = null;
            if ($guard === null || !$guard->isArmed()) {
                $this->abandonUncertainNativeTransaction();
                throw new RuntimeException('v5_bitrix_rollback_continuity_unavailable');
            }
            $this->guardedTerminalInProgress = true;
            try {
                if (!$guard->finishRollback()) {
                    throw new RuntimeException('v5_bitrix_rollback_continuity_lost');
                }
                return true;
            } finally {
                $this->guardedTerminalInProgress = false;
            }
        }
        try {
            $this->assertCurrentApplicationConnection();
            if ($this->native->rollbackTransaction() === false) {
                throw new RuntimeException('Native rollback failed');
            }
            $this->opened = false;
            // If transaction state was already uncertain, retain a permanent
            // poison flag: a new authenticated request must use a fresh scope.
            return true;
        } catch (Throwable) {
            $this->poisoned = true;
            throw new RuntimeException('v5_bitrix_rollback_uncertain');
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($options !== []) {
            throw new RuntimeException('v5_bitrix_prepare_options_forbidden');
        }
        if ($this->poisoned) {
            throw new RuntimeException('v5_bitrix_connection_poisoned');
        }
        self::assertStatementAllowed($query);
        // Checkout SQL may never be prepared by legacy CLI diagnostics or
        // V5 auth adapters without the strict FPM-owned transaction.
        $this->assertCheckoutSqlScope($query);
        return new V5BitrixEmulatedStatement($this, $query);
    }

    /**
     * Strict V5-only safety check immediately before and after native SQL.
     * This protects against hidden COMMIT+BEGIN before the next statement,
     * not merely at the eventual transaction commit.
     */
    private function assertStrictStatementContinuity(): void
    {
        if (!$this->requireNativeContinuity || !$this->opened) {
            return;
        }
        $guard = $this->continuityGuard;
        if (!$guard instanceof V5ProductionNativeTransactionContinuity
            || !$guard->confirmBeforeStatement()) {
            $this->abandonUncertainNativeTransaction();
            throw new RuntimeException('v5_bitrix_statement_continuity_lost');
        }
    }

    /** @internal Only the private fixed-SQL statement adapter calls this. */
    public function runStatement(string $template, array $params): array
    {
        if ($this->poisoned) {
            throw new RuntimeException('v5_bitrix_connection_poisoned');
        }
        // Even direct callers may not bypass prepare()'s allowlist.
        self::assertStatementAllowed($template);
        // runStatement() is public for the PDOStatement wrapper; recheck
        // here so a direct caller cannot bypass prepare() admission.
        $this->assertCheckoutSqlScope($template);
        $this->assertCurrentApplicationConnection();
        $sql = $this->encodeParameters($template, $params);
        $this->assertStrictStatementContinuity();
        $isSelect = str_starts_with(ltrim($sql), 'SELECT ');
        if (!$this->opened && (!$isSelect || str_contains(strtoupper($sql), 'FOR UPDATE'))) {
            throw new RuntimeException('v5_bitrix_write_or_lock_without_scope');
        }
        try {
            if ($isSelect) {
                $result = $this->native->query($sql);
                if (!is_object($result) || !method_exists($result, 'fetch')) {
                    throw new RuntimeException('Unexpected Bitrix result');
                }
                $rows = [];
                // A bounded read; all V5 reads use a single-row key or LIMIT 2.
                // The pre-existing V5 SQL uses LIMIT 2/1 for all account reads.
                // A SELECT lacking a LIMIT is allowed only for constants.
                for ($i = 0; $i < 3; $i++) {
                    $row = $result->fetch();
                    if ($row === false || $row === null) {
                        break;
                    }
                    if (!is_array($row) || $i === 2) {
                        throw new RuntimeException('Unbounded or invalid native result');
                    }
                    $rows[] = $row;
                }
                $this->assertStrictStatementContinuity();
                return [$rows, 0];
            }
            if ($this->native->queryExecute($sql) === false) {
                throw new RuntimeException('Native write failed');
            }
            $affected = $this->native->getAffectedRowsCount();
            if (!is_int($affected) && !(is_string($affected) && ctype_digit($affected))) {
                throw new RuntimeException('Invalid native affected count');
            }
            $this->assertStrictStatementContinuity();
            return [[], (int)$affected];
        } catch (Throwable) {
            throw new RuntimeException('v5_bitrix_sql_execution_unavailable');
        }
    }

    /**
     * A separate, stricter admission policy for V5 checkout INSERT/SELECT/
     * UPDATE and its two cart/gift DELETE templates. The generic auth
     * diagnostic bridge can NEVER execute these, even after mock BEGIN.
     */
    private function assertCheckoutSqlScope(string $sql): void
    {
        if (!in_array($sql, self::PICKUP_CHECKOUT_SQL, true)) {
            return;
        }
        if (PHP_SAPI !== 'fpm-fcgi'
            || !$this->verifiedHttpIngressAtConstruction
            || !$this->requireNativeContinuity
            || !$this->opened || $this->poisoned
            || !$this->continuityGuard instanceof V5ProductionNativeTransactionContinuity
            || !$this->continuityGuard->isArmed()
            || !$this->ownsCurrentNativeTransaction($this->native)) {
            throw new RuntimeException('v5_checkout_sql_scope_unverified');
        }
        $this->assertCurrentApplicationConnection();
    }

    private function assertCurrentApplicationConnection(): void
    {
        if (!class_exists('Bitrix\\Main\\Application')
            || !method_exists('Bitrix\\Main\\Application', 'getConnection')) {
            throw new RuntimeException('v5_bitrix_application_unavailable');
        }
        try {
            $current = \Bitrix\Main\Application::getConnection();
        } catch (Throwable) {
            throw new RuntimeException('v5_bitrix_application_unavailable');
        }
        if ($current !== $this->native) {
            throw new RuntimeException('v5_bitrix_wrong_connection');
        }
    }

    /**
     * Only the two exact, user-scoped, row-locked V5 mobile trust reads.
     * All arbitrary queries against shared V4 trust data remain forbidden.
     * SQL parameters use Bitrix's existing constrained escaping.
     */
    private const V5_TRUST_LOCK_SQL = [
        'SELECT USER_ID, DEVICE_ID_HASH, TRUST_TOKEN_HASH, '
            . 'TOKEN_VERSION, STATUS, UNIX_TIMESTAMP(TOKEN_EXPIRES_AT) AS expires_epoch, USER_AGENT '
            . 'FROM ev_trusted_devices WHERE USER_ID = ? AND DEVICE_ID_HASH = ? FOR UPDATE',
        'SELECT USER_ID, DEVICE_ID_HASH, TRUST_TOKEN_HASH, '
            . 'TOKEN_VERSION, STATUS, UNIX_TIMESTAMP(TOKEN_EXPIRES_AT) AS expires_epoch, USER_AGENT '
            . 'FROM ev_trusted_devices WHERE USER_ID = ? AND DEVICE_ID_HASH > ? '
            . 'ORDER BY DEVICE_ID_HASH LIMIT 2 FOR UPDATE',
    ];

    /** @var list<string> */
    private const STRONG_SESSION_SQL = [
        'SELECT sid, user_id FROM ev_v5_strong_sessions '
            . 'WHERE refresh_digest = ? LIMIT 2',
        'SELECT sid, user_id, auth_epoch, assurance, device_binding, platform, assured_at, '
            . 'expires_at, status, refresh_digest, refresh_generation '
            . 'FROM ev_v5_strong_sessions WHERE sid = ? FOR UPDATE',
        'INSERT INTO ev_v5_strong_sessions '
            . '(sid, user_id, auth_epoch, assurance, device_binding, platform, '
            . 'assured_at, expires_at, status, refresh_digest, refresh_generation, '
            . 'grant_digest, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'UPDATE ev_v5_strong_sessions SET refresh_digest = ?, '
            . 'refresh_generation = ?, updated_at = ? '
            . 'WHERE sid = ? AND refresh_digest = ? AND refresh_generation = ? '
            . 'AND auth_epoch = ? AND status = ?',
        'UPDATE ev_v5_strong_sessions SET status = ?, updated_at = ? '
            . 'WHERE sid = ? AND status = ? AND auth_epoch = ?',
    ];

    /**
     * Exact indexed outbox reads used by dispatch, reconciliation and consume.
     * Do not expand the generic WHERE regex to accept arbitrary delivery reads.
     * Ciphertext is read only by the locked, single-delivery dispatch template.
     * @var list<string>
     */
    private const SMS_OUTBOX_READ_SQL = [
        'SELECT challenge_id,user_id FROM ev_v5_sms_outbox '
            . 'WHERE delivery_id = ? LIMIT 2',
        'SELECT delivery_id,challenge_id,user_id,send_ordinal,encrypted_envelope,'
            . 'provider_idempotency_key,verification_stamp,max_price_minor,currency,status '
            . 'FROM ev_v5_sms_outbox WHERE delivery_id = ? FOR UPDATE',
        'SELECT status FROM ev_v5_sms_outbox WHERE delivery_id = ? '
            . 'AND provider_idempotency_key = ? LIMIT 1',
        'SELECT status,verification_stamp,send_ordinal FROM ev_v5_sms_outbox '
            . 'WHERE delivery_id = ? AND challenge_id = ? FOR UPDATE',
    ];

    /** Exact existing first-factor lookups and their locked recheck variants. */
    private const AUTH_IDENTITY_READ_SQL = [
        'SELECT ID, LOGIN, EMAIL, PASSWORD, ACTIVE, BLOCKED, PASSWORD_EXPIRED, '
            . 'LOGIN_ATTEMPTS FROM b_user WHERE EMAIL = ? LIMIT 2',
        'SELECT ID, LOGIN, EMAIL, PASSWORD, ACTIVE, BLOCKED, PASSWORD_EXPIRED, '
            . 'LOGIN_ATTEMPTS FROM b_user WHERE LOGIN = ? LIMIT 2',
        'SELECT ID, LOGIN, EMAIL, PASSWORD, ACTIVE, BLOCKED, PASSWORD_EXPIRED, '
            . 'LOGIN_ATTEMPTS FROM b_user WHERE EMAIL = ? LIMIT 2 FOR UPDATE',
        'SELECT ID, LOGIN, EMAIL, PASSWORD, ACTIVE, BLOCKED, PASSWORD_EXPIRED, '
            . 'LOGIN_ATTEMPTS FROM b_user WHERE LOGIN = ? LIMIT 2 FOR UPDATE',
    ];

    /** @var list<string> */
    private const EMAIL_AUTH_SQL = [
        // Exact site login/current email proof reads.
        'SELECT ID,LOGIN,EMAIL,PASSWORD,ACTIVE,BLOCKED,'
            . 'PASSWORD_EXPIRED,LOGIN_ATTEMPTS,UNIX_TIMESTAMP(TIMESTAMP_X) AS profile_stamp '
            . 'FROM b_user WHERE ID = ? FOR UPDATE',
        'SELECT ID FROM b_user WHERE EMAIL = ? LIMIT 2 FOR UPDATE',
        'SELECT ID FROM b_user WHERE LOGIN = ? LIMIT 2 FOR UPDATE',
        'SELECT USER_ID FROM b_sec_user WHERE USER_ID = ? LIMIT 2 FOR UPDATE',
        'SELECT user_id,current_email_mac,profile_updated_at,'
            . 'verified_at,source,revoked FROM ev_web_email_ownership_proof '
            . 'WHERE user_id = ? FOR UPDATE',
        // Locking and auth epoch form used by email issuance and consumed grant.
        'SELECT ID,ACTIVE,BLOCKED,PASSWORD_EXPIRED '
            . 'FROM b_user WHERE ID = ? FOR UPDATE',
        'SELECT ID,ACTIVE,BLOCKED,PASSWORD_EXPIRED '
            . 'FROM b_user WHERE ID=? FOR UPDATE',
        'SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state '
            . 'WHERE USER_ID = ? FOR UPDATE',
        'SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state '
            . 'WHERE USER_ID=? FOR UPDATE',
        // Existing eurasia_new does NOT yet have this V5 email ledger.
        // Exact prepared statements are inert until independently approved
        // same-DB migration and full native admission; no DDL here.
        'SELECT COUNT(*) FROM ev_v5_email_auth_challenges '
            . 'WHERE user_id = ? AND status IN (?,?) AND expires_at > ?',
        'SELECT challenge_id FROM ev_v5_email_auth_challenges '
            . 'WHERE challenge_id = ? LIMIT 1',
        'SELECT user_id FROM ev_v5_email_auth_challenges '
            . 'WHERE challenge_id = ? LIMIT 1',
        'SELECT challenge_id,user_id,device_binding,platform,status,'
            . 'email_stamp,auth_epoch,code_hmac,issued_at,expires_at,failed_attempts '
            . 'FROM ev_v5_email_auth_challenges WHERE challenge_id = ? FOR UPDATE',
        'SELECT challenge_id,user_id,device_binding,platform,status,'
            . 'email_stamp,auth_epoch,code_hmac,issued_at,expires_at,failed_attempts '
            . 'FROM ev_v5_email_auth_challenges WHERE challenge_id=? FOR UPDATE',
        // Only the existing password-bound email OTP code may mutate its
        // own NEW ledger. Never permit generic UPDATE/INSERT/SELECT here.
        'INSERT INTO ev_v5_email_auth_challenges '
            . '(challenge_id,user_id,device_binding,platform,status,email_stamp,'
            . 'auth_epoch,code_hmac,issued_at,expires_at,failed_attempts) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        'UPDATE ev_v5_email_auth_challenges SET status = ? '
            . 'WHERE challenge_id = ? AND user_id = ? AND status = ? '
            . 'AND auth_epoch = ? AND email_stamp = ? AND expires_at > ?',
        'UPDATE ev_v5_email_auth_challenges '
            . 'SET status = ?, code_hmac = NULL WHERE challenge_id = ? '
            . 'AND status = ? AND failed_attempts = ?',
        'UPDATE ev_v5_email_auth_challenges '
            . 'SET failed_attempts = ?, status = ? WHERE challenge_id = ? '
            . 'AND status = ? AND failed_attempts = ?',
        // New V5-only atomic email->SMS stage. This exact CAS clears the
        // original OTP and can be executed only under V5-owned transaction.
        'UPDATE ev_v5_email_auth_challenges SET status = ?, code_hmac = NULL '
            . 'WHERE challenge_id = ? AND user_id = ? AND status = ? '
            . 'AND auth_epoch = ? AND email_stamp = ? AND expires_at > ?',
    ];

    /**
     * ONLY the two read-only template checks used by
     * V5BitrixCurrentEmailLoginTemplateProof. Never return email addresses
     * or mail bodies, never query unbounded templates and never update them.
     * CHAR(35) deliberately avoids sending a literal SQL comment marker.
     * @var list<string>
     */
    private const EMAIL_LOGIN_MAIL_METADATA_SQL = [
        "SELECT ID,EVENT_NAME,ACTIVE,LID,"
            ."IF(INSTR(COALESCE(EMAIL_TO,''),CONCAT(CHAR(35),'EMAIL_TO',CHAR(35)))>0,1,0) AS email_to_ok,"
            ."IF(INSTR(COALESCE(MESSAGE,''),CONCAT(CHAR(35),'VERIFY_CODE',CHAR(35)))>0,1,0) AS verify_code_ok "
            ."FROM b_event_message WHERE ID = ? LIMIT 2",
        'SELECT SITE_ID FROM b_event_message_site '
            .'WHERE EVENT_MESSAGE_ID = ? ORDER BY SITE_ID LIMIT 2',
    ];

    // Exact native sale FUSER ownership point read for protected V5 cart.
    // b_sale_fuser ownership must remain locked until the cart read completes;
    // NO generic allowlisting of sale/basket tables or arbitrary SQL shapes.
    private const CART_OWNER_SQL = 'SELECT ID, USER_ID FROM b_sale_fuser '
        . 'WHERE USER_ID = ? ORDER BY ID ASC LIMIT 2 FOR UPDATE';

    /** Exact checkout V5 ledger and V4-verified cart cleanup templates.
     * No other basket table may be deleted by the compatibility bridge.
     * All writes require the bridge-owned native transaction.
     * Live checkout still has an independent strict-HTTP continuity gate.
     */
    private const PICKUP_CHECKOUT_SQL = [
        'INSERT INTO ev_v5_pickup_checkout_requests '
            . '(USER_ID, REQUEST_ID, INPUT_SHA256, STATE, CREATED_AT) '
            . "VALUES (?, UNHEX(?), UNHEX(?), 'pending', UTC_TIMESTAMP()) "
            . 'ON DUPLICATE KEY UPDATE USER_ID=USER_ID',
        'SELECT HEX(INPUT_SHA256) AS HASH_HEX, STATE, ORDER_ID, '
            . 'ORDER_NUMBER, ORDER_STATUS FROM ev_v5_pickup_checkout_requests '
            . 'WHERE USER_ID = ? AND REQUEST_ID = UNHEX(?) FOR UPDATE',
        'UPDATE ev_v5_pickup_checkout_requests SET '
            . "STATE='saved', ORDER_ID=?, ORDER_NUMBER=?, ORDER_STATUS=?, "
            . 'COMPLETED_AT=UTC_TIMESTAMP() '
            . "WHERE USER_ID=? AND REQUEST_ID=UNHEX(?) AND STATE='pending'",
        'SELECT ID, USER_ID, ACCOUNT_NUMBER, STATUS_ID, PAYED, CANCELED '
            . 'FROM b_sale_order WHERE ID = ? AND USER_ID = ? LIMIT 2 FOR UPDATE',
        'DELETE FROM B_API_BASKET WHERE FUSER_ID = ?',
        'DELETE FROM BASKET_CUSTOM_GIFTS WHERE UF_FUSER_ID = ?',
    ];

    private static function assertStatementAllowed(string $sql): void
    {
        if ($sql === '' || strlen($sql) > 8000
            || str_contains($sql, ';')
            || str_contains($sql, '--')
            || str_contains($sql, '/*')
            || str_contains($sql, '#')
        ) {
            throw new RuntimeException('v5_bitrix_sql_shape_forbidden');
        }
        if ($sql === 'SELECT UNIX_TIMESTAMP()') {
            return;
        }
        // Ownership proof is a single, fixed indexed-row read. A FOR UPDATE
        // statement still requires an owned native V5 transaction in runStatement.
        if ($sql === self::CART_OWNER_SQL) {
            return;
        }
        if (in_array($sql, self::PICKUP_CHECKOUT_SQL, true)) {
            return;
        }
        // Fixed transaction continuity guards, including four exact
        // names owned by V5MySqlEmailOtp (issue/rate/ack/verify). No
        // generic savepoint name or external SQL may be submitted.
        // runStatement() separately demands an owned native transaction for
        // ALL these write-shaped statements. No arbitrary names/rollback/batch.
        if (in_array($sql, [
            'SAVEPOINT v5_strong_session_guard',
            'RELEASE SAVEPOINT v5_strong_session_guard',
            'SAVEPOINT v5_native_factor_guard',
            'RELEASE SAVEPOINT v5_native_factor_guard',
            'SAVEPOINT v5_totp_issue_guard',
            'RELEASE SAVEPOINT v5_totp_issue_guard',
            'SAVEPOINT v5_email_issue_guard',
            'RELEASE SAVEPOINT v5_email_issue_guard',
            'SAVEPOINT v5_email_rate_guard',
            'RELEASE SAVEPOINT v5_email_rate_guard',
            'SAVEPOINT v5_email_ack_guard',
            'RELEASE SAVEPOINT v5_email_ack_guard',
            'SAVEPOINT v5_email_verify_guard',
            'RELEASE SAVEPOINT v5_email_verify_guard',
            'SAVEPOINT v5_email_sms_stage_guard',
            'RELEASE SAVEPOINT v5_email_sms_stage_guard',
        ], true)) {
            return;
        }
        // No generic EMAIL/LOGIN predicate allowance or ACTIVE pre-filter.
        if (in_array($sql, self::AUTH_IDENTITY_READ_SQL, true)) {
            return;
        }
        // Email queries must match the exact staging source templates.
        // All other access to V5's email ledger and ownership proof denied.
        if (in_array($sql, self::EMAIL_AUTH_SQL, true)) {
            return;
        }
        if (str_contains($sql, 'ev_v5_email_auth_challenges')
            || str_contains($sql, 'ev_web_email_ownership_proof')) {
            throw new RuntimeException('v5_bitrix_email_sql_forbidden');
        }
        // The site already owns the Redis email-send quota. No parallel
        // V5 SQL rate-window access may be added to the native bridge.
        if (str_contains($sql, 'ev_v5_email_rate_windows')) {
            throw new RuntimeException('v5_bitrix_email_rate_sql_forbidden');
        }
        // The Bitrix mail registry may be read ONLY using two exact
        // attestation statements. No mail template body leaves the DB.
        if (in_array($sql, self::EMAIL_LOGIN_MAIL_METADATA_SQL, true)) {
            return;
        }
        if (str_contains($sql, 'b_event_message')) {
            throw new RuntimeException('v5_bitrix_mail_registry_sql_forbidden');
        }
        if (str_contains($sql, 'ev_trusted_devices')) {
            if (!in_array($sql, self::V5_TRUST_LOCK_SQL, true)) {
                throw new RuntimeException('v5_bitrix_mobile_trust_sql_forbidden');
            }
            return;
        }
        // Only the EXACT statements emitted by V5MySqlStrongSessionStore.
        // Never add the sessions table to the generic SELECT/UPDATE regex:
        // refresh digests and grant state are sensitive authentication data.
        if (str_contains($sql, 'ev_v5_strong_sessions')) {
            if (!in_array($sql, self::STRONG_SESSION_SQL, true)) {
                throw new RuntimeException('v5_bitrix_strong_session_sql_forbidden');
            }
            return;
        }
        if (preg_match('/\ASELECT\s/i', $sql) === 1) {
            if (stripos($sql, 'ev_v5_sms_outbox') !== false) {
                if (!in_array($sql, self::SMS_OUTBOX_READ_SQL, true)) {
                    throw new RuntimeException('v5_bitrix_sms_outbox_read_forbidden');
                }
                return;
            }
            if (preg_match('/\bFROM\s+(?:information_schema\.TABLES|ev_v5_auth_challenges|ev_v5_sms_budget_windows|ev_v5_sms_outbox|b_user|b_sec_user|b_user_phone_auth|ev_samzaberu_auth_state)\b/i', $sql) !== 1) {
                throw new RuntimeException('v5_bitrix_select_scope_forbidden');
            }
            // Only indexed point reads, no bulk accidental user-data reads.
            if (preg_match('/\bWHERE\s+(?:(?:TABLE_SCHEMA\s*=\s*DATABASE\(\)\s+AND\s+TABLE_NAME)|(?:USER_ID|ID|challenge_id|bucket_key)\s*=)/i', $sql) !== 1
                && !str_contains($sql, 'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?')) {
                throw new RuntimeException('v5_bitrix_unbounded_select_forbidden');
            }
            return;
        }
        if (preg_match('/\A(?:INSERT\s+INTO|UPDATE)\s+(?:ev_v5_auth_challenges|ev_v5_sms_budget_windows|ev_v5_sms_outbox|ev_samzaberu_auth_state)\s/i', $sql) !== 1) {
            throw new RuntimeException('v5_bitrix_write_scope_forbidden');
        }
    }

    private function encodeParameters(string $sql, array $params): string
    {
        if (substr_count($sql, '?') !== count($params) || count($params) > 24) {
            throw new RuntimeException('v5_bitrix_parameter_count_invalid');
        }
        $helper = $this->native->getSqlHelper();
        $pieces = explode('?', $sql);
        $built = array_shift($pieces);
        foreach ($pieces as $index => $tail) {
            $value = $params[$index] ?? null;
            if ($value === null) {
                $literal = 'NULL';
            } elseif (is_int($value)) {
                $literal = (string)$value;
            } elseif (is_string($value) && strlen($value) <= 1048576
                && !str_contains($value, "\0")) {
                $escaped = $helper->forSql($value);
                if (!is_string($escaped)) {
                    throw new RuntimeException('v5_bitrix_sql_escaping_unavailable');
                }
                $literal = "'" . $escaped . "'";
            } else {
                throw new RuntimeException('v5_bitrix_parameter_type_forbidden');
            }
            $built .= $literal . $tail;
        }
        return $built;
    }
}

/** @internal Minimal PDOStatement semantics used by the existing V5 code. */
final class V5BitrixEmulatedStatement extends PDOStatement
{
    /** @var list<array<string,mixed>> */
    private array $rows = [];
    private int $offset = 0;
    private int $affected = 0;

    public function __construct(
        private V5BitrixPdoCompatibilityBridge $bridge,
        private string $template,
    ) {}

    public function execute(?array $params = null): bool
    {
        $parameters = $params ?? [];
        if (!array_is_list($parameters)) {
            throw new RuntimeException('v5_bitrix_positional_params_required');
        }
        [$this->rows, $this->affected] = $this->bridge->runStatement($this->template, $parameters);
        $this->offset = 0;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($cursorOrientation !== PDO::FETCH_ORI_NEXT || $cursorOffset !== 0
            || !in_array($mode, [PDO::FETCH_DEFAULT, PDO::FETCH_ASSOC], true)) {
            throw new RuntimeException('v5_bitrix_fetch_mode_unsupported');
        }
        return $this->rows[$this->offset++] ?? false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        $values = array_values($row);
        return $values[$column] ?? false;
    }

    public function rowCount(): int
    {
        return $this->affected;
    }
}
