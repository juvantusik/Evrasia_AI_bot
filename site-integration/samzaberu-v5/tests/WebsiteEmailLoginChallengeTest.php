<?php
declare(strict_types=1);

require_once __DIR__ . '/../deployment/website-mfa/EmailLoginChallenge.php';

use Evrasia\WebMfa\EmailLoginChallenge as Challenge;

$pass = 0;
$test = static function (bool $ok, string $name) use (&$pass): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    $pass++;
};
$time = 1800000000;
$email = 'known.confirmed@example.com';
$sessionId = 'php-session-fixture-123';
$sendCount = 0;
$reserveCount = 0;
$lastCode = '';
$reserve = static function (int $uid) use (&$reserveCount): bool {
    if ($uid <= 0) {
        throw new RuntimeException('never_reserve_unknown_user');
    }
    $reserveCount++;
    return true;
};
$send = static function (string $to, string $code)
    use (&$sendCount, &$lastCode, $email): bool {
    if ($to !== $email || preg_match('/^[0-9]{6}$/D', $code) !== 1) {
        throw new RuntimeException('sender_contract');
    }
    $lastCode = $code;
    $sendCount++;
    return true;
};
$start = static function (array &$state, ?int $timestamp = null)
    use ($reserve, $send, $time, $email, $sessionId): array {
    return Challenge::begin($state, 77, $email, true, true, false, false,
        $sessionId, $reserve, $send, $timestamp ?? $time);
};
$attempt = static function (array &$state, string $challengeId, string $code,
    int $uid = 77, string $mail = 'known.confirmed@example.com',
    string $sid = 'php-session-fixture-123', bool $confirmed = true,
    int $when = 1800000001): bool {
    return Challenge::verify($state, $uid, $mail, $confirmed, $sid,
        $challengeId, $code, $when);
};

try {
    $state = [];
    $begin = $start($state);
    $test($begin['status'] === 'email_challenge', 'positive_email_challenge');
    $id = $begin['challenge_id'] ?? '';
    $test(is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id) === 1,
        'unguessable_challenge_reference');
    $test(($begin['expires_at'] ?? 0) === $time + 300, 'time_to_live_300');
    $test($sendCount === 1 && $reserveCount === 1, 'single_send_after_reserve');
    $test(!str_contains(json_encode($state, JSON_THROW_ON_ERROR), $lastCode),
        'plaintext_code_not_in_session');
    $test(!str_contains(json_encode($begin, JSON_THROW_ON_ERROR), $lastCode),
        'plaintext_code_not_in_http_response');
    $test(!str_contains(json_encode($state, JSON_THROW_ON_ERROR), $email),
        'raw_email_not_in_session');
    $test($attempt($state, $id, $lastCode), 'valid_email_proof');
    $test(!$attempt($state, $id, $lastCode), 'email_code_replay_rejected');

    foreach ([
        ['no_password', false, false, false, true, 'known.confirmed@example.com', 77,
            $sessionId],
        ['native_totp', true, true, false, true, 'known.confirmed@example.com', 77,
            $sessionId],
        ['trusted_device', true, false, true, true, 'known.confirmed@example.com', 77,
            $sessionId],
        ['unconfirmed_email', true, false, false, false, 'known.confirmed@example.com',
            77, $sessionId],
        ['invalid_email', true, false, false, true, 'not an email', 77, $sessionId],
        ['invalid_user', true, false, false, true, 'known.confirmed@example.com',
            0, $sessionId],
        ['missing_session', true, false, false, true, 'known.confirmed@example.com',
            77, ''],
    ] as [$name, $password, $totp, $trust, $confirmed, $mail, $uid, $sid]) {
        $s = [];
        $before = $sendCount;
        $result = Challenge::begin($s, $uid, $mail, $confirmed, $password,
            $totp, $trust, $sid, $reserve, $send, $time);
        $test($result['status'] === 'sms_or_native_factor_required',
            'ineligible_' . $name);
        $test($before === $sendCount && $s === [],
            'no_mail_or_session_on_ineligible_' . $name);
    }

    $s = [];
    $result = Challenge::begin($s, 77, $email, true, true, false, false,
        $sessionId, static fn (int $uid): bool => false, $send, $time);
    $test($result['status'] === 'email_unavailable' && $s === [],
        'send_quota_fail_closed');

    $result = Challenge::begin($s, 77, $email, true, true, false, false,
        $sessionId, $reserve, static fn (string $to, string $code): bool => false,
        $time);
    $test($result['status'] === 'email_unavailable' && $s === [],
        'smtp_failure_clears_challenge');

    $result = Challenge::begin($s, 77, $email, true, true, false, false,
        $sessionId, $reserve,
        static function (string $to, string $code): bool {
            throw new RuntimeException('SMTP backend internal error');
        }, $time);
    $test($result['status'] === 'email_unavailable' && $s === [],
        'smtp_exception_redacted_and_cleared');

    $s = [];
    $begin = $start($s);
    $id = (string) $begin['challenge_id'];
    $test(!$attempt($s, $id, $lastCode, 78), 'cross_user_rejected');
    $test(!$attempt($s, $id, $lastCode), 'cross_user_mismatch_consumed');

    $s = [];
    $begin = $start($s);
    $id = (string) $begin['challenge_id'];
    $test(!$attempt($s, $id, $lastCode, 77,
        'changed.confirmed@example.com'), 'changed_email_rejected');
    $test(!$attempt($s, $id, $lastCode), 'changed_email_consumed');

    $s = [];
    $begin = $start($s);
    $id = (string) $begin['challenge_id'];
    $test(!$attempt($s, $id, $lastCode, 77, $email, 'another-session'),
        'cross_session_rejected');
    $test(!$attempt($s, $id, $lastCode), 'cross_session_consumed');

    $s = [];
    $begin = $start($s);
    $id = (string) $begin['challenge_id'];
    $test(!$attempt($s, $id, $lastCode, 77, $email, $sessionId, false),
        'email_confirmation_revoked');

    $s = [];
    $begin = $start($s);
    $id = (string) $begin['challenge_id'];
    $test(!$attempt($s, $id, $lastCode, 77, $email, $sessionId, true,
        $time + 300), 'expired_at_exact_boundary');

    $s = [];
    $begin = $start($s);
    $id = (string) $begin['challenge_id'];
    $test(!$attempt($s, str_repeat('a', 32), $lastCode),
        'wrong_challenge_rejected');
    $test(!$attempt($s, $id, $lastCode),
        'wrong_challenge_invalidates_email_proof');

    $s = [];
    $begin = $start($s);
    $id = (string) $begin['challenge_id'];
    $wrong = $lastCode === '000000' ? '999999' : '000000';
    $test(!$attempt($s, $id, 'abc123'), 'non_digits_rejected');
    $test(!$attempt($s, $id, $wrong), 'first_wrong_code_rejected');
    $test(!$attempt($s, $id, $wrong), 'three_attempts_lockout');
    $test(!$attempt($s, $id, $lastCode), 'real_code_after_lockout_rejected');

    $s = [];
    $begin = $start($s);
    $id = (string) $begin['challenge_id'];
    $test(!Challenge::switchToSms($s, 78, $email, $sessionId, $id, $time + 1),
        'sms_cross_user_rejected');
    $test(!Challenge::switchToSms($s, 77, $email, $sessionId, $id, $time + 1),
        'sms_fallback_one_time');

    $s = [];
    $begin = $start($s);
    $id = (string) $begin['challenge_id'];
    $test(Challenge::switchToSms($s, 77, $email, $sessionId, $id, $time + 1),
        'explicit_sms_fallback');
    $test(!$attempt($s, $id, $lastCode), 'email_code_revoked_at_sms_fallback');

    $s = [];
    $begin = $start($s);
    $id = (string) $begin['challenge_id'];
    Challenge::clear($s);
    $test(!$attempt($s, $id, $lastCode), 'logout_clears_email_challenge');

    $test(Challenge::TTL_SECONDS === 300 && Challenge::MAX_ATTEMPTS === 3,
        'explicit_security_contract');

    echo 'PASS: email-first server-side state machine, OTP/native trust isolation, '
        . 'SMS fallback and negative security cases (' . $pass . " assertions)\n";
    echo "DATABASE_CONNECTION=NO\nREDIS_CONNECTION=NO\n";
    echo "EMAIL_SENT=NO\nSMS_SENT=NO\n";
    echo "PRODUCTION_CODE_WRITE=NO\nAPI_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
    echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
} catch (Throwable $error) {
    // Source-only test failures should never dump generated codes or addresses.
    echo 'FAIL: ' . $error->getMessage() . "\n";
    echo "FINAL_STATUS=FAIL\nFINAL_RC=1\n";
    exit(1);
}
