<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/**
 * Proposed V5 HTTP surface. Paths are relative to the /api/v5 base.
 * No controller may be installed before authentication services are ready.
 */
final class V5RouteMap
{
    public const BASE_PATH = '/api/v5';

    public const ROUTES = [
        'POST /auth/signin' => 'signin',
        'POST /auth/challenge/verify' => 'verifyChallenge',
        'POST /auth/challenge/resend' => 'resendChallenge',
        'POST /auth/refresh' => 'refresh',
        'POST /auth/token/validate' => 'validateToken',
        'POST /loyalty/pin' => 'showBonusPin',
        'GET /legal/consents' => 'publicLegalConsents',
    ];
}
