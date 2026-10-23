<?php
declare(strict_types=1);

/**
 * Source-only regression: original production OFF manifest contains BOTH
 * /api/v5/router.php and /api/v5/.htaccess. Never loosen V5 allowlisting
 * for a general public directory or silently replace installed.sha256.
 *
 * No production host, Bitrix, database, SMS or account execution.
 */
$root = dirname(__DIR__);
$install = file_get_contents($root . '/deployment/install-disabled-v5.sh');
$twofile = file_get_contents($root . '/deployment/upgrade-twofile-public-registration-v5.sh');
$rewrite = file_get_contents($root . '/api/v5/.htaccess');
if (!is_string($install) || !is_string($twofile) || !is_string($rewrite)) {
    throw new RuntimeException('original V5 deployment evidence unavailable');
}
$checks = 0;
$assert = static function (bool $ok, string $why) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('V5 manifest guard regression: ' . $why);
    }
    $checks++;
};

$assert(
    str_contains(
        $install,
        'sha256sum "$PUBLIC/router.php" "$PUBLIC/.htaccess" >> "$HOME_V5/installed.sha256"'
    ),
    'original installer enumerates BOTH public files into SHA manifest'
);
$assert(
    hash('sha1', 'blob ' . strlen($rewrite) . "\0" . $rewrite)
        === 'a1a05fb6d630c24aed627558304f71e7ba0af9b9',
    'public rewrite Git source has pinned original blob'
);
$sha = hash('sha256', $rewrite);
$assert(
    $sha === '5d10b20b840fe3f36a348a64a4fe5aee2b9050559cb5d429d6e12196929ed08f',
    'public rewrite SHA256 is derived from exact original Git bytes'
);
$assert(
    str_contains($twofile, 'V5_PUBLIC_REWRITE_SHA=' . $sha),
    'new installer pins exact public rewrite bytes rather than trusting arbitrary manifest hash'
);
$assert(
    str_contains($twofile, 'pin "$WEB/api/v5/.htaccess" "$V5_PUBLIC_REWRITE_SHA" public_v5_rewrite')
    && str_contains(
        $twofile,
        'pin "$WEB/api/v5/.htaccess" "$V5_PUBLIC_REWRITE_SHA" public_v5_rewrite_after'
    ),
    'rewrite is source-pinned before and after V5 inner-router switch'
);
$assert(
    str_contains($twofile, '"$WEB/api/v5/.htaccess")')
    && str_contains($twofile, '"$expected" == "$V5_PUBLIC_REWRITE_SHA"')
    && str_contains($twofile, '"$BASE"/private/samzaberu-v5/releases/*) ;;'),
    'manifest allowlist includes EXACT V5 rewrite and original private release only'
);
$assert(
    str_contains($twofile, '[[ "$total" -eq 42 && "$good" -eq 41 && "$known_mismatch" -eq 1 ]]'),
    'single known manifest mismatch remains mandatory, with no silent rebaseline'
);
$assert(
    !str_contains($twofile, 'sha256sum -c --status "$MANIFEST"')
    && str_contains($twofile, "LEGACY_INSTALLED_MANIFEST_WRITE=NO"),
    'old manifest with attested known drift is not overwritten or passed off as valid'
);

echo "PASS: V5 original 42-record manifest public rewrite scope regression ({$checks} checks)\n";
echo "MODE=OFFLINE_SOURCE_ONLY\n";
echo "PRODUCTION_CODE_WRITE=NO\n";
echo "API_V4_WRITE=NO\n";
echo "DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
echo "SMS_SENT=NO\n";
