<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/**
 * V5 HTTP surface. Paths are relative to the /api/v5 base.
 * Public legal validation is read-only and may run before auth readiness;
 * user/session endpoints MUST stay disabled until native gates pass.
 */
final class V5RouteMap
{
    public const BASE_PATH = '/api/v5';

    public const ROUTES = [
        'POST /auth/signin' => 'signin',
        'POST /auth/challenge/verify' => 'verifyChallenge',
        'POST /auth/challenge/resend' => 'resendChallenge',
        'POST /auth/challenge/use-sms' => 'requestSmsFallback',
        'POST /auth/refresh' => 'refresh',
        'POST /auth/logout' => 'logout',
        'POST /auth/token/validate' => 'validateToken',
        'POST /loyalty/pin' => 'showBonusPin',
        'GET /cart' => 'readCart',
        'POST /cart/items/add' => 'addCartItem',
        'POST /cart/items/quantity' => 'setCartItemQuantity',
        'POST /cart/items/remove' => 'removeCartItem',
        'GET /profile' => 'readProfile',
        'GET /orders' => 'readOrders',
        'POST /orders/detail' => 'readOrderDetail',
        'POST /cart/order' => 'placeOrder',
        'GET /legal/consents' => 'publicLegalConsents',
        'GET /legal/registration-documents' => 'publicRegistrationDocuments',
        'POST /legal/consents/validate' => 'validateLegalConsents',
    ];
}
