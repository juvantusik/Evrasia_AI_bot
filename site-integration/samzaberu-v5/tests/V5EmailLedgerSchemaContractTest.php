<?php
declare(strict_types=1);

// Pure source contract: no DB, Bitrix bootstrap, Redis, filesystem writes,
// test users, OTP generation or real messages.
$root = dirname(__DIR__);
$schema = file_get_contents($root . '/schema/PROPOSED_ev_v5_email_auth_challenges.sql');
$issuer = file_get_contents($root . '/src/V5MySqlEmailOtp.php');
$proof = file_get_contents($root . '/src/V5MySqlConsumedEmailGrantProof.php');
$bridge = file_get_contents($root . '/src/V5BitrixPdoCompatibilityBridge.php');
if (!is_string($schema) || !is_string($issuer)
    || !is_string($proof) || !is_string($bridge)) {
    throw new RuntimeException('FAIL: email source contract unavailable');
}

$checks = 0;
$check = static function (bool $condition, string $reason) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $reason);
    }
    $checks++;
};

$check(substr_count($schema, 'CREATE TABLE ev_v5_email_auth_challenges (') === 1,
    'single exact new email ledger DDL');
$check(preg_match('/\\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\\s*\\z/D', $schema) === 1,
    'InnoDB engine and explicit schema suffix');
$check(preg_match('/\\b(?:DROP|TRUNCATE|ALTER|DELETE|GRANT|REPLACE|INSERT|UPDATE)\\b/i',
    preg_replace('/^--[^\\r\\n]*$/m', '', $schema)) !== 1,
    'migration candidate never changes existing tables or rows');
$expected = [
    'challenge_id' => 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
    'user_id' => 'BIGINT UNSIGNED NOT NULL',
    'device_binding' => 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
    'platform' => 'VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
    'status' => 'VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
    'email_stamp' => 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
    'auth_epoch' => 'BIGINT UNSIGNED NOT NULL',
    'code_hmac' => 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL',
    'issued_at' => 'BIGINT UNSIGNED NOT NULL',
    'expires_at' => 'BIGINT UNSIGNED NOT NULL',
    'failed_attempts' => 'INT UNSIGNED NOT NULL DEFAULT 0',
];
$definitions = [];
foreach (explode("\n", $schema) as $line) {
    if (preg_match('/^    ([a-z_]+) ((?:CHAR|VARCHAR|BIGINT|INT)\\b[^,\\r\\n]*)[,]?\\r?$/',
        $line, $match) === 1) {
        if (isset($definitions[$match[1]])) {
            throw new RuntimeException('FAIL: duplicate email column');
        }
        $definitions[$match[1]] = rtrim($match[2]);
    }
}
$check(count($definitions) === count($expected), 'exact column count');
foreach ($expected as $name => $definition) {
    $check(($definitions[$name] ?? null) === $definition,
        'column contract: ' . $name);
}
$check(str_contains($schema, 'PRIMARY KEY (challenge_id)'),
    'challenge ID is unique');
$check(str_contains($schema,
    'KEY idx_account_status_expiry (user_id, status, expires_at)'),
    'per-account pending count uses matching index');
$check(!str_contains($schema, 'FOREIGN KEY')
    && !str_contains($schema, 'ev_v5_email_rate_windows'),
    'no cross-table DDL or second email rate limiter');
$check(!str_contains($schema, 'email_address')
    && !str_contains($schema, 'otp_plaintext')
    && !str_contains($schema, 'device_id '),
    'new ledger stores neither recipient, plaintext OTP nor raw device ID');
foreach ([
    'challenge_id', 'user_id', 'device_binding', 'platform',
    'status', 'email_stamp', 'auth_epoch', 'code_hmac',
    'issued_at', 'expires_at', 'failed_attempts'
] as $column) {
    $check(str_contains($issuer, $column)
        && str_contains($proof, $column),
        'issuer and grant proof share ' . $column);
}
foreach (['pending', 'accepted', 'consumed', 'cancelled'] as $state) {
    $check(str_contains($issuer, "'" . $state . "'"),
        'email issuer handles state ' . $state);
}
$check(str_contains($issuer, 'private const MAX_PENDING = 5')
    && str_contains($issuer, 'private const MAX_FAILED = 3'),
    'bounded pending and failed-attempt policies');
$check(str_contains($issuer, 'private int $ttl = 300')
    && str_contains($proof, '$expires-$issued>300'),
    'email OTP and consumed grant honor five-minute maximum');
$check(str_contains($issuer, 'SET status = ?, code_hmac = NULL')
    && str_contains($proof, "['code_hmac']??null)!==null"),
    'one-time consumption clears OTP HMAC before strong JWT grant');
$check(str_contains($bridge, "'SELECT COUNT(*) FROM ev_v5_email_auth_challenges '")
    && str_contains($bridge, "'INSERT INTO ev_v5_email_auth_challenges '")
    && str_contains($bridge, "'UPDATE ev_v5_email_auth_challenges '"),
    'existing exact SQL bridge includes email ledger operations');
$check(!str_contains($issuer, 'ev_v5_email_rate_windows')
    && !str_contains($proof, 'ev_v5_email_rate_windows'),
    'no parallel SQL email rate tables');

printf("PASS: V5 email ledger DDL versus issuer/grant contract (%d assertions)\n", $checks);
echo "MODE=SOURCE_ONLY_NO_DATABASE\n";
echo "DDL_EXECUTED=NO\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
echo "SYNTHETIC_ACCOUNTS=NO\nREDIS_CONNECTION=NO\nOTP_SENT=NO\n";
echo "V5_AUTH_PRODUCTION=OFF\nFINAL_STATUS=PASS\n";
