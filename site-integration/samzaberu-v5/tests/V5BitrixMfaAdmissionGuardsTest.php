<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5BitrixMfaAdmissionGuards.php';

use Evrasia\Samzaberu\V5\{
    V5NativeMandatoryRightsProbePort,V5PinnedBitrixMandatoryRightsProbe,
    V5MandatoryRightsSmsEligibility,V5PerUserSmsEligibilityPort,
    V5NativeTotpEnabledGate,V5NativeTotpStatusPort,V5GlobalOtpConfig,
    V5GlobalOtpConfigPort
};

final class V5RightsFake implements V5NativeMandatoryRightsProbePort {
    public ?bool $v = null;
    public bool $error = false;
    public function userTargeted(int $id): ?bool {
        if ($this->error) throw new RuntimeException('private');
        return $this->v;
    }
}
final class V5SmsSiteFake implements V5PerUserSmsEligibilityPort {
    public ?bool $v = null;
    public int $calls = 0;
    public function isSmsAllowedWithoutNativeMfa(int $id): ?bool {
        $this->calls++;
        return $this->v;
    }
}
final class V5TotpNativeFake implements V5NativeTotpStatusPort {
    public ?bool $v = null;
    public bool $error = false;
    public function isTotpActive(int $id): ?bool {
        if ($this->error) throw new RuntimeException('private');
        return $this->v;
    }
}
final class V5GlobalOtpFake implements V5GlobalOtpConfigPort {
    public ?V5GlobalOtpConfig $v = null;
    public bool $flip = false;
    public int $calls = 0;
    public function read(): ?V5GlobalOtpConfig {
        $this->calls++;
        return $this->flip && $this->calls % 2 === 0
            ? new V5GlobalOtpConfig(true, true) : $this->v;
    }
}
$checks = 0;
function v5AdmissionCheck(bool $yes, string $why): void {
    global $checks;
    if (!$yes) throw new RuntimeException('FAIL ' . $why);
    $checks++;
}

$rights = new V5RightsFake();
$site = new V5SmsSiteFake();
$sms = new V5MandatoryRightsSmsEligibility($rights, $site);

v5AdmissionCheck($sms->isSmsAllowedWithoutNativeMfa(0) === null, 'invalid user');
v5AdmissionCheck($sms->isSmsAllowedWithoutNativeMfa(41) === null, 'unknown mandatory rights');
v5AdmissionCheck($site->calls === 0, 'never delegate unknown rights');
$rights->v = true;
$site->v = true;
v5AdmissionCheck($sms->isSmsAllowedWithoutNativeMfa(41) === false,
    'mandatory rights deny even if site allows');
v5AdmissionCheck($site->calls === 0, 'never delegate mandatory rights');
$rights->v = false;
$site->v = null;
v5AdmissionCheck($sms->isSmsAllowedWithoutNativeMfa(41) === null, 'unknown site policy denies');
$site->v = false;
v5AdmissionCheck($sms->isSmsAllowedWithoutNativeMfa(41) === false, 'explicit site veto denies');
$site->v = true;
v5AdmissionCheck($sms->isSmsAllowedWithoutNativeMfa(41) === true,
    'only absent mandatory rights and confirmed independent site approval');
$rights->error = true;
v5AdmissionCheck($sms->isSmsAllowedWithoutNativeMfa(41) === null, 'rights exception denies');

$native = new V5TotpNativeFake();
$global = new V5GlobalOtpFake();
$gate = new V5NativeTotpEnabledGate($native, $global);
v5AdmissionCheck($gate->isTotpActive(0) === null, 'invalid user');
v5AdmissionCheck($gate->isTotpActive(41) === null, 'unknown global OTP');
$global->v = new V5GlobalOtpConfig(false, false);
$native->v = true;
v5AdmissionCheck($gate->isTotpActive(41) === null,
    'configured OTP cannot be strong MFA when global verifier disabled');
$global->v = new V5GlobalOtpConfig(true, false);
v5AdmissionCheck($gate->isTotpActive(41) === true, 'enabled TOTP stays required');
$native->v = false;
v5AdmissionCheck($gate->isTotpActive(41) === false,
    'optional and unconfigured permits downstream SMS rules');
$global->v = new V5GlobalOtpConfig(true, true);
v5AdmissionCheck($gate->isTotpActive(41) === null, 'mandatory cannot fall back to SMS');
$global->v = new V5GlobalOtpConfig(null, null);
v5AdmissionCheck($gate->isTotpActive(41) === null, 'unknown config denies');
$global->v = new V5GlobalOtpConfig(true, false);
$global->flip = true;
$global->calls = 0;
v5AdmissionCheck($gate->isTotpActive(41) === null, 'racing global policy denies');
$global->flip = false;
$native->v = null;
v5AdmissionCheck($gate->isTotpActive(41) === null, 'unknown native MFA denies');
$native->error = true;
v5AdmissionCheck($gate->isTotpActive(41) === null, 'native backend exception denies');

$probe = new V5PinnedBitrixMandatoryRightsProbe();
v5AdmissionCheck($probe->userTargeted(0) === null, 'invalid native rights probe');
v5AdmissionCheck($probe->userTargeted(41) === null,
    'unverified native Bitrix source cannot grant rights');

printf("PASS: V5 native MFA admission guards (%d synthetic assertions)\n", $checks);
echo "REAL_BITRIX_MFA_EXECUTED=NO\n";
echo "SITE_DATABASE_ACCESS=NO\nBITRIX_BOOTSTRAP=NO\nSMS_SENT=NO\nV5_AUTH_ENABLED=NO\n";
