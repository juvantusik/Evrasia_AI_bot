<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5RouteMap.php';

use Evrasia\Samzaberu\V5\V5RouteMap;

$expected = [
    'POST /auth/signin' => 'signin',
    'POST /auth/challenge/verify' => 'verifyChallenge',
    'POST /auth/challenge/resend' => 'resendChallenge',
    'POST /auth/refresh' => 'refresh',
    'POST /loyalty/pin' => 'showBonusPin',
];

if (V5RouteMap::BASE_PATH !== '/api/v5'
    || V5RouteMap::ROUTES !== $expected
    || count(array_unique(array_values(V5RouteMap::ROUTES))) !== 5
) {
    throw new RuntimeException('V5 route contract mismatch');
}

foreach (array_keys(V5RouteMap::ROUTES) as $spec) {
    if (!str_starts_with($spec, 'POST /')
        || str_contains($spec, '/api/v4')
        || preg_match('/\s/', substr($spec, 5))
    ) {
        throw new RuntimeException('Invalid V5 route declaration');
    }
}

echo 'PASS: V5 isolated route contract (5 routes)' . PHP_EOL;
echo 'NATIVE_PROVIDERS_NOT_ACTIVATED=YES' . PHP_EOL;

// Keep this regression in the existing approved offline suite, not a new workflow.
require __DIR__ . '/V5ParallelHttpEntryTest.php';
require __DIR__ . '/V5PrivatePilotConfigTest.php';
