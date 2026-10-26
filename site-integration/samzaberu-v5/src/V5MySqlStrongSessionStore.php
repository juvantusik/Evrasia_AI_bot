<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5LoginWorkflow.php';
require_once __DIR__ . '/V5SessionTokens.php';
require_once __DIR__ . '/V5SessionAssurancePolicy.php';
require_once __DIR__ . '/V5SecurityEventVersions.php';
require_once __DIR__ . '/V5TrustedSessionBinding.php';

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * No permissive/default implementation. Only a trusted site provider may
 * perform the FULL fresh native password/MFA/trust/grant re-check under the
 * locked b_user row in the SAME transaction as session insertion.
 * For consumed grants it MUST reject a fabricated/replayed grant and must
 * coordinate with the actual durable V5 challenge-consume implementation.
 */
interface V5LockedStrongSessionProofPort
{
    public function verifiedTrustWithUserLock(
        PDO $db, PasswordVerifiedAccount $account,
        string $deviceId, string $platform, string $trustToken
    ): bool;

    public function verifiedConsumedGrantWithUserLock(
        PDO $db, ConsumedLoginChallenge $grant
    ): bool;
}

/**
 * STAGING CANDIDATE ONLY: durable V5 session, access signing after commit,
 * one-use refresh rotation and revocation. Never wire to /api/v5 without
 * attested isolated Bitrix/MySQL staging and native mutation/epoch coverage.
 *
 * Lock discipline: no nested transaction; b_user FIRST; proof / mandatory
 * coverage; shared auth_state; session row last. Refresh digest index is read
 * WITHOUT lock before beginning so the actual lock order stays b_user first.
 * No raw bearer, password, trust token, OTP or device ID is persisted.
 */
/**
 * Site-only, READ-ONLY reader for /loyalty/pin after an already-validated
 * strong V5 access JWT. It MUST use this exact PDO/Bitrix transaction,
 * never open a second connection, mutate state or trigger SMS.
 * No production implementation is installed.
 */
interface V5LockedBonusPinReaderPort
{
    public function readLockedBonusPin(PDO $db, int $userId): ?string;
}

/**
 * READ-ONLY cart business service inside the SAME V5 user + epoch + session
 * row-locked transaction. Caller never supplies FUSER_ID or user_id.
 * The implementation MUST independently verify native FUSER ownership
 * and read-side-effect safety; no permissive default is provided.
 */
/**
 * Existing-item mutation only. Adapter resolves the locked user's FUSER,
 * verifies basket-line ownership and saves on the same guarded Bitrix
 * physical transaction. No V4 controller, browser cookie or new FUSER.
 */
/** Native add-by-catalog-ID under the same strong session and FUSER lock. */
interface V5LockedCartAddPort
{
    /** Returns the verified persisted new Bitrix basket-line ID, not product ID. */
    public function addLockedCatalogItem(
        PDO $db, int $userId, int $productId, int $quantity
    ): int;
}

interface V5LockedCartMutatorPort
{
    public function changeLockedCartItem(
        PDO $db, int $userId, int $itemId, ?int $quantity
    ): bool;
}

interface V5LockedCartReaderPort
{
    /** @return array{items:list<array<string,mixed>>,total:array<string,mixed>}|null */
    public function readLockedCart(PDO $db, int $userId): ?array;
}

/**
 * Native Bitrix profile fields, read in the same locked V5 JWT/session scope.
 * V4 user endpoints and V4 token parsing are never used.
 */
interface V5LockedProfileReaderPort
{
    /** @return array<string,int|string|null>|null */
    public function readLockedProfile(PDO $db, int $userId): ?array;
}

/**
 * Bitrix D7 order history for the locked V5 user, not a V4 controller.
 */
interface V5LockedOrdersReaderPort
{
    /** @return list<array<string,int|string|float|bool|null>>|null */
    public function readLockedOrders(PDO $db, int $userId): ?array;
}

/**
 * Order details and lines for the already locked V5 user and order ID.
 */
interface V5LockedOrderDetailReaderPort
{
    /** @return array<string,mixed>|null */
    public function readLockedOrderDetail(PDO $db, int $userId, int $orderId): ?array;
}

/**
 * The only authority allowed to write a Bitrix Sale D7 order for V5.
 * Must use the same physical native transaction, persist or resolve a
 * durable idempotency key, preserve existing site checkout pricing, delivery,
 * restaurant, gift and payment validation, and return only after successful
 * D7 save. Never use V4 runtime, V4 JWT or a client FUSER_ID.
 *
 * No positive production implementation is provided by the V5 MVP.
 */
interface V5LockedOrderPlacementPort
{
    /** @return array{id:int,user_id:int,request_id:string,number:string,status:string,saved:bool} */
    public function placeLockedOrder(
        PDO $db, int $userId, string $requestId, array $checkout
    ): array;
}

final readonly class V5MySqlStrongSessionStore implements V5StrongSessionPort
{
    private const TABLES = [
        'b_user', 'ev_samzaberu_auth_state', 'ev_v5_strong_sessions',
    ];

    public function __construct(
        private PDO $db,
        private V5AccessJwtCodec $access,
        private V5OpaqueRefreshTokens $refreshTokens,
        private V5LockedStrongSessionProofPort $proof,
        private V5NativeSecurityHookCoveragePort $nativeCoverage,
        #[\SensitiveParameter] private string $bindingKey,
        private int $sessionTtlSeconds = 86400,
    ) {
        if (strlen($bindingKey) < 32 || strlen($bindingKey) > 1024
            || $sessionTtlSeconds < 300 || $sessionTtlSeconds > 604800) {
            throw new InvalidArgumentException('V5 session policy unavailable');
        }
    }

    public function afterTrustedDevice(
        PasswordVerifiedAccount $account,
        string $deviceId,
        string $platform,
        string $trustToken,
    ): AuthenticatedV5Session {
        // Preserve valid previously enrolled V4 trusts even where the
        // original registration method was AUTH_PASSWORD. The proof MUST
        // still verify CURRENT token/account/device, expiry, non-revocation
        // and user/epoch state under this same transaction.
        // New trust tokens are created only AFTER TOTP/email/SMS success.
        if (!$account->accountAllowed
            || !V5LoginWorkflow::validInstallation($deviceId, $platform)
            || $trustToken === '' || strlen($trustToken) > 1024
        ) {
            throw new AuthenticationRejected('authentication_failed');
        }
        return $this->issue($account->userId, $deviceId, $platform, 'trusted',
            null, function () use ($account, $deviceId, $platform, $trustToken): bool {
                return $this->proof->verifiedTrustWithUserLock(
                    $this->db, $account, $deviceId, $platform, $trustToken
                ) === true;
            }, $trustToken);
    }

    public function afterConsumedChallenge(ConsumedLoginChallenge $grant): AuthenticatedV5Session
    {
        return $this->issue($grant->userId, $grant->deviceId, $grant->platform,
            $grant->kind->value, $grant->grantReference,
            fn(): bool => $this->proof->verifiedConsumedGrantWithUserLock(
                $this->db, $grant
            ) === true);
    }

    /**
     * One-use refresh. Caller must NEVER accept V4 refresh credentials.
     * Returns a completely new access JWT + opaque refresh token after commit.
     * Inactive/expired/changed-epoch sessions cannot be silently upgraded.
     */
    public function rotateRefresh(#[\SensitiveParameter] string $presented): AuthenticatedV5Session
    {
        $digest = $this->refreshTokens->digestPresented($presented);
        if ($digest === null) throw new AuthenticationRejected('authentication_failed');
        $this->assertReady();

        // Unlocked lookup is ONLY a hint to lock b_user first. The digest is
        // re-verified against the freshly locked session inside the transaction.
        $hint = $this->unique('SELECT sid, user_id FROM ev_v5_strong_sessions '
            . 'WHERE refresh_digest = ? LIMIT 2', [$digest]);
        $sid = $hint['sid'] ?? null;
        $userId = self::positive($hint['user_id'] ?? null);
        if (!is_string($sid) || !self::hex64($sid) || $userId === null) {
            throw new AuthenticationRejected('authentication_failed');
        }

        $committed = $this->transaction(function () use ($digest, $sid, $userId): array {
            $now = $this->dbTime();
            $this->lockUser($userId);
            $this->requireNativeCoverage($userId);
            $epoch = $this->lockedEpoch($userId);
            $row = $this->lockedSession($sid);
            $state = $this->validatedState($row, $userId, $now);
            $generation = self::positive($row['refresh_generation'] ?? null);
            if ($state === null || $generation === null || $generation >= PHP_INT_MAX - 1
                || $state['auth_epoch'] !== $epoch
                || !is_string($row['refresh_digest'] ?? null)
                || !hash_equals($row['refresh_digest'], $digest)
            ) {
                throw new AuthenticationRejected('authentication_failed');
            }
            $next = $this->refreshTokens->issue();
            $q = $this->query('UPDATE ev_v5_strong_sessions SET refresh_digest = ?, '
                . 'refresh_generation = ?, updated_at = ? '
                . 'WHERE sid = ? AND refresh_digest = ? AND refresh_generation = ? '
                . 'AND auth_epoch = ? AND status = ?', [
                $next['digest'], $generation + 1, $now, $sid, $digest,
                $generation, $epoch, 'active',
            ]);
            if ($q->rowCount() !== 1) {
                throw new AuthenticationRejected('authentication_failed');
            }
            // Credential material is NOT signed or returned before COMMIT.
            // A failed commit invalidates this candidate and never yields JWTs.
            return ['state'=>$state, 'now'=>$now, 'refreshToken'=>$next['token']];
        });
        return $this->issueCommittedCredentials($committed);
    }

    /**
     * Revoke this one V5 session, never another user's session and never V4.
     * Refresh and logout serialize on b_user then session; old JWTs still need
     * a server-side revocation/epoch check at EVERY protected action.
     */
    public function revokeAccess(#[\SensitiveParameter] string $accessJwt): bool
    {
        $claims = $this->access->verifyAccess($accessJwt, $this->peekTime());
        if ($claims === null) return false;
        $this->assertReady();
        $userId = self::positive($claims['sub'] ?? null);
        if ($userId === null) return false;
        try {
            return $this->transaction(function () use ($claims, $userId): bool {
                $now = $this->dbTime();
                $this->lockUser($userId);
                $this->requireNativeCoverage($userId);
                $epoch = $this->lockedEpoch($userId);
                $row = $this->lockedSession($claims['sid']);
                $state = $this->validatedState($row, $userId, $now);
                if ($state === null || $epoch !== $state['auth_epoch']
                    || !V5SessionAssurancePolicy::permitsProtectedAction($claims, $state, $now)) {
                    return false;
                }
                $q = $this->query('UPDATE ev_v5_strong_sessions SET status = ?, updated_at = ? '
                    . 'WHERE sid = ? AND status = ? AND auth_epoch = ?', [
                    'revoked', $now, $claims['sid'], 'active', $epoch,
                ]);
                return $q->rowCount() === 1;
            });
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Read-only assurance snapshot, NOT authorization to perform a DB mutation
     * after the transaction has ended. A sensitive endpoint MUST run its
     * protected operation in the SAME locked authorization transaction.
     * Caller supplies a signature-validated V5 token; we validate it again.
     * @return array<string,mixed>|null
     */
    public function currentAssuranceSnapshot(#[\SensitiveParameter] string $jwt): ?array
    {
        $claims = $this->access->verifyAccess($jwt, $this->peekTime());
        if ($claims === null) return null;
        $this->assertReady();
        $userId = self::positive($claims['sub'] ?? null);
        if ($userId === null) return null;
        try {
            return $this->transaction(function () use ($claims, $userId): ?array {
                $now = $this->dbTime();
                $this->lockUser($userId);
                $this->requireNativeCoverage($userId);
                $epoch = $this->lockedEpoch($userId);
                $row = $this->lockedSession($claims['sid']);
                $state = $this->validatedState($row, $userId, $now);
                if ($state === null || $epoch !== $state['auth_epoch']
                    || !V5SessionAssurancePolicy::permitsProtectedAction($claims, $state, $now)) {
                    return null;
                }
                return $state;
            });
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Protected bonus-PIN retrieval. Authorization and the private read MUST
     * happen under the SAME owned transaction and b_user mutex. Unlike
     * currentAssuranceSnapshot(), this does not release the session lock
     * before reading the protected value. There is no additional SMS proof:
     * access must already come from strong TOTP, SMS or enrolled trust.
     *
     * Returns null for ALL denied/unknown states; never log the bearer or PIN.
     * The site PIN reader is intentionally unimplemented until staging.
     */
    public function showBonusPinForAccess(
        #[\SensitiveParameter] string $accessJwt,
        V5LockedBonusPinReaderPort $reader
    ): ?string {
        try {
            $claims = $this->access->verifyAccess($accessJwt, $this->peekTime());
            if ($claims === null) return null;
            $this->assertReady();
            $userId = self::positive($claims['sub'] ?? null);
            if ($userId === null) return null;
            return $this->transaction(function () use ($claims, $userId, $reader): ?string {
                $now = $this->dbTime();
                $this->lockUser($userId);
                $this->requireNativeCoverage($userId);
                $epoch = $this->lockedEpoch($userId);
                $row = $this->lockedSession($claims['sid']);
                $state = $this->validatedState($row, $userId, $now);
                if ($state === null || $epoch !== $state['auth_epoch']
                    || !V5SessionAssurancePolicy::permitsProtectedAction($claims, $state, $now)
                ) return null;

                $pin = $reader->readLockedBonusPin($this->db, $userId);
                // Never guess a site-specific PIN format or strip leading zeros.
                // Refuse unexpectedly large values and binary control bytes.
                return is_string($pin) && $pin !== ''
                    && strlen($pin) <= 128
                    && preg_match('/[\x00-\x1f\x7f]/', $pin) !== 1
                    ? $pin : null;
            });
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Authenticated cart read for real existing Bitrix CartApiService.
     * The reader runs under the same user->epoch->session locks/transaction,
     * not after a detached snapshot. Missing provider/owner/FUSER is denial.
     *
     * Unlike V4 Cart controller, never invoke CUser::Authorize() or use
     * client-request FUSER_ID / legacy JwtAuthorization.
     *
     * @return array{items:list<array<string,mixed>>,total:array<string,mixed>}|null
     */
    public function readCartForAccess(
        #[\SensitiveParameter] string $accessJwt,
        V5LockedCartReaderPort $reader
    ): ?array {
        try {
            $claims = $this->access->verifyAccess($accessJwt, $this->peekTime());
            if ($claims === null) return null;
            $this->assertReady();
            $userId = self::positive($claims['sub'] ?? null);
            if ($userId === null) return null;
            return $this->transaction(function () use ($claims, $userId, $reader): ?array {
                $now = $this->dbTime();
                $this->lockUser($userId);
                $this->requireNativeCoverage($userId);
                $epoch = $this->lockedEpoch($userId);
                $row = $this->lockedSession($claims['sid']);
                $state = $this->validatedState($row, $userId, $now);
                if ($state === null || $epoch !== $state['auth_epoch']
                    || !V5SessionAssurancePolicy::permitsProtectedAction($claims, $state, $now)
                ) return null;

                $snapshot = $reader->readLockedCart($this->db, $userId);
                return is_array($snapshot) && count($snapshot) === 2
                    && isset($snapshot['items'], $snapshot['total'])
                    && is_array($snapshot['items'])
                    && array_is_list($snapshot['items'])
                    && is_array($snapshot['total'])
                    && !array_is_list($snapshot['total'])
                    ? $snapshot : null;
            });
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Mutate an existing cart line after strong V5 user/session/epoch proof.
     * quantity=null removes it. true is returned only AFTER transaction
     * COMMIT; null denotes denied auth, false native write failure.
     */
    public function changeCartForAccess(
        #[\SensitiveParameter] string $accessJwt,
        int $itemId, ?int $quantity,
        V5LockedCartMutatorPort $mutator,
    ): ?bool {
        if ($itemId < 1 || $itemId > 2147483647
            || ($quantity !== null && ($quantity < 1 || $quantity > 1000))) {
            return false;
        }
        try {
            $claims = $this->access->verifyAccess($accessJwt, $this->peekTime());
            if ($claims === null) return null;
            $this->assertReady();
            $userId = self::positive($claims['sub'] ?? null);
            if ($userId === null) return null;
            return $this->transaction(function () use (
                $claims, $userId, $itemId, $quantity, $mutator
            ): ?bool {
                $now = $this->dbTime();
                $this->lockUser($userId);
                $this->requireNativeCoverage($userId);
                $epoch = $this->lockedEpoch($userId);
                $row = $this->lockedSession($claims['sid']);
                $state = $this->validatedState($row, $userId, $now);
                if ($state === null || $epoch !== $state['auth_epoch']
                    || !V5SessionAssurancePolicy::permitsProtectedAction(
                        $claims, $state, $now
                    )) return null;

                if ($mutator->changeLockedCartItem(
                    $this->db, $userId, $itemId, $quantity
                ) !== true) {
                    throw new RuntimeException('v5_cart_mutation_unconfirmed');
                }
                return true;
            });
        } catch (Throwable) {
            // Transaction owner performs rollback on native failure.
            return false;
        }
    }

    /**
     * Only add a *new* server-verified catalog product; no user/FUSER/price
     * fields are taken from the app. Return persisted item ID after COMMIT.
     */
    public function addCartItemForAccess(
        #[\SensitiveParameter] string $accessJwt, int $productId, int $quantity,
        V5LockedCartAddPort $adder,
    ): ?int {
        if ($productId < 1 || $productId > 2147483647
            || $quantity < 1 || $quantity > 1000) return null;
        try {
            $claims=$this->access->verifyAccess($accessJwt,$this->peekTime());
            if ($claims===null) return null;
            $this->assertReady();
            $userId=self::positive($claims['sub'] ?? null);
            if ($userId===null) return null;
            return $this->transaction(function () use (
                $claims,$userId,$productId,$quantity,$adder
            ): ?int {
                $now=$this->dbTime();
                $this->lockUser($userId);
                $this->requireNativeCoverage($userId);
                $epoch=$this->lockedEpoch($userId);
                $row=$this->lockedSession($claims['sid']);
                $state=$this->validatedState($row,$userId,$now);
                if ($state===null || $epoch!==$state['auth_epoch']
                    || !V5SessionAssurancePolicy::permitsProtectedAction(
                        $claims,$state,$now
                    )) return null;
                $basketId=$adder->addLockedCatalogItem(
                    $this->db,$userId,$productId,$quantity
                );
                if ($basketId<1 || $basketId>2147483647) {
                    throw new RuntimeException('v5_cart_add_unconfirmed');
                }
                return $basketId;
            });
        } catch (Throwable) {
            // The strong-session transaction rolls back on native failure.
            return null;
        }
    }

    /**
     * Independent V5 profile read under the existing strong V5 bearer,
     * Bitrix b_user, AUTH_EPOCH and session row locks.
     *
     * @return array<string,int|string|null>|null
     */
    public function readProfileForAccess(
        #[\SensitiveParameter] string $accessJwt,
        V5LockedProfileReaderPort $reader
    ): ?array {
        try {
            $claims = $this->access->verifyAccess($accessJwt, $this->peekTime());
            if ($claims === null) return null;
            $this->assertReady();
            $userId = self::positive($claims['sub'] ?? null);
            if ($userId === null) return null;
            return $this->transaction(function () use ($claims, $userId, $reader): ?array {
                $now = $this->dbTime();
                $this->lockUser($userId);
                $this->requireNativeCoverage($userId);
                $epoch = $this->lockedEpoch($userId);
                $row = $this->lockedSession($claims['sid']);
                $state = $this->validatedState($row, $userId, $now);
                if ($state === null || $epoch !== $state['auth_epoch']
                    || !V5SessionAssurancePolicy::permitsProtectedAction($claims, $state, $now)
                ) return null;

                $profile = $reader->readLockedProfile($this->db, $userId);
                return is_array($profile) && ($profile['id'] ?? null) === $userId
                    ? $profile : null;
            });
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Read a user's order history with the existing V5 access JWT, locked
     * b_user, live auth epoch and strong-session authorization.
     *
     * @return list<array<string,int|string|float|bool|null>>|null
     */
    public function readOrdersForAccess(
        #[\SensitiveParameter] string $accessJwt,
        V5LockedOrdersReaderPort $reader
    ): ?array {
        try {
            $claims = $this->access->verifyAccess($accessJwt, $this->peekTime());
            if ($claims === null) return null;
            $this->assertReady();
            $userId = self::positive($claims['sub'] ?? null);
            if ($userId === null) return null;
            return $this->transaction(function () use ($claims, $userId, $reader): ?array {
                $now = $this->dbTime();
                $this->lockUser($userId);
                $this->requireNativeCoverage($userId);
                $epoch = $this->lockedEpoch($userId);
                $row = $this->lockedSession($claims['sid']);
                $state = $this->validatedState($row, $userId, $now);
                if ($state === null || $epoch !== $state['auth_epoch']
                    || !V5SessionAssurancePolicy::permitsProtectedAction($claims, $state, $now)
                ) return null;

                $orders = $reader->readLockedOrders($this->db, $userId);
                return is_array($orders) && array_is_list($orders)
                    && count($orders) <= 50 ? $orders : null;
            });
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Protected detail lookup uses the SAME V5 strong-session authorization
     * and transaction as the existing orders list.
     *
     * @return array<string,mixed>|null
     */
    public function readOrderDetailForAccess(
        #[\SensitiveParameter] string $accessJwt,
        int $orderId,
        V5LockedOrderDetailReaderPort $reader,
    ): ?array {
        if ($orderId < 1) return null;
        try {
            $claims = $this->access->verifyAccess($accessJwt, $this->peekTime());
            if ($claims === null) return null;
            $this->assertReady();
            $userId = self::positive($claims['sub'] ?? null);
            if ($userId === null) return null;
            return $this->transaction(function () use ($claims, $userId, $orderId, $reader): ?array {
                $now = $this->dbTime();
                $this->lockUser($userId);
                $this->requireNativeCoverage($userId);
                $epoch = $this->lockedEpoch($userId);
                $row = $this->lockedSession($claims['sid']);
                $state = $this->validatedState($row, $userId, $now);
                if ($state === null || $epoch !== $state['auth_epoch']
                    || !V5SessionAssurancePolicy::permitsProtectedAction($claims, $state, $now)
                ) return null;

                $detail = $reader->readLockedOrderDetail($this->db, $userId, $orderId);
                return is_array($detail) && ($detail['id'] ?? null) === $orderId
                    && isset($detail['items'])
                    && is_array($detail['items']) && array_is_list($detail['items'])
                    && count($detail['items']) <= 200 ? $detail : null;
            });
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * V5-owned checkout path. Same strong JWT, Bitrix user, epoch and
     * revocation checks as order detail, followed by a native D7 writer.
     * Any writer failure throws to ROLLBACK (never COMMIT a partial order).
     * The native writer itself must enforce durable request-id uniqueness.
     *
     * @return array{id:int,number:string,status:string}|null
     */
    public function placeOrderForAccess(
        #[\SensitiveParameter] string $accessJwt,
        string $requestId,
        array $checkout,
        V5LockedOrderPlacementPort $writer,
    ): ?array {
        if (preg_match('/\A(?:[a-f0-9]{32}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\\z/D',
            $requestId) !== 1 || count($checkout) < 1) return null;
        try {
            $claims = $this->access->verifyAccess($accessJwt, $this->peekTime());
            if ($claims === null) return null;
            $this->assertReady();
            $userId = self::positive($claims['sub'] ?? null);
            if ($userId === null) return null;
            return $this->transaction(function () use ($claims, $userId, $requestId, $checkout, $writer): ?array {
                $now = $this->dbTime();
                $this->lockUser($userId);
                $this->requireNativeCoverage($userId);
                $epoch = $this->lockedEpoch($userId);
                $row = $this->lockedSession($claims['sid']);
                $state = $this->validatedState($row, $userId, $now);
                if ($state === null || $epoch !== $state['auth_epoch']
                    || !V5SessionAssurancePolicy::permitsProtectedAction($claims, $state, $now)) {
                    return null;
                }
                $result = $writer->placeLockedOrder($this->db, $userId, $requestId, $checkout);
                if (!is_array($result)
                    || ($result['saved'] ?? null) !== true
                    || ($result['user_id'] ?? null) !== $userId
                    || ($result['request_id'] ?? null) !== $requestId
                    || !is_int($result['id'] ?? null) || $result['id'] < 1
                    || !is_string($result['number'] ?? null) || $result['number'] === ''
                    || !is_string($result['status'] ?? null) || $result['status'] === '') {
                    throw new RuntimeException('checkout_save_unconfirmed');
                }
                return [
                    'id' => $result['id'],
                    'number' => $result['number'],
                    'status' => $result['status'],
                ];
            });
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * All issuance gates must pass before inserting any session. The mandatory
     * injected proof ports intentionally have NO production implementation.
     */
    private function issue(
        int $userId, string $deviceId, string $platform, string $assurance,
        ?string $grantReference, callable $proof,
        #[\SensitiveParameter] ?string $trustToken = null,
    ): AuthenticatedV5Session {
        if ($userId < 1 || !V5LoginWorkflow::validInstallation($deviceId, $platform)
            || !in_array($assurance, ['totp','sms','email','trusted'], true)
            || ($grantReference !== null && !V5LoginWorkflow::validChallengeId($grantReference))
            || ($grantReference === null && $assurance !== 'trusted')
            || ($grantReference !== null && $assurance === 'trusted')
            || ($assurance === 'trusted' && $trustToken === null)
            || ($assurance !== 'trusted' && $trustToken !== null)) {
            throw new AuthenticationRejected('authentication_failed');
        }
        $this->assertReady();
        $committed = $this->transaction(function () use (
            $userId, $deviceId, $platform, $assurance, $grantReference, $proof
        ): array {
            $now = $this->dbTime();
            if ($now > PHP_INT_MAX - $this->sessionTtlSeconds) {
                throw new RuntimeException('session_clock_unavailable');
            }
            $this->lockUser($userId);
            // The proof port may inspect native MFA/verified phone, but MUST
            // not independently commit or start a nested transaction.
            if ($proof() !== true) throw new AuthenticationRejected('authentication_failed');
            $this->requireNativeCoverage($userId);
            $epoch = $this->lockedEpoch($userId);
            $sid = bin2hex(random_bytes(32));
            $refresh = $this->refreshTokens->issue();
            $grantDigest = $grantReference === null ? null :
                hash_hmac('sha256', "evrasia:v5:consumed-grant:v1\0" . $grantReference,
                    $this->bindingKey);
            $deviceBinding = $assurance === 'trusted'
                ? $this->lockedPresentedTrustBinding(
                    $userId, $deviceId, $platform, (string)$trustToken, $now
                )
                : hash_hmac('sha256', "evrasia:v5:device-binding:v1\0"
                    . $deviceId . "\0" . $platform, $this->bindingKey);
            if ($deviceBinding === null) {
                throw new AuthenticationRejected('authentication_failed');
            }
            $q = $this->query('INSERT INTO ev_v5_strong_sessions '
                . '(sid, user_id, auth_epoch, assurance, device_binding, platform, '
                . 'assured_at, expires_at, status, refresh_digest, refresh_generation, '
                . 'grant_digest, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $sid, $userId, $epoch, $assurance, $deviceBinding, $platform,
                $now, $now + $this->sessionTtlSeconds, 'active',
                $refresh['digest'], 1, $grantDigest, $now, $now,
            ]);
            if ($q->rowCount() !== 1) throw new RuntimeException('session_insert_failed');
            $state = ['version'=>'v5','status'=>'active','revocation_verified'=>true,
                'sid'=>$sid,'user_id'=>$userId,'auth_epoch'=>$epoch,
                'assurance'=>$assurance,'assured_at'=>$now,
                'expires_at'=>$now+$this->sessionTtlSeconds];
            // Only the committed session snapshot may authorize a V5 JWT.
            return ['state'=>$state, 'now'=>$now, 'refreshToken'=>$refresh['token']];
        });
        return $this->issueCommittedCredentials($committed);
    }

    /**
     * This method runs ONLY after the owning transaction reported COMMIT.
     * The caller must not hand out either bearer on an uncertain commit.
     * No additional database read is needed; protected actions still recheck
     * current revocation and AUTH_EPOCH within their own locked transaction.
     *
     * @param array{state:array<string,mixed>,now:int,refreshToken:string} $committed
     */
    private function issueCommittedCredentials(array $committed): AuthenticatedV5Session
    {
        return new AuthenticatedV5Session(
            $this->access->issueForCommittedSession(
                $committed['state'], $committed['now']
            ),
            $committed['refreshToken']
        );
    }

    private function assertReady(): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $this->db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $this->db->inTransaction()) {
            throw new RuntimeException('session_connection_unavailable');
        }
        $q=$this->prepare('SELECT ENGINE FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        foreach (self::TABLES as $name) {
            if (!$q->execute([$name]) || $q->fetchColumn() !== 'InnoDB') {
                throw new RuntimeException('session_table_unavailable');
            }
        }
    }

    /** A transaction owned by this method only. Deny on uncertain commit. */
    private function transaction(callable $operation): mixed
    {
        $started=false;
        $nativeGuardOpen=false;
        $nativeGuardReleaseStarted=false;
        try {
            if (!$this->db->beginTransaction()) {
                throw new RuntimeException('session_begin_unavailable');
            }
            $started=true;
            // A savepoint is destroyed by an implicit COMMIT, including when
            // native code immediately starts another transaction afterwards.
            $this->query('SAVEPOINT v5_strong_session_guard', []);
            $nativeGuardOpen=true;
            $result=$operation();
            $nativeGuardReleaseStarted=true;
            $this->query('RELEASE SAVEPOINT v5_strong_session_guard', []);
            $nativeGuardOpen=false;
            // A native proof/read callback may not close our transaction.
            // Never sign JWTs or expose a PIN after that boundary is lost.
            if (!$this->db->inTransaction()) {
                throw new RuntimeException('session_transaction_lost');
            }
            if (!$this->db->commit()) throw new RuntimeException('session_commit_unavailable');
            $started=false;
            return $result;
        } catch (Throwable $error) {
            $nativeBoundaryLost=false;
            if ($started && $nativeGuardOpen) {
                // A native locked proof/PIN/cart reader may have COMMITted
                // the old transaction and begun a new one. inTransaction()
                // is NOT proof that V5 still owns the same transaction.
                if (!$nativeGuardReleaseStarted) {
                    $nativeGuardReleaseStarted=true;
                    try {
                        $this->query('RELEASE SAVEPOINT v5_strong_session_guard', []);
                        $nativeGuardOpen=false;
                    } catch (Throwable) {
                        // Lost savepoint: never roll back a foreign scope.
                    }
                }
                if ($nativeGuardOpen) {
                    $nativeBoundaryLost=true;
                    if ($this->db instanceof V5BitrixPdoCompatibilityBridge) {
                        $this->db->abandonUncertainNativeTransaction();
                    }
                    $started=false; // Do not ROLLBACK an unknown owner.
                }
            }
            if ($started && $this->db->inTransaction()) {
                try { $this->db->rollBack(); } catch (Throwable) { /* deny */ }
            }
            // Unknown native transaction ownership is a server fault,
            // never a valid signed JWT, PIN result or credential decision.
            if ($nativeBoundaryLost) {
                throw new RuntimeException('session_unavailable');
            }
            // Distinguish rejected credentials from an unavailable native DB,
            // without exposing account status, token generation, or SQL.
            if ($error instanceof AuthenticationRejected) {
                throw new AuthenticationRejected('authentication_failed');
            }
            throw new RuntimeException('session_unavailable');
        }
    }

    private function peekTime(): int
    {
        $this->assertReady();
        return $this->dbTime();
    }

    private function dbTime(): int
    {
        $q=$this->query('SELECT UNIX_TIMESTAMP()', []);
        $now=self::positive($q->fetchColumn());
        if ($now === null || $now >= PHP_INT_MAX - 604800) {
            throw new RuntimeException('session_clock_unavailable');
        }
        return $now;
    }

    private function lockUser(int $userId): void
    {
        $r=$this->unique('SELECT ID, ACTIVE, BLOCKED, PASSWORD_EXPIRED FROM b_user '
            . 'WHERE ID = ? FOR UPDATE', [$userId]);
        if ($r === null || self::positive($r['ID'] ?? null) !== $userId
            || ($r['ACTIVE'] ?? null) !== 'Y'
            || ($r['BLOCKED'] ?? null) !== 'N'
            || ($r['PASSWORD_EXPIRED'] ?? null) !== 'N') {
            throw new AuthenticationRejected('authentication_failed');
        }
    }

    private function requireNativeCoverage(int $userId): void
    {
        if ($this->nativeCoverage->fullyCovered($this->db, $userId) !== true) {
            throw new RuntimeException('native_epoch_coverage_unverified');
        }
    }

    private function lockedEpoch(int $userId): int
    {
        $r=$this->unique('SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state '
            . 'WHERE USER_ID = ? FOR UPDATE', [$userId]);
        $epoch=self::positive($r['AUTH_EPOCH'] ?? null);
        if ($epoch === null) throw new RuntimeException('native_epoch_unavailable');
        return $epoch;
    }

    /** @return array<string,mixed>|null */
    private function lockedSession(string $sid): ?array
    {
        return $this->unique('SELECT sid, user_id, auth_epoch, assurance, device_binding, platform, assured_at, '
            . 'expires_at, status, refresh_digest, refresh_generation '
            . 'FROM ev_v5_strong_sessions WHERE sid = ? FOR UPDATE', [$sid]);
    }

    /**
     * A trust-derived session must remain bound to the exact CURRENT token
     * hash/version, not merely to the original successful login. Every
     * refresh, logout, PIN and protected action passes through this gate
     * while the V5-owned b_user transaction remains open.
     */
    private function validatedState(?array $row, int $userId, int $now): ?array
    {
        $state = self::state($row, $userId, $now);
        if ($state === null || $state['assurance'] !== 'trusted') {
            return $state;
        }
        $platform = $row['platform'] ?? null;
        $binding = $row['device_binding'] ?? null;
        if (!is_string($platform) || !in_array($platform, ['ios', 'android'], true)
            || !is_string($binding)
            || preg_match('/\A[a-f0-9]{64}\z/D', $binding) !== 1) {
            return null;
        }
        // One indexed user-scope read, never a global device scan. FOR UPDATE
        // serializes V5 validation against concurrent V4 trust rotation/revoke.
        $q = $this->query(self::TRUST_CURRENT_SQL, [$userId]);
        $matches = 0;
        while (($trustRow = $q->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($trustRow)) {
                throw new RuntimeException('trusted_session_row_unavailable');
            }
            $candidate = V5TrustedSessionBinding::current(
                $trustRow, $userId, $now, $platform, $this->bindingKey
            );
            if (is_string($candidate) && hash_equals($binding, $candidate)) {
                $matches++;
                if ($matches > 1) {
                    throw new RuntimeException('trusted_session_ambiguous');
                }
            }
        }
        return $matches === 1 ? $state : null;
    }

    /**
     * Called ONLY after a successful fresh locked device+token proof.
     * Store the keyed credential-version binding, never the token or hash.
     */
    private function lockedPresentedTrustBinding(
        int $userId, string $deviceId, string $platform,
        #[\SensitiveParameter] string $token, int $now
    ): ?string {
        $deviceHash = hash('sha256', hash('sha256', $deviceId));
        $row = $this->unique(self::TRUST_PRESENTED_SQL, [$userId, $deviceHash]);
        return $row === null ? null : V5TrustedSessionBinding::presented(
            $deviceId, $platform, $token, $row, $userId, $now, $this->bindingKey
        );
    }

    private const TRUST_FIELDS = 'SELECT USER_ID, DEVICE_ID_HASH, TRUST_TOKEN_HASH, '
        . 'TOKEN_VERSION, STATUS, UNIX_TIMESTAMP(TOKEN_EXPIRES_AT) AS expires_epoch, USER_AGENT '
        . 'FROM ev_trusted_devices ';
    private const TRUST_PRESENTED_SQL = self::TRUST_FIELDS
        . 'WHERE USER_ID = ? AND DEVICE_ID_HASH = ? FOR UPDATE';
    private const TRUST_CURRENT_SQL = self::TRUST_FIELDS
        . 'WHERE USER_ID = ? FOR UPDATE';

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private static function state(?array $row, int $userId, int $now): ?array
    {
        if ($row === null || ($row['sid'] ?? null) === null
            || !is_string($row['sid']) || !self::hex64($row['sid'])
            || self::positive($row['user_id'] ?? null) !== $userId
            || ($row['status'] ?? null) !== 'active'
            || !in_array($row['assurance'] ?? null, ['totp','sms','email','trusted'], true)
        ) return null;
        $epoch=self::positive($row['auth_epoch'] ?? null);
        $assured=self::positive($row['assured_at'] ?? null);
        $expires=self::positive($row['expires_at'] ?? null);
        if ($epoch === null || $assured === null || $expires === null
            || $assured > $now || $expires <= $now || $expires <= $assured) return null;
        return ['version'=>'v5','status'=>'active','revocation_verified'=>true,
            'sid'=>$row['sid'],'user_id'=>$userId,'auth_epoch'=>$epoch,
            'assurance'=>$row['assurance'],'assured_at'=>$assured,'expires_at'=>$expires];
    }

    /** @return array<string,mixed>|null */
    private function unique(string $sql, array $params): ?array
    {
        $q=$this->query($sql,$params);
        $first=$q->fetch(PDO::FETCH_ASSOC);
        if ($first===false) return null;
        if (!is_array($first) || $q->fetch(PDO::FETCH_ASSOC)!==false) {
            throw new RuntimeException('session_row_ambiguous');
        }
        return $first;
    }

    private function query(string $sql, array $params): PDOStatement
    {
        $q=$this->prepare($sql);
        if (!$q->execute($params)) throw new RuntimeException('session_query_unavailable');
        return $q;
    }

    private function prepare(string $sql): PDOStatement
    {
        $q=$this->db->prepare($sql);
        if (!$q instanceof PDOStatement) throw new RuntimeException('session_query_unavailable');
        return $q;
    }

    private static function positive(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D',$value)!==1)return null;
        $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $n===false?null:$n;
    }

    private static function hex64(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D',$value)===1;
    }
}
