<?php
declare(strict_types=1);

/**
 * Resume ONLY the previously failed UI-only warning relocation:
 * PREPARED_PRIVATE_RELEASE exists, but installed main account SHA remains old.
 *
 * Old installer failed AFTER a validated backup/candidate and BEFORE site
 * write. This version does not rely on shell exec() / proc_open(), preserves
 * metadata of the *live template*, and installs a root-owned restore.php
 * that can independently rollback the single account template.
 *
 * NO DB/Redis/email/SMS operations; does not touch signin/PIN/V4/V5.
 */
final class EvWarningSafeResume
{
    private const ROOT = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const ROOT_STAGE = '/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H';
    private const RELEASE = self::ROOT_STAGE . '/account-warning-relocation';
    private const MAIN = self::ROOT . '/local/templates/eurasia/components/eurasia/account/main/template.php';
    private const CACHED = self::ROOT . '/local/templates/eurasia/components/eurasia/account/cached/template.php';
    private const SIGNIN = self::ROOT . '/local/components/eurasia/signin/class.php';
    private const BEFORE = '7d0db7e8a16f190f0dfd59a2d4337f45f088ea96705e24c60cef1c96d0cd2654';
    private const AFTER = 'cc0bb0e2754704feb29d7c7db6f9064c340b274ada21852e18ed1a99db7723bb';
    private const CACHED_SHA = '66ae330cc0af3b2ed54a3d92c224fec60919bec4bfd279e1d9debdd7eacd59bb';
    private const SIGNIN_SHA = 'e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a';
    private const PREVIOUS_RESTORE_BLOB = 'a1745f9b4f4674b59e6b8046bc3f9e7a368cd02d';
    private const BACKUP = self::RELEASE . '/main.before.php';
    private const CANDIDATE = self::RELEASE . '/main.candidate.php';
    private const RESTORE = self::RELEASE . '/restore.php';
    private const OLD_RESTORE = self::RELEASE . '/restore.initial-failed.php';

    private static int $pass = 0;
    private static bool $siteTouched = false;
    private static bool $restored = false;
    private static bool $already = false;

    private static function must(bool $ok, string $label): void
    {
        if (!$ok) {
            throw new RuntimeException($label);
        }
        self::$pass++;
        echo 'PASS: ' . $label . "\n";
    }

    private static function sha(string $p): string
    {
        $sum = hash_file('sha256', $p);
        return is_string($sum) ? $sum : '';
    }

    private static function gitBlob(string $p): string
    {
        $s = file_get_contents($p);
        return is_string($s)
            ? sha1('blob ' . strlen($s) . "\0" . $s) : '';
    }

    private static function source(string $p, string $expected, string $label): void
    {
        self::must(is_file($p) && !is_link($p) && is_readable($p)
            && realpath($p) === $p
            && hash_equals($expected, self::sha($p)),
            $label . '_pinned_SHA');
    }

    private static function exactEnvironment(): void
    {
        echo "=== 1. HOST / INSTALLED-RELEASE GUARDS ===\n";
        self::must(PHP_SAPI === 'cli'
            && function_exists('posix_geteuid') && posix_geteuid() === 0
            && explode('.', gethostname() ?: '', 2)[0] === 'evrasia',
            'exact_production_hostname_root');
        self::must(is_dir(self::ROOT) && !is_link(self::ROOT)
            && realpath(self::ROOT) === self::ROOT,
            'Bitrix_document_root_exact');
        foreach ([self::ROOT_STAGE, self::RELEASE] as $dir) {
            self::must(is_dir($dir) && !is_link($dir)
                && realpath($dir) === $dir
                && fileowner($dir) === 0
                && (fileperms($dir) & 0777) === 0700,
                'private_release_directory_' . basename($dir));
        }
        $badge = self::ROOT_STAGE . '/live-badge-release/manifest.txt';
        self::must(is_file($badge) && !is_link($badge)
            && str_contains((string)file_get_contents($badge),
                'RELEASE_SCOPE=BADGE_ONLY_2_ACCOUNT_TEMPLATES_4_PASSIVE_HELPERS'),
            'original_badge_checkpoint_present');
        self::source(self::CACHED, self::CACHED_SHA, 'cached_account_unchanged');
        self::source(self::SIGNIN, self::SIGNIN_SHA, 'signin_MFA_unchanged');
        self::source(self::BACKUP, self::BEFORE, 'main_original_backup');
        self::source(self::CANDIDATE, self::AFTER, 'warning_candidate');
        $candidate = file_get_contents(self::CANDIDATE);
        self::must(is_string($candidate)
            && substr_count($candidate, 'ev-website-mfa-warning-readable') === 1
            && str_contains($candidate, 'font-size:16px')
            && !str_contains($candidate, 'class="website-mfa-v4-warning"')
            && str_contains($candidate, 'evrasiaEmailBadgeBridge'),
            'only_readable_warning_and_existing_email_badge');
        // This self-contained PHP file is invoked with -d short_open_tag=1;
        // the parser validates mixed HTML and PHP without spawning commands.
        self::must(self::syntaxValid($candidate),
            'candidate_parses_without_disabled_shell_exec');
        self::must(is_file(self::MAIN) && !is_link(self::MAIN)
            && realpath(self::MAIN) === self::MAIN,
            'current_main_regular_file');
    }

    private static function syntaxValid(string $bytes): bool
    {
        try {
            token_get_all($bytes, TOKEN_PARSE);
            return true;
        } catch (ParseError) {
            return false;
        }
    }

    private static function lockRelease(): mixed
    {
        $lockPath = self::ROOT_STAGE . '/.security-warning-relocation.lock';
        $fh = fopen($lockPath, 'c');
        self::must(is_resource($fh) && flock($fh, LOCK_EX | LOCK_NB),
            'exclusive_relocation_lock');
        return $fh;
    }

    private static function restoreState(): string
    {
        if (!is_file(self::RESTORE) || is_link(self::RESTORE)) {
            return 'missing';
        }
        if (hash_equals(self::PREVIOUS_RESTORE_BLOB,
            self::gitBlob(self::RESTORE))) {
            return 'original';
        }
        if (hash_equals(self::sha(__FILE__), self::sha(self::RESTORE))) {
            return 'recovered';
        }
        // If running the self-contained restore.php itself, the active
        // file must match its root-owned recovery marker.
        if (realpath(__FILE__) === realpath(self::RESTORE)
            && is_file(self::RELEASE . '/fixed-restore.sha256')
            && !is_link(self::RELEASE . '/fixed-restore.sha256')) {
            $mark = trim((string)file_get_contents(
                self::RELEASE . '/fixed-restore.sha256'));
            if (preg_match('/^[a-f0-9]{64}$/D', $mark) === 1
                && hash_equals($mark, self::sha(self::RESTORE))) {
                return 'recovered';
            }
        }
        return 'changed';
    }

    private static function oldStatus(): string
    {
        $live = self::sha(self::MAIN);
        if (hash_equals(self::BEFORE, $live)) return 'PREPARED_NOT_INSTALLED';
        if (hash_equals(self::AFTER, $live)) return 'INSTALLED';
        return 'DRIFT_REFUSED';
    }

    private static function preflight(): void
    {
        echo "=== 2. FAILED-RELEASE RECOVERY PREFLIGHT ===\n";
        $state = self::oldStatus();
        self::must($state !== 'DRIFT_REFUSED',
            'live_main_matches_original_or_candidate');
        $restore = self::restoreState();
        self::must(in_array($restore, ['original','recovered'], true),
            'known_previous_or_fixed_restore');
        if ($restore === 'original') {
            self::must(!file_exists(self::OLD_RESTORE)
                && !is_link(self::OLD_RESTORE),
                'no_preexisting_restore_archive');
        } else {
            self::must(is_file(self::RELEASE . '/fixed-restore.sha256')
                && !is_link(self::RELEASE . '/fixed-restore.sha256'),
                'fixed_restore_manifest_present');
        }
        $m = stat(self::MAIN);
        self::must(is_array($m)
            && (int)$m['uid'] > 0
            && ((int)$m['mode'] & 0777) >= 0400
            && (((int)$m['mode'] & 0002) === 0),
            'live_template_owner_and_permissions_safe');
        echo "LIVE_TEMPLATE_STATE=$state\n";
        echo 'LIVE_OWNER_ID=' . (int)$m['uid'] . "\n";
        echo 'LIVE_MODE_OCTAL=' . sprintf('%04o', ((int)$m['mode'] & 0777)) . "\n";
        echo "RESTORE_STATE=$restore\n";
        echo "DATABASE_WRITE=NO\n";
        if ($restore === 'original' && $state === 'INSTALLED') {
            throw new RuntimeException('old_broken_restore_present_after_install');
        }
    }

    private static function makeWorkingRestore(): void
    {
        $state = self::restoreState();
        if ($state === 'recovered') return;
        self::must($state === 'original', 'old_restore_matches_initial_Git_blob');
        $src = file_get_contents(__FILE__);
        if (!is_string($src) || strlen($src) < 8000
            || !self::syntaxValid($src)) {
            throw new RuntimeException('recovery_source_invalid');
        }
        $temp = tempnam(self::RELEASE, '.fixed-restore-');
        if ($temp === false) throw new RuntimeException('recovery_temp_failed');
        try {
            self::must(file_put_contents($temp, $src, LOCK_EX)
                    === strlen($src) && chmod($temp, 0600),
                'new_restorer_staged_private');
            self::must(hash_equals(self::sha(__FILE__), self::sha($temp)),
                'new_restorer_SHA_matches_pinned_source');
            $mark = self::RELEASE . '/fixed-restore.sha256';
            self::must(!file_exists($mark) && !is_link($mark),
                'no_existing_restore_manifest');
            self::must(file_put_contents($mark, self::sha($temp) . "\n",
                LOCK_EX) !== false && chmod($mark, 0600),
                'new_restore_hash_manifest');
            self::must(rename(self::RESTORE, self::OLD_RESTORE),
                'previous_failed_restorer_archived');
            self::must(rename($temp, self::RESTORE),
                'verified_fixed_restorer_activated');
        } finally {
            if (is_file($temp)) @unlink($temp);
        }
        self::must($thisState = self::restoreState() === 'recovered',
            'fixed_restorer_verified');
    }

    private static function atomicTemplateReplace(string $bytes,
        string $expectedCurrent): void
    {
        // Compare both bytes SHA and lstat to avoid replacing symlink/drift.
        self::must(is_file(self::MAIN) && !is_link(self::MAIN)
            && realpath(self::MAIN) === self::MAIN
            && hash_equals($expectedCurrent, self::sha(self::MAIN)),
            'exact_live_pre_replace_SHA');
        $m = stat(self::MAIN);
        if (!is_array($m)) throw new RuntimeException('live_stat_failed');
        $uid = (int)$m['uid'];
        $gid = (int)$m['gid'];
        $mode = (int)$m['mode'] & 0777;
        if ($uid <= 0 || ($mode & 0002) !== 0 || $mode < 0400) {
            throw new RuntimeException('unsafe_original_file_metadata');
        }
        self::must(self::syntaxValid($bytes),
            'replacement_PHP_parser_check');
        $temp = tempnam(dirname(self::MAIN), '.ev-warning-');
        if ($temp === false) {
            throw new RuntimeException('same_filesystem_temp_failed');
        }
        try {
            self::must(file_put_contents($temp, $bytes, LOCK_EX)
                    === strlen($bytes),
                'temporary_candidate_written');
            // CRITICAL: Metadata from live template, not root-only backup.
            self::must(chown($temp, $uid)
                    && chgrp($temp, $gid)
                    && chmod($temp, $mode),
                'original_site_owner_group_mode_preserved');
            clearstatcache(true, $temp);
            self::must(fileowner($temp) === $uid
                    && filegroup($temp) === $gid
                    && (fileperms($temp) & 0777) === $mode,
                'temporary_file_metadata_verified');
            // Rename atomic on same filesystem, no exec/proc_open calls.
            self::must(rename($temp, self::MAIN),
                'atomic_same_directory_template_replace');
            self::$siteTouched = true;
        } finally {
            if (is_file($temp)) @unlink($temp);
        }
    }

    private static function install(): void
    {
        self::preflight();
        $state = self::oldStatus();
        if ($state === 'INSTALLED') {
            self::$already = true;
            self::must(self::restoreState() === 'recovered',
                'already_installed_and_rollback_available');
            return;
        }
        self::must($state === 'PREPARED_NOT_INSTALLED',
            'resume_only_valid_prepared_release');
        // Restorer must exist and be tested before first production write.
        self::makeWorkingRestore();
        self::must(self::restoreState() === 'recovered',
            'preinstall_restore_ready');
        $bytes = file_get_contents(self::CANDIDATE);
        if (!is_string($bytes)) {
            throw new RuntimeException('candidate_read_failed');
        }
        echo "=== 3. APPLY WARNING-ONLY TEMPLATE UPDATE ===\n";
        self::atomicTemplateReplace($bytes, self::BEFORE);
        self::must(hash_equals(self::AFTER, self::sha(self::MAIN)),
            'installed_expected_candidate_SHA');
        echo "WARNING_RELOCATION=INSTALLED_INSIDE_SECURITY\n";
    }

    private static function status(): void
    {
        self::preflight();
        self::must(self::oldStatus() === 'INSTALLED',
            'live_warning_candidate_installed');
        self::must(self::restoreState() === 'recovered',
            'working_rollback_present');
        echo "WARNING_RELOCATION=INSTALLED_INSIDE_SECURITY\n";
    }

    private static function rollback(): void
    {
        self::preflight();
        self::must(self::restoreState() === 'recovered',
            'safe_restorer_available');
        self::must(self::oldStatus() === 'INSTALLED',
            'rollback_requires_pinned_installed_candidate');
        $bytes = file_get_contents(self::BACKUP);
        if (!is_string($bytes)) {
            throw new RuntimeException('backup_read_failed');
        }
        echo "=== 3. RESTORE ORIGINAL WARNING PLACEMENT ===\n";
        self::atomicTemplateReplace($bytes, self::AFTER);
        self::must(hash_equals(self::BEFORE, self::sha(self::MAIN)),
            'original_warning_and_email_badge_restored');
        self::$restored = true;
        echo "WARNING_RELOCATION=ROLLED_BACK_ONLY_THIS_PATCH\n";
    }

    private static function selfTest(): void
    {
        echo "=== 1. OFFLINE SELF TEST ===\n";
        self::must(PHP_SAPI === 'cli', 'CLI');
        $good = "<?php if (true): ?>\n"
            . "<div class=\"account-security-main\">test</div>\n"
            . "<?php endif; ?>\n";
        self::must(self::syntaxValid($good),
            'PHP_and_HTML_mixed_template_parses');
        self::must(!self::syntaxValid('<?php if (true) { '),
            'invalid_PHP_fails_closed_without_exec');
        self::must(strlen(self::BEFORE) === 64
            && strlen(self::AFTER) === 64
            && self::BEFORE !== self::AFTER,
            'exact_different_source_shas');
        self::must(str_contains(self::RELEASE,
            'account-warning-relocation'),
            'isolated_existing_warning_release_path');
        echo "EXTERNAL_EXEC_REQUIRED=NO\n";
    }

    public static function main(array $argv): void
    {
        $mode = $argv[1] ?? '';
        $rc = 0;
        try {
            if (count($argv) !== 2) {
                throw new RuntimeException('exact_one_mode');
            }
            if ($mode === '--self-test') {
                self::selfTest();
            } else {
                self::exactEnvironment();
                $lock = self::lockRelease();
                if ($mode === '--preflight') {
                    self::preflight();
                } elseif ($mode === '--apply') {
                    self::install();
                } elseif ($mode === '--status') {
                    self::status();
                } elseif ($mode === '--rollback') {
                    self::rollback();
                } else {
                    throw new RuntimeException('unknown_mode');
                }
            }
        } catch (Throwable $error) {
            $msg = $error->getMessage();
            if (!preg_match('/^[a-zA-Z_0-9]+$/D', $msg)) {
                $msg = 'safe_resume_failed_without_sensitive_details';
            }
            echo 'FAIL: ' . $msg . "\n";
            $rc = 1;
            if ($mode === '--apply' && self::$siteTouched
                && hash_equals(self::AFTER, self::sha(self::MAIN))) {
                try {
                    self::rollback();
                    echo "AUTOMATIC_ROLLBACK=PASS\n";
                } catch (Throwable) {
                    echo "AUTOMATIC_ROLLBACK=MANUAL_ATTENTION_REQUIRED\n";
                }
            }
        }
        echo "=== 5. RESULT ===\n";
        echo "MODE=$mode\n";
        echo 'PASS_COUNT=' . self::$pass . "\n";
        echo 'SOURCE_FILE_WRITE=' . (self::$siteTouched?'YES':'NO') . "\n";
        echo 'RESTORED=' . (self::$restored?'YES':'NO') . "\n";
        echo 'ALREADY_APPLIED=' . (self::$already?'YES':'NO') . "\n";
        echo "DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
        echo "EMAIL_SENT=NO\nSMS_SENT=NO\nREDIS_WRITE=NO\n";
        echo "SIGNIN_WRITE=NO\nPIN_WRITE=NO\nTRUSTED_DEVICE_WRITE=NO\n";
        echo "API_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
        echo "EMAIL_CONFIRM_BUTTON=NOT_INSTALLED\nEMAIL_FIRST_AUTH=OFF\n";
        echo 'FINAL_STATUS=' . ($rc === 0?'PASS':'FAIL') . "\n";
        echo "FINAL_RC=$rc\nTERMINAL_WILL_STAY_OPEN=YES\n";
        exit($rc);
    }
}
EvWarningSafeResume::main($argv);
