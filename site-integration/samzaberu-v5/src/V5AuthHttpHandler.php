<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5LoginWorkflow.php';
require_once __DIR__ . '/V5LegalConsentContract.php';
require_once __DIR__ . '/V5PublicLegalCodeValidation.php';
require_once __DIR__ . '/V5CheckoutRequest.php';
require_once __DIR__ . '/V5CartMutationRequest.php';

use JsonException;
use Throwable;

final readonly class V5HttpReply
{
    public function __construct(
        public int $status,
        public array $body,
    ) {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('Invalid HTTP status');
        }
    }
}

/**
 * Optional STAGING-ONLY strong V5 operations. Never supplied by the
 * current production /api/v5/router.php, which remains HTTP 503.
 * Implementations MUST verify tokens, epoch, revocation, and locked
 * bonus-PIN reader state independently of any client JSON claims.
 */
interface V5SessionActionsPort
{
    public function rotateRefresh(string $refreshToken): AuthenticatedV5Session;
    public function readBonusPin(string $accessJwt): ?string;
}

/**
 * Token verification is strictly server-backed: cryptographic validation,
 * committed strong session, current user state, revocation and AUTH_EPOCH.
 * Missing native proof MUST NOT be interpreted as a valid JWT.
 */
interface V5SessionLogoutPort
{
    /**
     * Revoke precisely this verified V5 access session; reject stale,
     * V4, expired or re-used bearers. Never accept client-supplied user IDs.
     */
    public function revoke(#[\SensitiveParameter] string $accessJwt): bool;
}

/**
 * Server-validated V5 cart GET, no caller-supplied FUSER or user ID.
 * The native business-service adapter is required; no V4-controller/JWT call.
 */
/**
 * Existing-basket-line mutation after verified V5 JWT, native user lock and
 * same transaction. Success is only returned after commit, not upon staging.
 */
/** Server-authorized add-by-catalog-product-ID; returns native line ID. */
interface V5CartHttpAddPort
{
    public function addVerifiedCartProduct(
        #[\SensitiveParameter] string $accessJwt,
        int $productId, int $quantity
    ): ?int;
}

interface V5CartHttpMutationPort
{
    public function changeVerifiedCartItem(
        #[\SensitiveParameter] string $accessJwt, int $itemId, ?int $quantity
    ): ?bool;
}

interface V5CartHttpReaderPort
{
    /** @return array{items:list<array<string,mixed>>,total:array<string,mixed>}|null */
    public function readVerifiedCart(#[\SensitiveParameter] string $accessJwt): ?array;
}

/**
 * Native profile identity comes only from the already authenticated V5 JWT.
 * No client-supplied Bitrix user ID or V4 profile handler.
 */
interface V5ProfileHttpReaderPort
{
    /** @return array<string,int|string|null>|null */
    public function readVerifiedProfile(#[\SensitiveParameter] string $accessJwt): ?array;
}

/** Order history is bound to the user in the verified V5 JWT. */
interface V5OrdersHttpReaderPort
{
    /** @return list<array<string,int|string|float|bool|null>>|null */
    public function readVerifiedOrders(#[\SensitiveParameter] string $accessJwt): ?array;
}

interface V5OrderDetailHttpReaderPort
{
    /** @return array<string,mixed>|null */
    public function readVerifiedOrderDetail(
        #[\SensitiveParameter] string $accessJwt, int $orderId
    ): ?array;
}

/**
 * Only a V5 session-bound native checkout writer may place an order.
 * No V4 controller/JWT and no client-supplied Bitrix USER_ID or FUSER_ID.
 */
interface V5OrderPlaceHttpPort
{
    /** @return array{id:int,number:string,status:string}|null */
    public function placeVerifiedOrder(
        #[\SensitiveParameter] string $accessJwt,
        string $requestId,
        array $checkout
    ): ?array;
}

interface V5TokenValidationPort
{
    public function isValidAccessToken(string $accessJwt): bool;
}

/**
 * Trusted, public legal-document catalog from current server-side revisions.
 * It has no user identity or accepted flags, and it never stores consent.
 */
interface V5PublicLegalConsentsPort
{
    /** @return list<array<string,mixed>> */
    public function currentPublicDocuments(): array;
}

/**
 * Native signup writer. Must create the Bitrix USER_ID and persist current
 * Bitrix consent events BEFORE reporting success. Must recheck accepted
 * document codes against native agreement rows within its write operation.
 * Never accepts a caller-supplied user_id, JWT, or trusted device.
 */
interface V5NativeSignupHttpPort
{
    /** @param array<string,mixed> $registration @param list<string> $codes */
    public function createWithNativeConsents(array $registration, array $codes): ?int;
}

/**
 * Offline JSON transport adapter for the V5 login workflow.
 * The actual website /api/v5/router.php remains disabled (503), and no
 * Bitrix/password, OTP, SMS, session or token backend is wired here.
 *
 * A future HTTP entrypoint must enforce HTTPS, POST for auth, GET for public legal, content type,
 * request size limits, Cache-Control:no-store and no credential logging.
 */
final readonly class V5AuthHttpHandler
{
    public function __construct(
        private ?V5LoginWorkflow $workflow = null,
        private ?V5SessionActionsPort $sessionActions = null,
        private ?V5TokenValidationPort $tokenValidator = null,
        private ?V5PublicLegalConsentsPort $publicLegalConsents = null,
        private ?V5SessionLogoutPort $logout = null,
        private ?V5CartHttpReaderPort $cartReader = null,
        private ?V5ProfileHttpReaderPort $profileReader = null,
        private ?V5OrdersHttpReaderPort $ordersReader = null,
        private ?V5OrderDetailHttpReaderPort $orderDetailReader = null,
        private ?V5OrderPlaceHttpPort $orderPlace = null,
        private ?V5CartHttpMutationPort $cartMutation = null,
        private ?V5CartHttpAddPort $cartAdd = null,
        private ?V5NativeSignupHttpPort $signup = null,
    ) {}

    /**
     * $action MUST come from server-side V5RouteMap dispatch, never a client
     * supplied body attribute. Unsupported actions remain unavailable.
     */
    public function handleJson(string $action, string $rawJson): V5HttpReply
    {
        if (strlen($rawJson) > 8192 || $rawJson === '') {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        try {
            $body = json_decode($rawJson, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($body) || array_is_list($body)) {
                return new V5HttpReply(400, ['error' => 'invalid_request']);
            }

            return match ($action) {
                'signin' => $this->workflow === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->signIn($body),
                'signup' => $this->signup === null || $this->publicLegalConsents === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->signup($body),
                'verifyTotp' => $this->workflow === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->verify($body, ChallengeKind::TOTP),
                'verifyEmail' => $this->workflow === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->verify($body, ChallengeKind::EMAIL),
                'verifySms' => $this->workflow === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->verify($body, ChallengeKind::SMS),
                'verifyChallenge' => $this->workflow === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->verify($body),
                'resendChallenge' => $this->workflow === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->resend($body),
                'requestSmsFallback' => $this->workflow === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->requestSmsFallback($body),
                // Without explicit verified V5 operations, HTTP remains 503.
                'refresh' => $this->sessionActions === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->refresh($body),
                'logout' => $this->logout === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->logout($body),
                'showBonusPin' => $this->sessionActions === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->showBonusPin($body),
                'validateToken' => $this->tokenValidator === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->validateToken($body),
                'readOrderDetail' => $this->orderDetailReader === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->readOrderDetail($body),
                'placeOrder' => $this->orderPlace === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->placeOrder($body),
                'setCartItemQuantity', 'removeCartItem' => $this->cartMutation === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->changeCartItem($body, $action),
                'addCartItem' => $this->cartAdd === null
                    ? new V5HttpReply(503, ['error'=>'v5_unavailable'])
                    : $this->addCartItem($body),
                'validateLegalConsents' => $this->publicLegalConsents === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->validateLegalConsents($body),
                default => new V5HttpReply(503, ['error' => 'v5_unavailable']),
            };
        } catch (AuthenticationRejected $e) {
            // Do not expose whether the account exists, which factor failed,
            // retry counts, current SMS limits or the upstream exception.
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        } catch (JsonException|\InvalidArgumentException $e) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        } catch (Throwable $e) {
            return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
        }
    }

    /**
     * GET-only public document catalog. Never returns user-specific acceptance
     * and never writes to b_consent_user_consent. No JWT or cookie is required.
     */
    public function handlePublicLegalConsents(): V5HttpReply
    {
        if ($this->publicLegalConsents === null) {
            return new V5HttpReply(503, ['error' => 'v5_unavailable']);
        }
        try {
            $documents = V5LegalConsentContract::publicDocuments(
                $this->publicLegalConsents->currentPublicDocuments()
            );
            if ($documents === null) {
                return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
            }
            return new V5HttpReply(200, ['documents' => $documents]);
        } catch (Throwable) {
            return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
        }
    }

    /**
     * V5 registration document list reuses the SAME current native Bitrix
     * agreement catalog as GET /legal/consents. It returns a list of required
     * and optional current documents; it does not acknowledge or save consent,
     * create an account, use V4 HTTP/JWT, or write b_consent_user_consent.
     *
     * The format intentionally matches V5 legal/consents, not an assumed V4
     * legacy registration-documents DTO.
     */
    public function handlePublicRegistrationDocuments(): V5HttpReply
    {
        return $this->handlePublicLegalConsents();
    }

    /**
     * Protected GET /cart: the real V5 JWT MUST pass locked user, epoch,
     * assurance and session checks BEFORE calling the native cart service.
     */
    public function handleProtectedCartGet(#[\SensitiveParameter] string $accessJwt): V5HttpReply
    {
        if ($this->cartReader === null) {
            return new V5HttpReply(503, ['error' => 'v5_unavailable']);
        }
        if (strlen($accessJwt) < 110 || strlen($accessJwt) > 4096
            || preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D', $accessJwt) !== 1) {
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        }
        try {
            $cart = $this->cartReader->readVerifiedCart($accessJwt);
            if ($cart === null) {
                return new V5HttpReply(401, ['error' => 'authentication_failed']);
            }
            if (!is_array($cart) || count($cart) !== 2
                || !isset($cart['items'], $cart['total'])
                || !is_array($cart['items']) || !array_is_list($cart['items'])
                || !is_array($cart['total']) || array_is_list($cart['total'])) {
                return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
            }
            // Price/discount totals are directly calculated by installed V4.
            return new V5HttpReply(200, [
                'items' => $cart['items'], 'total' => $cart['total']
            ]);
        } catch (Throwable) {
            return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
        }
    }

    /**
     * GET /profile: native Bitrix b_user through V5's strong JWT/session,
     * with no V4 controller or cookie authentication.
     */
    public function handleProtectedProfileGet(#[\SensitiveParameter] string $accessJwt): V5HttpReply
    {
        if ($this->profileReader === null) {
            return new V5HttpReply(503, ['error' => 'v5_unavailable']);
        }
        if (strlen($accessJwt) < 110 || strlen($accessJwt) > 4096
            || preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D',
                $accessJwt) !== 1) {
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        }
        try {
            $profile = $this->profileReader->readVerifiedProfile($accessJwt);
            if ($profile === null) {
                return new V5HttpReply(401, ['error' => 'authentication_failed']);
            }
            if (!is_array($profile) || !is_int($profile['id'] ?? null)
                || $profile['id'] < 1) {
                return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
            }
            return new V5HttpReply(200, ['profile' => $profile]);
        } catch (Throwable) {
            return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
        }
    }

    /**
     * GET /orders uses the same server-side V5 session as profile and cart.
     * No client-provided USER_ID and no V4 auth/business controller.
     */
    public function handleProtectedOrdersGet(#[\SensitiveParameter] string $accessJwt): V5HttpReply
    {
        if ($this->ordersReader === null) {
            return new V5HttpReply(503, ['error' => 'v5_unavailable']);
        }
        if (strlen($accessJwt) < 110 || strlen($accessJwt) > 4096
            || preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D',
                $accessJwt) !== 1) {
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        }
        try {
            $orders = $this->ordersReader->readVerifiedOrders($accessJwt);
            if (!is_array($orders) || !array_is_list($orders)) {
                return new V5HttpReply(401, ['error' => 'authentication_failed']);
            }
            if (count($orders) > 50) {
                return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
            }
            return new V5HttpReply(200, ['orders' => $orders]);
        } catch (Throwable) {
            return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
        }
    }

    /**
     * POST /orders/detail with JSON {access_token,order_id} uses the
     * existing V5 token/session, never a client USER_ID or V4 handler.
     */
    private function readOrderDetail(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['access_token','order_id'])
            || !self::isString($body, 'access_token')
            || !isset($body['order_id'])
            || !is_int($body['order_id']) || $body['order_id'] < 1) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        $jwt = $body['access_token'];
        if (strlen($jwt) < 110 || strlen($jwt) > 4096
            || preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D', $jwt) !== 1) {
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        }
        try {
            $detail = $this->orderDetailReader->readVerifiedOrderDetail(
                $jwt, $body['order_id']
            );
            if ($detail === null) return new V5HttpReply(404, ['error' => 'order_not_found']);
            if (!is_array($detail) || ($detail['id'] ?? null) !== $body['order_id']
                || !isset($detail['items']) || !is_array($detail['items'])
                || !array_is_list($detail['items']) || count($detail['items']) > 200) {
                return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
            }
            return new V5HttpReply(200, ['order' => $detail]);
        } catch (Throwable) {
            return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
        }
    }

    /**
     * POST /cart/order is an independent V5 route. Checkout details still
     * require the existing site business validator, Sale D7 persistence and
     * payment policy in the injected native writer (not implemented here).
     */
    /**
     * Native V5-only existing item mutation. This is NOT an add-by-product
     * endpoint; the client supplies only its already assigned basket item ID.
     * Server supplies locked FUSER, price and user identity.
     */
    /** New items are identified by product ID; server sets all prices. */
    private function addCartItem(array $body): V5HttpReply
    {
        $request=V5CartMutationRequest::parseAdd($body);
        if ($request===null) return new V5HttpReply(400,['error'=>'invalid_request']);
        $jwt=$request['access_token'];
        if (strlen($jwt)<110 || strlen($jwt)>4096
            || preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D',$jwt)!==1) {
            return new V5HttpReply(401,['error'=>'authentication_failed']);
        }
        try {
            $lineId=$this->cartAdd->addVerifiedCartProduct(
                $jwt,$request['product_id'],$request['quantity']
            );
            if (!is_int($lineId) || $lineId<1 || $lineId>2147483647) {
                return new V5HttpReply(503,['error'=>'temporarily_unavailable']);
            }
            return new V5HttpReply(201,['item'=>[
                'id'=>$lineId,
                'product_id'=>$request['product_id'],
                'quantity'=>$request['quantity'],
            ]]);
        } catch (Throwable) {
            return new V5HttpReply(503,['error'=>'temporarily_unavailable']);
        }
    }

    private function changeCartItem(array $body, string $action): V5HttpReply
    {
        $request = V5CartMutationRequest::parse($body, $action);
        if ($request === null) {
            return new V5HttpReply(400, ['error'=>'invalid_request']);
        }
        $jwt = $request['access_token'];
        if (strlen($jwt) < 110 || strlen($jwt) > 4096
            || preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D', $jwt)!==1) {
            return new V5HttpReply(401, ['error'=>'authentication_failed']);
        }
        try {
            $saved=$this->cartMutation->changeVerifiedCartItem(
                $jwt, $request['item_id'], $request['quantity']
            );
            if ($saved === null) {
                return new V5HttpReply(401, ['error'=>'authentication_failed']);
            }
            if ($saved !== true) {
                return new V5HttpReply(503, ['error'=>'temporarily_unavailable']);
            }
            $result=['item_id'=>$request['item_id']];
            if ($request['quantity']===null) {
                $result['removed']=true;
            } else {
                $result['quantity']=$request['quantity'];
            }
            return new V5HttpReply(200,['item'=>$result]);
        } catch (Throwable) {
            return new V5HttpReply(503,['error'=>'temporarily_unavailable']);
        }
    }

    private function placeOrder(array $body): V5HttpReply
    {
        $normalized = V5CheckoutRequest::fromJson($body);
        if ($normalized === null) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        $jwt = $normalized['access_token'];
        if (strlen($jwt) < 110 || strlen($jwt) > 4096
            || preg_match('/\A[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+\\z/D', $jwt) !== 1) {
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        }
        try {
            $order = $this->orderPlace->placeVerifiedOrder(
                $jwt, $normalized['request_id'], $normalized['checkout']
            );
            if ($order === null) {
                return new V5HttpReply(401, ['error' => 'authentication_failed']);
            }
            if (!is_array($order) || !isset($order['id'], $order['number'], $order['status'])
                || !is_int($order['id']) || $order['id'] < 1
                || !is_string($order['number']) || $order['number'] === ''
                || !is_string($order['status']) || $order['status'] === '') {
                return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
            }
            return new V5HttpReply(201, ['order' => $order]);
        } catch (Throwable) {
            return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
        }
    }

    private function validateLegalConsents(array $body): V5HttpReply
    {
        $result = V5PublicLegalCodeValidation::evaluate(
            $body, $this->publicLegalConsents->currentPublicDocuments()
        );
        return new V5HttpReply($result['status'], $result['body']);
    }

    private function logout(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['access_token'])
            || !self::isString($body, 'access_token')) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        $jwt = $body['access_token'];
        if (strlen($jwt) < 110 || strlen($jwt) > 4096
            || substr_count($jwt, '.') !== 2) {
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        }
        return $this->logout->revoke($jwt)
            ? new V5HttpReply(200, ['status' => 'logged_out'])
            : new V5HttpReply(401, ['error' => 'authentication_failed']);
    }

    private function validateToken(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['access_token'])
            || !self::isString($body, 'access_token')) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        $token = $body['access_token'];
        if (strlen($token) < 110 || strlen($token) > 4096
            || substr_count($token, '.') !== 2) {
            return new V5HttpReply(401, ['valid' => false]);
        }
        return $this->tokenValidator->isValidAccessToken($token)
            ? new V5HttpReply(200, ['valid' => true])
            : new V5HttpReply(401, ['valid' => false]);
    }

    /**
     * First-time registration. Never creates a JWT or mobile trust token.
     * The app first GETs /legal/registration-documents, displays current
     * required and optional agreements, and submits accepted codes here.
     */
    private function signup(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['registration', 'agreements'])
            || !isset($body['registration'], $body['agreements'])
            || !is_array($body['registration'])
            || array_is_list($body['registration'])
            || count($body['registration']) < 1
            || count($body['registration']) > 16
            || !is_array($body['agreements'])) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        // Registration input is untrusted. No arbitrary USER_ID or token
        // fields can be relayed as authority to the native Bitrix writer.
        foreach ($body['registration'] as $field => $value) {
            if (!is_string($field)
                || preg_match('/\A[a-z][a-z0-9_]{0,40}\z/D', $field) !== 1
                || in_array($field, [
                    'user_id', 'id', 'access_token', 'refresh_token',
                    'trust_token', 'role', 'is_admin', 'confirmed',
                ], true)
                || (!is_string($value) && !is_bool($value))
                || (is_string($value) && strlen($value) > 4096)) {
                return new V5HttpReply(400, ['error' => 'invalid_request']);
            }
        }
        // Re-read the public catalog on EVERY submission; stale revision
        // codes are rejected, never silently accepted from yesterday.
        $current = $this->publicLegalConsents->currentPublicDocuments();
        $codes = V5LegalConsentContract::acceptedForSignup(
            $body['agreements'], $current
        );
        if ($codes === null) {
            return new V5HttpReply(409, ['error' => 'legal_documents_changed']);
        }
        $id = $this->signup->createWithNativeConsents(
            $body['registration'], $codes
        );
        if (!is_int($id) || $id < 1) {
            return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
        }
        return new V5HttpReply(201, [
            'status' => 'registered',
            'user_id' => $id,
            'next_step' => 'signin',
        ]);
    }

    private function signIn(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['login','password','device_id','platform','trust_token'])
            || !self::isString($body, 'login')
            || !self::isString($body, 'password')
            || !self::isString($body, 'device_id')
            || !self::isString($body, 'platform')
            || (array_key_exists('trust_token', $body)
                && $body['trust_token'] !== null
                && !is_string($body['trust_token']))
        ) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        $outcome = $this->workflow->signIn(
            $body['login'],
            $body['password'],
            $body['device_id'],
            $body['platform'],
            $body['trust_token'] ?? null,
        );

        if ($outcome instanceof PendingLoginChallenge) {
            return self::pending($outcome, 'challenge_required');
        }
        if ($outcome instanceof AuthenticatedV5Session) {
            return self::authenticated($outcome);
        }
        return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
    }

    private function verify(array $body, ?ChallengeKind $expectedKind = null): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['challenge_id','code','device_id','platform'])
            || !self::isString($body, 'challenge_id')
            || !self::isString($body, 'code')
            || !self::isString($body, 'device_id')
            || !self::isString($body, 'platform')
        ) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        return self::authenticated(
            $this->workflow->verifyChallenge(
                $body['challenge_id'],
                $body['code'],
                $body['device_id'],
                $body['platform'],
                $expectedKind,
            )
        );
    }

    private function resend(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['challenge_id','device_id','platform'])
            || !self::isString($body, 'challenge_id')
            || !self::isString($body, 'device_id')
            || !self::isString($body, 'platform')
        ) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        return self::pending(
            $this->workflow->resendSms(
                $body['challenge_id'],
                $body['device_id'],
                $body['platform'],
            ),
            'challenge_pending'
        );
    }

    private function requestSmsFallback(array $body): V5HttpReply
    {
        // Explicit request, never a signin parameter or an automatic resend.
        // No client phone, recipient email, user ID or factor override.
        if (!self::hasOnlyKeys($body, ['challenge_id','device_id','platform'])
            || !self::isString($body, 'challenge_id')
            || !self::isString($body, 'device_id')
            || !self::isString($body, 'platform')
        ) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        return self::pending(
            $this->workflow->requestSmsFallback(
                $body['challenge_id'], $body['device_id'], $body['platform']
            ),
            'challenge_pending'
        );
    }

    private function refresh(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['refresh_token'])
            || !self::isString($body, 'refresh_token')) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        if (preg_match('/\Av5r_[a-f0-9]{64}\z/D', $body['refresh_token']) !== 1) {
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        }
        return self::authenticated(
            $this->sessionActions->rotateRefresh($body['refresh_token'])
        );
    }

    private function showBonusPin(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['access_token'])
            || !self::isString($body, 'access_token')) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        $token = $body['access_token'];
        if (strlen($token) > 4096 || substr_count($token, '.') !== 2) {
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        }
        $pin = $this->sessionActions->readBonusPin($token);
        if ($pin === null || $pin === '') {
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        }
        // No additional SMS after an already-verified strong V5 session.
        // A real entrypoint MUST send Cache-Control:no-store and never log PINs.
        return new V5HttpReply(200, ['status' => 'revealed', 'pin' => $pin]);
    }

    private static function pending(PendingLoginChallenge $ticket, string $status): V5HttpReply
    {
        return new V5HttpReply(202, [
            'status' => $status,
            'challenge_id' => $ticket->challengeId,
            'challenge_type' => $ticket->kind->value,
            'expires_in' => $ticket->expiresInSeconds,
        ]);
    }

    private static function authenticated(AuthenticatedV5Session $session): V5HttpReply
    {
        $data = [
            'status' => 'authenticated',
            'access_token' => $session->accessToken,
            'refresh_token' => $session->refreshToken,
        ];
        if ($session->trustedDevice !== null) {
            $data['trusted_device'] = $session->trustedDevice;
        }
        return new V5HttpReply(200, $data);
    }

    private static function isString(array $body, string $key): bool
    {
        return array_key_exists($key, $body) && is_string($body[$key]);
    }

    private static function hasOnlyKeys(array $body, array $allowed): bool
    {
        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                return false;
            }
        }
        return true;
    }
}
