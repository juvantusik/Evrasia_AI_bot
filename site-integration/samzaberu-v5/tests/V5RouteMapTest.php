<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5RouteMap.php';

use Evrasia\Samzaberu\V5\V5RouteMap;

$expected = [
    'POST /auth/signin' => 'signin',
    'POST /auth/challenge/verify' => 'verifyChallenge',
    'POST /auth/challenge/resend' => 'resendChallenge',
    'POST /auth/refresh' => 'refresh',
    'POST /auth/logout' => 'logout',
    'POST /auth/token/validate' => 'validateToken',
    'POST /loyalty/pin' => 'showBonusPin',
    'GET /cart' => 'readCart',
    'GET /legal/consents' => 'publicLegalConsents',
    'GET /legal/registration-documents' => 'publicRegistrationDocuments',
    'POST /legal/consents/validate' => 'validateLegalConsents',
];

if (V5RouteMap::BASE_PATH !== '/api/v5'
    || V5RouteMap::ROUTES !== $expected
    || count(array_unique(array_values(V5RouteMap::ROUTES))) !== 11
) {
    throw new RuntimeException('V5 route contract mismatch');
}

foreach (array_keys(V5RouteMap::ROUTES) as $spec) {
    [$method,$path] = explode(' ', $spec, 2);
    if (!in_array($method, ['POST','GET'], true)
        || !str_starts_with($path, '/')
        || ($method === 'GET' && !in_array($path, ['/legal/consents','/legal/registration-documents','/cart'], true))
        || str_contains($path, '/api/v4')
        || preg_match('/\s/', $path)
    ) {
        throw new RuntimeException('Invalid V5 route declaration');
    }
}

echo 'PASS: V5 isolated route contract (11 routes; protected GET cart; anonymous current legal/registration documents GET and legal validation POST)' . PHP_EOL;
echo 'NATIVE_PROVIDERS_NOT_ACTIVATED=YES' . PHP_EOL;

// Keep this regression in the existing approved offline suite, not a new workflow.
require __DIR__ . '/V5ParallelHttpEntryTest.php';
require __DIR__ . '/V5PrivatePilotConfigTest.php';
