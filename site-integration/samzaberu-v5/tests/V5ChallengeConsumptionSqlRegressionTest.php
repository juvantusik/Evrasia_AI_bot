<?php
declare(strict_types=1);

/**
 * Offline regression for the canonical V5 challenge consume SQL.
 * Detects the previously introduced missing whitespace between table and SET.
 * This tests source assembly only; no MySQL or Bitrix bootstrap.
 */
$source = file_get_contents(__DIR__ . '/../src/V5MySqlChallengeConsumption.php');
if (!is_string($source)) throw new RuntimeException('source unavailable');
$pattern = <<<'PHP'
'UPDATE ev_v5_auth_challenges '
                    .'SET status = ?, sms_code_hmac = NULL '
PHP;
if (!str_contains($source, $pattern)) {
    throw new RuntimeException('FAIL: successful consume SQL requires a separator before SET');
}
if (str_contains($source, "'UPDATE ev_v5_auth_challenges'\n                    .'SET")) {
    throw new RuntimeException('FAIL: malformed successful consume SQL returned');
}
if (!str_contains($source, "'WHERE challenge_id = ? AND status = ? AND failed_attempts = ?'")) {
    throw new RuntimeException('FAIL: CAS preconditions absent');
}
echo "PASS: canonical SMS/TOTP consume UPDATE SQL separator and CAS guard\n";
echo "DATABASE_CONNECTION=NO\nPRODUCTION_WRITE=NO\n";
