<?php
declare(strict_types=1);

namespace Bitrix\Security\Mfa {
    // CI-only lookalike. MUST be rejected by the real source-file SHA gate.
    // None of these stub methods may be called in a successful test.
    final class Otp {
        public int $calls=0;
        public function __construct(private int $userId) {}
        public function getUserId(): int { $this->calls++; return $this->userId; }
        public function getType(): string { $this->calls++; return 'totp'; }
        public function isInitialized(): bool { $this->calls++; return true; }
        public function isActivated(): bool { $this->calls++; return true; }
        public function getHexSecret(): string { $this->calls++; return str_repeat('a',40); }
        public function save(): bool { $this->calls++; return true; }
        public function delete(): self { $this->calls++; return $this; }
    }
}
namespace {
    // The CI-only class above has no legitimate installed Bitrix source SHA.
    if (getenv('GITHUB_ACTIONS')!=='true'
        || getenv('V5_CI_ONLY_ACK')!=='GITHUB_EPHEMERAL_MARIADB'
        || getenv('V5_CI_DB_HOST')!=='127.0.0.1'
        || getenv('V5_CI_DB_NAME')!=='ev_v5_ci_synthetic'
        || getenv('V5_CI_DB_USER')!=='ev_v5_ci'
        || in_array(gethostname(),['evrasia','eur-bot-01'],true)
        || !extension_loaded('pdo_mysql') || !extension_loaded('sodium')) {
        fwrite(STDERR, "FAIL: isolated native Bitrix OTP source adapter guard\n");
        exit(2);
    }

    require_once __DIR__ . '/V5EphemeralMySqlStagingNativeMutationScopeTest.php';
    require_once __DIR__ . '/../../src/V5BitrixOtpNativeMutationPort.php';

    $start=$checks;
    $db=smsDb();
    $native=new \Bitrix\Main\DB\MysqliConnection($db);
    \Bitrix\Main\Application::$current=$native;
    $bridge=new \Evrasia\Samzaberu\V5\V5BitrixPdoCompatibilityBridge(
        $native,new CiSmsBridgePermit());
    $stub=new \Bitrix\Security\Mfa\Otp(17001);
    foreach ([
        \Evrasia\Samzaberu\V5\V5NativeMutationPath::OtpSaveAndEnrollment,
        \Evrasia\Samzaberu\V5\V5NativeMutationPath::OtpDeleteAndDisable,
        \Evrasia\Samzaberu\V5\V5NativeMutationPath::PasswordChangeOrReset,
    ] as $kind) {
        smsDeny(fn()=>new \Evrasia\Samzaberu\V5\V5BitrixOtpNativeMutationPort(
            $stub,$kind,$bridge),
            'synthetic or wrong-mode Bitrix Otp cannot pass reviewed source pin');
    }
    smsCheck($stub->calls===0, 'unverified Bitrix OTP cannot execute or reveal OTP material');

    $proof=new CiScopeCoverage($bridge);
    $coverage=new \Evrasia\Samzaberu\V5\V5StrictNativeSecurityHookCoverage($proof);
    $phone=new \Evrasia\Samzaberu\V5\V5StrictE164Phone();
    $writer=new \Evrasia\Samzaberu\V5\V5SecurityEventVersionWriter(
        new \Evrasia\Samzaberu\V5\V5SecurityEventVersions(
            $phone,$coverage,str_repeat('F',32),str_repeat('G',32)));
    $scope=new \Evrasia\Samzaberu\V5\V5StagingNativeMutationScope(
        $bridge,$native,$coverage,$writer,$phone,new CiScopeDepth(),[17001]);
    $before=nativeEventSnapshot(smsDb(),17001);
    foreach ([
        \Evrasia\Samzaberu\V5\V5NativeMutationPath::OtpSaveAndEnrollment,
        \Evrasia\Samzaberu\V5\V5NativeMutationPath::OtpDeleteAndDisable,
        \Evrasia\Samzaberu\V5\V5NativeMutationPath::OtpRecoveryAndReset,
        \Evrasia\Samzaberu\V5\V5NativeMutationPath::OtpAutomaticReactivation,
    ] as $kind) {
        $fake=new CiReviewedNativeAction(null,true);
        smsDeny(fn()=> $scope->run(17001,$kind,$fake),
            'unreviewed native callback cannot claim MFA/agent/recovery path');
        smsCheck($fake->calls===0 && !$bridge->inTransaction(),
            'MFA gate rejects prior to any callback, SQL or new transaction');
    }
    smsCheck(nativeEventSnapshot(smsDb(),17001)===$before,
        'negative MFA gates never change actual disposable InnoDB state');
    $added=$checks-$start;
    echo "PASS: pinned native Bitrix OTP adapter negative release gates ($added additional assertions)\n";
    echo "PASS: full dependent chain ($checks assertions; 43 sessions separate)\n";
    echo "NATIVE_OTP_SOURCE_MATCHED=NO\nREAL_BITRIX_OTP_RUNTIME_TESTED=NO\n";
    echo "REAL_TEN_PATH_COVERAGE=NOT_ATTESTED\nPRODUCTION_WRITE=NO\n";
}
