<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/AuthDecision.php';

use Evrasia\Samzaberu\V5\AuthDecision;
use Evrasia\Samzaberu\V5\NextFactor;

$tested = 0;
foreach ([false, true] as $passwordVerified) {
    foreach ([false, true] as $accountAllowed) {
        foreach ([false, true] as $nativeTotpActive) {
            foreach ([false, true] as $trustTokenValid) {
                foreach ([false, true] as $trustStronglyEnrolled) {
                    $actual = AuthDecision::afterPassword(
                        $passwordVerified,
                        $accountAllowed,
                        $nativeTotpActive,
                        $trustTokenValid,
                        $trustStronglyEnrolled,
                    );

                    if (!$passwordVerified || !$accountAllowed) {
                        $expected = NextFactor::DENY;
                    } elseif ($nativeTotpActive) {
                        $expected = NextFactor::TOTP;
                    } elseif ($trustTokenValid && $trustStronglyEnrolled) {
                        $expected = NextFactor::COMPLETE;
                    } else {
                        $expected = NextFactor::SMS;
                    }

                    if ($actual !== $expected) {
                        throw new RuntimeException('Unexpected auth decision at case ' . ($tested + 1));
                    }

                    // No unverified password or inactive/blocked account can progress.
                    if ((!$passwordVerified || !$accountAllowed) && $actual !== NextFactor::DENY) {
                        throw new RuntimeException('Authentication bypass');
                    }

                    // Even valid trust cannot override an active native TOTP requirement.
                    if ($passwordVerified && $accountAllowed && $nativeTotpActive && $actual !== NextFactor::TOTP) {
                        throw new RuntimeException('TOTP bypass');
                    }

                    // A password-only legacy trust token is not sufficient.
                    if ($passwordVerified && $accountAllowed && !$nativeTotpActive && !$trustStronglyEnrolled && $actual === NextFactor::COMPLETE) {
                        throw new RuntimeException('Legacy trust bypass');
                    }

                    $tested++;
                }
            }
        }
    }
}

if ($tested !== 32) {
    throw new RuntimeException('Expected 32 cases');
}

echo "PASS: V5 decision matrix ($tested cases)\n";
echo "NO_JWT_ISSUED=YES\nNO_SMS_SENT=YES\nNO_DB_READ_OR_WRITE=YES\n";
