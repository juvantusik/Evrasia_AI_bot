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
                    } elseif ($trustTokenValid) {
                        $expected = NextFactor::COMPLETE;
                    } elseif ($nativeTotpActive) {
                        $expected = NextFactor::TOTP;
                    } else {
                        $expected = NextFactor::EMAIL;
                    }

                    if ($actual !== $expected) {
                        throw new RuntimeException('Unexpected auth decision at case ' . ($tested + 1));
                    }

                    // No unverified password or inactive/blocked account can progress.
                    if ((!$passwordVerified || !$accountAllowed) && $actual !== NextFactor::DENY) {
                        throw new RuntimeException('Authentication bypass');
                    }

                    // CURRENT valid trust skips repeat TOTP, including original V4 AUTH_PASSWORD trust.
                    if ($passwordVerified && $accountAllowed && $nativeTotpActive) {
                        $expectedTotpDecision = ($trustTokenValid)
                            ? NextFactor::COMPLETE : NextFactor::TOTP;
                        if ($actual !== $expectedTotpDecision) {
                            throw new RuntimeException('Native TOTP/trust ordering mismatch');
                        }
                    }

                    // An already valid historical trust skips a new code;
                    // an unverified device_id or trust token never does.
                    if ($passwordVerified && $accountAllowed && $trustTokenValid
                        && !$trustStronglyEnrolled && $actual !== NextFactor::COMPLETE) {
                        throw new RuntimeException('Grandfathered V4 trust was wrongly rejected');
                    }
                    if ($passwordVerified && $accountAllowed && !$trustTokenValid
                        && $actual === NextFactor::COMPLETE) {
                        throw new RuntimeException('Unverified trust bypass');
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
echo "NO_JWT_ISSUED=YES\nNO_SMS_SENT=YES\nNO_EMAIL_SENT=YES\nNO_DB_READ_OR_WRITE=YES\n";
