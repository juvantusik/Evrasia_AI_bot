<?php
declare(strict_types=1);

/**
 * Production-only source-pinned, reversible UI-only relocation of the existing
 * СамЗаберу V4 compatibility warning into the established security card.
 *
 * Modes: --self-test (offline), --preflight, --apply, --status, --rollback.
 * NO DB access, mail, SMS, OTP, auth, device, PIN or V4/V5 writes.
 * Main account source MUST have the previously verified email badge installed.
 */
final class EvSecurityWarningRelocation
{
    private const ROOT = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const TARGET = self::ROOT . '/local/templates/eurasia/components/eurasia/account/main/template.php';
    private const OTHER = self::ROOT . '/local/templates/eurasia/components/eurasia/account/cached/template.php';
    private const SIGNIN = self::ROOT . '/local/components/eurasia/signin/class.php';
    private const MAIN_SHA = '7d0db7e8a16f190f0dfd59a2d4337f45f088ea96705e24c60cef1c96d0cd2654';
    private const CACHED_SHA = '66ae330cc0af3b2ed54a3d92c224fec60919bec4bfd279e1d9debdd7eacd59bb';
    private const SIGNIN_SHA = 'e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a';
    private const BADGE_RELEASE = '/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H/live-badge-release';
    private const RELEASE = '/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H/account-warning-relocation';
    private const BACKUP = self::RELEASE . '/main.before.php';
    private const CANDIDATE = self::RELEASE . '/main.candidate.php';
    private const MARKER = 'ev-website-mfa-warning-readable';

    private static int $pass = 0;
    private static bool $wroteSite = false;
    private static bool $restored = false;
    private static bool $already = false;

    private static function demand(bool $ok, string $label): void
    {
        if (!$ok) {
            throw new RuntimeException($label);
        }
        self::$pass++;
        echo 'PASS: ' . $label . "\n";
    }

    private static function source(string $path, string $sha): void
    {
        self::demand(
            is_file($path) && !is_link($path) && is_readable($path)
            && realpath($path) === $path
            && hash_equals($sha, (string)hash_file('sha256', $path)),
            'pinned_' . substr($sha, 0, 8)
        );
    }

    private static function patch(string $before): string
    {
        if (str_contains($before, self::MARKER)) {
            throw new RuntimeException('already_patched_source_refused');
        }
        // Exactly one existing text warning, originally outside the card.
        $regex = '~<p\s+class="website-mfa-v4-warning"\s+role="note"\s*>(.*?)</p>~su';
        $found = preg_match_all($regex, $before, $matches);
        if ($found !== 1) {
            throw new RuntimeException('warning_marker_not_unique');
        }
        $text = trim($matches[1][0]);
        if (!str_contains($text, 'СамЗаберу')
            || !str_contains($text, 'двухфакторной')
            || str_contains($text, '<?')
            || str_contains($text, '<script')
            || strlen($text) > 1500) {
            throw new RuntimeException('warning_text_source_not_exact');
        }
        $card = 'class="name_block logout_block account-security-panel"';
        $entry = '<div class="account-security-main">';
        if (substr_count($before, $card) !== 1
            || substr_count($before, $entry) !== 1
            || substr_count($before, 'Безопасность аккаунта') !== 1) {
            throw new RuntimeException('security_card_structure_changed');
        }
        $cardPos = strpos($before, $card);
        $warningPos = strpos($before, $matches[0][0]);
        $entryPos = strpos($before, $entry);
        if ($cardPos === false || $warningPos === false || $entryPos === false
            || $warningPos >= $cardPos || $cardPos >= $entryPos
            || $entryPos - $cardPos > 5000) {
            throw new RuntimeException('warning_is_not_outside_expected_card');
        }

        $notice = '<div class="' . self::MARKER . '" role="note"'
            . ' style="font-family:inherit;font-size:16px;line-height:1.5;'
            . 'font-weight:400;color:#425960;'
            . 'background:rgba(255,255,255,.62);'
            . 'border-left:4px solid #5aa7b3;'
            . 'padding:12px 16px;margin:14px 0 20px;'
            . 'border-radius:3px;box-sizing:border-box;'
            . 'overflow-wrap:anywhere;text-align:left;">'
            . $text . '</div>' . "\n";

        $withoutOld = str_replace($matches[0][0], '', $before, $removed);
        if ($removed !== 1) {
            throw new RuntimeException('warning_not_removed_once');
        }
        $after = str_replace($entry, $notice . $entry, $withoutOld, $inserted);
        if ($inserted !== 1 || substr_count($after, self::MARKER) !== 1
            || substr_count($after, $text) !== 1
            || !str_contains($after, 'evrasiaEmailBadgeBridge')) {
            throw new RuntimeException('warning_relocation_invariant');
        }
        // Only two authorized text transformations: old paragraph moved,
        // and a single styled notice inserted before the existing 2FA copy.
        $reversed = str_replace($notice, '', $after, $r1);
        $reversed = str_replace('', '', $reversed);
        if ($r1 !== 1
            || !hash_equals(hash('sha256', $withoutOld),
                hash('sha256', $reversed))) {
            throw new RuntimeException('non_reversible_warning_patch');
        }
        return $after;
    }

    private static function exactProduction(): void
    {
        self::demand(PHP_SAPI === 'cli' && function_exists('posix_geteuid')
            && posix_geteuid() === 0
            && explode('.', gethostname() ?: '', 2)[0] === 'evrasia',
            'production_root_only');
        self::demand(is_dir(self::ROOT) && !is_link(self::ROOT)
            && realpath(self::ROOT) === self::ROOT,
            'bitrix_root_realpath');
        self::demand(is_dir(self::BADGE_RELEASE)
            && !is_link(self::BADGE_RELEASE)
            && fileowner(self::BADGE_RELEASE) === 0
            && (fileperms(self::BADGE_RELEASE) & 0777) === 0700
            && is_file(self::BADGE_RELEASE . '/manifest.txt'),
            'installed_badge_release_checkpoint');
        self::source(self::OTHER, self::CACHED_SHA);
        self::source(self::SIGNIN, self::SIGNIN_SHA);
        $bridge = self::ROOT
            . '/local/php_interface/website-mfa/EmailAccountBadgeBridge.php';
        self::demand(is_file($bridge) && !is_link($bridge),
            'email_badge_bridge_still_installed');
        self::demand(is_file(self::TARGET)
            && !is_link(self::TARGET) && realpath(self::TARGET) === self::TARGET,
            'live_account_main_regular');
    }

    private static function lock(): mixed
    {
        $lockPath = dirname(self::RELEASE) . '/.security-warning-relocation.lock';
        $fh = fopen($lockPath, 'c');
        self::demand(is_resource($fh)
            && flock($fh, LOCK_EX | LOCK_NB), 'exclusive_warning_release_lock');
        return $fh;
    }

    private static function candidateFromOriginal(): string
    {
        self::source(self::BACKUP, self::MAIN_SHA);
        $old = file_get_contents(self::BACKUP);
        if (!is_string($old)) {
            throw new RuntimeException('failed_reading_backup');
        }
        return self::patch($old);
    }

    private static function checkRelease(): string
    {
        self::demand(is_dir(self::RELEASE)
            && !is_link(self::RELEASE)
            && realpath(self::RELEASE) === self::RELEASE
            && fileowner(self::RELEASE) === 0
            && (fileperms(self::RELEASE) & 0777) === 0700,
            'private_warning_release_directory');
        $generated = self::candidateFromOriginal();
        self::demand(is_file(self::CANDIDATE)
            && !is_link(self::CANDIDATE)
            && hash_equals(hash('sha256', $generated),
                (string)hash_file('sha256', self::CANDIDATE)),
            'exact_reversible_warning_candidate');
        return $generated;
    }

    private static function atomicReplace(string $candidate, string $ownerReference): void
    {
        $stat = stat($ownerReference);
        if (!is_array($stat)) {
            throw new RuntimeException('reference_stat_failed');
        }
        $temp = tempnam(dirname(self::TARGET), '.ev-warning-');
        if ($temp === false) {
            throw new RuntimeException('atomic_same_directory_temp_failed');
        }
        try {
            if (file_put_contents($temp, $candidate, LOCK_EX) !== strlen($candidate)
                || !chown($temp, (int)$stat['uid'])
                || !chgrp($temp, (int)$stat['gid'])
                || !chmod($temp, ((int)$stat['mode'] & 0777))) {
                throw new RuntimeException('atomic_candidate_setup_failed');
            }
            $check = [];
            $status = 1;
            exec('php -d short_open_tag=1 -d auto_prepend_file= -d auto_append_file= -l '
                . escapeshellarg($temp) . ' 2>&1', $check, $status);
            if ($status !== 0) {
                throw new RuntimeException('candidate_PHP_syntax_failed');
            }
            if (!rename($temp, self::TARGET)) {
                throw new RuntimeException('atomic_rename_failed');
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    private static function preflight(): void
    {
        self::source(self::TARGET, self::MAIN_SHA);
        self::demand(!file_exists(self::RELEASE) && !is_link(self::RELEASE),
            'no_existing_warning_release');
        $before = file_get_contents(self::TARGET);
        if (!is_string($before)) {
            throw new RuntimeException('source_unreadable');
        }
        $after = self::patch($before);
        self::demand(strlen($after) > 20000 && strlen($after) > strlen($before) - 1000,
            'warning_patch_local_and_bounded');
        echo 'CANDIDATE_SHA256=' . hash('sha256', $after) . "\n";
        echo "WARNING_MOVE=BEFORE_2FA_CONTENT_INSIDE_ACCOUNT_SECURITY_PANEL\n";
        echo "FONT_SIZE_PX=16\n";
        echo "BUTTON_AND_EMAIL_AUTH=NOT_CHANGED\n";
    }

    private static function install(): void
    {
        if (is_dir(self::RELEASE)) {
            $want = self::checkRelease();
            if (is_file(self::TARGET)
                && hash_equals(hash('sha256',$want),
                    (string)hash_file('sha256',self::TARGET))) {
                self::$already = true;
                self::demand(true, 'already_installed_exact_candidate');
                return;
            }
            throw new RuntimeException('existing_release_requires_manual_review');
        }

        preflightEntry:
        self::preflight();
        self::demand(mkdir(self::RELEASE, 0700)
            && is_dir(self::RELEASE)
            && (fileperms(self::RELEASE) & 0777) === 0700,
            'new_private_warning_rollback_checkpoint');
        $before = file_get_contents(self::TARGET);
        if (!is_string($before)) {
            throw new RuntimeException('source_read_failed');
        }
        $candidate = self::patch($before);
        if (file_put_contents(self::BACKUP, $before, LOCK_EX) !== strlen($before)
            || !chmod(self::BACKUP, 0600)
            || file_put_contents(self::CANDIDATE, $candidate, LOCK_EX)
                !== strlen($candidate)
            || !chmod(self::CANDIDATE, 0600)) {
            throw new RuntimeException('backup_or_candidate_write_failed');
        }
        $sourceScript = file_get_contents(__FILE__);
        if (!is_string($sourceScript)
            || file_put_contents(self::RELEASE . '/restore.php',
                $sourceScript, LOCK_EX) !== strlen($sourceScript)
            || !chmod(self::RELEASE . '/restore.php', 0600)) {
            throw new RuntimeException('root_only_restore_script_missing');
        }
        self::checkRelease();

        $actual = file_get_contents(self::TARGET);
        if (!is_string($actual)
            || !hash_equals(self::MAIN_SHA, hash('sha256',$actual))) {
            throw new RuntimeException('source_drift_before_atomic_rename');
        }

        self::atomicReplace($candidate, self::BACKUP);
        self::$wroteSite = true;
        self::demand(hash_equals(hash('sha256',$candidate),
                (string)hash_file('sha256',self::TARGET)),
            'live_warning_only_candidate_SHA');
        echo 'WEBSITE_ACCOUNT_WARNING=RELOCATED_INSIDE_SECURITY_CARD' . "\n";
    }

    private static function status(): void
    {
        $expected = self::checkRelease();
        self::demand(is_file(self::TARGET)
            && !is_link(self::TARGET)
            && hash_equals(hash('sha256',$expected),
                (string)hash_file('sha256', self::TARGET)),
            'current_warning_release_SHA');
        echo "WEBSITE_ACCOUNT_WARNING=RELOCATED_INSIDE_SECURITY_CARD\n";
        echo "EMAIL_CONFIRM_BUTTON=NOT_YET_DEPLOYED\n";
    }

    private static function rollback(): void
    {
        $expected = self::checkRelease();
        self::demand(is_file(self::TARGET)
            && !is_link(self::TARGET)
            && hash_equals(hash('sha256',$expected),
                (string)hash_file('sha256', self::TARGET)),
            'expected_warning_candidate_before_rollback');
        $backup = file_get_contents(self::BACKUP);
        if (!is_string($backup)) {
            throw new RuntimeException('backup_read_failed');
        }
        self::atomicReplace($backup, self::BACKUP);
        self::$wroteSite = true;
        self::demand(hash_equals(self::MAIN_SHA,
            (string)hash_file('sha256',self::TARGET)),
            'restored_previous_badge_plus_original_warning');
        self::$restored = true;
        echo "WEBSITE_ACCOUNT_WARNING=ORIGINAL_POSITION_RESTORED\n";
    }

    private static function selfTest(): void
    {
        self::demand(PHP_SAPI === 'cli', 'CLI');
        $test = <<<'HTML'
<?php if ($loggedIn): ?>
<p class="website-mfa-v4-warning" role="note">Важно: после добровольного подключения двухфакторной авторизации повторный вход в «СамЗаберу» временно не работает.</p>
<?php endif; ?>
<div class="name_block logout_block account-security-panel">
  <div class="account-security-header">
    <h2 class="account-security-title">Безопасность аккаунта</h2>
  </div>
  <div class="account-security-main">
    <?= $arResult['USER']['ID']; ?>
  </div>
</div>
<?php $evrasiaEmailBadgeBridge = 'installed'; ?>
HTML;
        $out = self::patch($test);
        self::demand(substr_count($out,self::MARKER)===1
            && strpos($out,self::MARKER)>strpos($out,'account-security-panel')
            && strpos($out,self::MARKER)<strpos($out,'account-security-main'),
            'warning_inside_existing_security_card');
        self::demand(!str_contains($out,'class="website-mfa-v4-warning"')
            && str_contains($out,'font-size:16px')
            && str_contains($out,'«СамЗаберу»'),
            'original_warning_moved_once_and_readable');
        self::demand(str_contains($out,"evrasiaEmailBadgeBridge = 'installed'"),
            'existing_email_badge_untouched');
        try {
            self::patch($out);
            throw new RuntimeException('double_patch_was_accepted');
        } catch (RuntimeException $e) {
            self::demand($e->getMessage() === 'already_patched_source_refused',
                'double_patch_fails_closed');
        }
    }

    public static function main(array $argv): void
    {
        $mode = $argv[1] ?? '';
        $rc=0;
        try {
            if (count($argv)!==2) {
                throw new RuntimeException('exactly_one_mode_required');
            }
            if ($mode==='--self-test') {
                self::selfTest();
            } else {
                self::exactProduction();
                $lock=self::lock();
                if ($mode==='--preflight') {
                    self::preflight();
                } elseif ($mode==='--apply') {
                    self::install();
                } elseif ($mode==='--status') {
                    self::status();
                } elseif ($mode==='--rollback') {
                    self::rollback();
                } else {
                    throw new RuntimeException('unsupported_mode');
                }
            }
        } catch (Throwable $e) {
            $name=$e->getMessage();
            if (!preg_match('/^[a-zA-Z0-9_]+$/D',$name)) {
                $name='safe_release_operation_failed';
            }
            echo "FAIL: $name\n";
            $rc=1;
            if ($mode==='--apply' && self::$wroteSite) {
                try {
                    $expected=self::checkRelease();
                    if (hash_equals(hash('sha256',$expected),
                        (string)hash_file('sha256',self::TARGET))) {
                        self::rollback();
                        echo "AUTOMATIC_ROLLBACK=PASS\n";
                    }
                } catch(Throwable) {
                    echo "AUTOMATIC_ROLLBACK=REQUIRES_ATTENTION\n";
                }
            }
        }
        echo "\n=== 5. RESULT ===\n";
        echo "MODE=$mode\n";
        echo "PASS_COUNT=".self::$pass."\n";
        echo "SOURCE_FILE_WRITE=". (self::$wroteSite?'YES':'NO')."\n";
        echo "ALREADY_APPLIED=".(self::$already?'YES':'NO')."\n";
        echo "RESTORED=".(self::$restored?'YES':'NO')."\n";
        echo "DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
        echo "EMAIL_SENT=NO\nSMS_SENT=NO\nREDIS_WRITE=NO\n";
        echo "SIGNIN_WRITE=NO\nPIN_WRITE=NO\n";
        echo "TRUSTED_DEVICE_WRITE=NO\nAPI_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
        echo "EMAIL_CONFIRMATION_BUTTON=NOT_INSTALLED\n";
        echo "EMAIL_FIRST_AUTH=OFF\n";
        echo "BADGE_SOURCE_PRESERVED=YES\n";
        echo 'FINAL_STATUS='.($rc===0?'PASS':'FAIL')."\n";
        echo "FINAL_RC=$rc\n";
        exit($rc);
    }
}
EvSecurityWarningRelocation::main($argv);
