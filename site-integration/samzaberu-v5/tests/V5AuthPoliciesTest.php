<?php
declare(strict_types=1);

namespace Bitrix\Security\Mfa {
    final class Otp {
        public static mixed $enabled = true;
        public static mixed $mandatory = false;
        public static bool $throws = false;
        public static int $readEnabled = 0;
        public static int $readMandatory = 0;
        public static function isOtpEnabled(): mixed {
            self::$readEnabled++;
            if (self::$throws) throw new \RuntimeException('private Bitrix error');
            return self::$enabled;
        }
        public static function isMandatoryUsing(): mixed {
            self::$readMandatory++;
            if (self::$throws) throw new \RuntimeException('private Bitrix error');
            return self::$mandatory;
        }
    }
}
namespace Evrasia\Samzaberu\V5 {
    require_once __DIR__ . '/../src/V5ExistingLoginPolicy.php';
    require_once __DIR__ . '/../src/V5NoMfaFallbackPolicy.php';

    use InvalidArgumentException;
    use RuntimeException;

    final class ControlsFake implements V5CurrentLoginControlsPort {
        public ?V5CurrentLoginControls $result = null;
        public bool $throws = false;
        public int $calls = 0;
        public function readForUser(int $userId): ?V5CurrentLoginControls {
            $this->calls++;
            if ($this->throws) throw new RuntimeException('private current controls failure');
            return $this->result;
        }
    }
    final class GlobalFake implements V5GlobalOtpConfigPort {
        public ?V5GlobalOtpConfig $result = null;
        public bool $throws = false;
        public int $calls = 0;
        public function read(): ?V5GlobalOtpConfig {
            $this->calls++;
            if ($this->throws) throw new RuntimeException('private MFA settings failure');
            return $this->result;
        }
    }
    final class PerUserFake implements V5PerUserSmsEligibilityPort {
        public ?bool $allowed = null;
        public int $calls = 0;
        public function isSmsAllowedWithoutNativeMfa(int $userId): ?bool {
            $this->calls++;
            return $this->allowed;
        }
    }
    final class MfaRowsFake implements V5MfaAccountRowSourcePort {
        public ?array $row = null;
        public int $calls = 0;
        public function findCurrentRow(int $userId): ?array {
            $this->calls++;
            return $this->row;
        }
    }

    $passes = 0;
    function ok(bool $actual, string $why): void {
        global $passes;
        if (!$actual) throw new RuntimeException('FAIL: ' . $why);
        $passes++;
    }
    function restrictions(?int $max, ?bool $lock = true, ?bool $security = true, ?bool $site = true): V5CurrentLoginControls {
        return new V5CurrentLoginControls($max,$lock,$security,$site);
    }
    function setupLogin(?V5CurrentLoginControls $record): array {
        $fake = new ControlsFake();
        $fake->result = $record;
        return [new V5StrictExistingLoginPolicy($fake), $fake];
    }
    function setupMfa(?V5GlobalOtpConfig $global, ?bool $perUser = true): array {
        $cfg = new GlobalFake(); $cfg->result = $global;
        $user = new PerUserFake(); $user->allowed = $perUser;
        return [new V5StrictNoMfaFallbackPolicy($cfg,$user),$cfg,$user];
    }
    function deniedOrUnknown(?bool $result): bool { return $result !== true; }

    // LOGIN: configured limit, including strict boundary and no magic number.
    foreach ([1, 2, 5, 17, 500] as $max) {
        [$policy,$source] = setupLogin(restrictions($max));
        ok($policy->permits(42,0) === true, 'zero attempts below configured threshold');
        ok($policy->permits(42,$max-1) === true, 'last permitted attempt');
        ok($policy->permits(42,$max) === false, 'limit reached');
        ok($policy->permits(42,$max+10) === false, 'limit exceeded');
        ok($source->calls === 4, 'current limit checked on every decision');
    }
    foreach ([-1,0,1000001] as $bad) {
        try { restrictions($bad); ok(false,'invalid configured limit must throw'); }
        catch (InvalidArgumentException $e) { ok(true,'invalid configured limit rejected'); }
    }
    [$policy,$source] = setupLogin(restrictions(5));
    foreach ([0,-1] as $uid) {
        ok($policy->permits($uid,1) === null,'invalid account ID denied');
    }
    ok($policy->permits(42,-1) === null,'negative attempts denied');
    ok($source->calls === 0,'invalid policy input no provider calls');
    [$policy,$source] = setupLogin(null);
    ok($policy->permits(42,0) === null,'missing current policy unknown');
    ok($source->calls === 1,'missing current policy consulted');
    [$policy] = setupLogin(restrictions(null));
    ok($policy->permits(42,0) === null,'unspecified threshold never assumed unlimited');
    foreach ([0,1,2] as $which) {
        $flags = [true,true,true];
        $flags[$which] = false;
        [$policy] = setupLogin(restrictions(9,...$flags));
        ok($policy->permits(42,0) === false,'known site restriction denies');
        $flags[$which] = null;
        [$policy] = setupLogin(restrictions(9,...$flags));
        ok($policy->permits(42,0) === null,'unknown restriction denies');
    }
    [$policy] = setupLogin(restrictions(null,false,null,null));
    ok($policy->permits(42,0) === false,'known lockout denial overrides unknown fields');
    [$policy] = setupLogin(restrictions(null,null,false,null));
    ok($policy->permits(42,0) === false,'known hook denial overrides unknown threshold');
    [$policy,$source] = setupLogin(restrictions(5));
    $source->throws = true;
    try {$policy->permits(42,0);ok(false,'reader outage may not grant');}
    catch (RuntimeException $e){ok(true,'reader outage fails closed');}

    // MFA: absence of b_sec_user row alone never authorizes SMS.
    foreach ([true,false] as $enabled) {
        foreach ([true,false] as $smsAllowed) {
            [$policy,$global,$user] = setupMfa(new V5GlobalOtpConfig($enabled,false),$smsAllowed);
            ok($policy->permitsPasswordPlusSms(42) === $smsAllowed,'nonmandatory plus per-user verified permit');
            ok($user->calls === 1,'per-user MFA policy checked');
            ok($global->calls === 1,'current global setting consulted');
        }
    }
    foreach ([true,false] as $enabled) {
        [$policy,$global,$user] = setupMfa(new V5GlobalOtpConfig($enabled,true),true);
        ok($policy->permitsPasswordPlusSms(42) === false,'mandatory OTP never downgraded');
        ok($user->calls === 0,'mandatory OTP bypass impossible');
    }
    foreach ([null,true,false] as $enabled) {
        foreach ([null,true,false] as $mandatory) {
            if (is_bool($enabled) && is_bool($mandatory)) continue;
            [$policy,$global,$user] = setupMfa(new V5GlobalOtpConfig($enabled,$mandatory),true);
            ok($policy->permitsPasswordPlusSms(42) === null,'unknown global config denies');
            ok($user->calls === 0,'unknown global config never asks per-user fallback');
        }
    }
    [$policy,$global,$user] = setupMfa(null,true);
    ok($policy->permitsPasswordPlusSms(42) === null,'unavailable global MFA policy denies');
    ok($user->calls === 0,'unavailable MFA policy no fallback');
    [$policy,$global,$user] = setupMfa(new V5GlobalOtpConfig(true,false),null);
    ok($policy->permitsPasswordPlusSms(42) === null,'unknown per-user group setting denies');
    [$policy,$global,$user] = setupMfa(new V5GlobalOtpConfig(true,false),true);
    foreach ([0,-9] as $uid) ok($policy->permitsPasswordPlusSms($uid) === null,'invalid MFA account ID denies');
    ok($global->calls === 0 && $user->calls === 0,'invalid MFA ID no provider calls');
    [$policy,$global,$user] = setupMfa(new V5GlobalOtpConfig(true,false),true);
    $global->throws = true;
    try {$policy->permitsPasswordPlusSms(42);ok(false,'settings outage must not grant');}
    catch (RuntimeException $e){ok(true,'settings outage fails closed');}

    // Bitrix native option adapter: only two public static methods, no user session.
    $native = new V5NativeBitrixGlobalOtpConfig();
    foreach ([true,false] as $enabled) {
        foreach ([true,false] as $mandatory) {
            \Bitrix\Security\Mfa\Otp::$enabled = $enabled;
            \Bitrix\Security\Mfa\Otp::$mandatory = $mandatory;
            $evidence = $native->read();
            ok($evidence instanceof V5GlobalOtpConfig,'native source returns snapshot');
            ok($evidence->enabled === $enabled && $evidence->mandatory === $mandatory,'native options read correctly');
        }
    }
    \Bitrix\Security\Mfa\Otp::$enabled = 'Y';
    ok($native->read() === null,'unexpected type from native options is unknown');
    \Bitrix\Security\Mfa\Otp::$enabled = true;
    \Bitrix\Security\Mfa\Otp::$mandatory = 'N';
    ok($native->read() === null,'nonboolean mandatory response is unknown');
    \Bitrix\Security\Mfa\Otp::$mandatory = false;
    \Bitrix\Security\Mfa\Otp::$throws = true;
    ok($native->read() === null,'native option exception is unknown');
    \Bitrix\Security\Mfa\Otp::$throws = false;
    ok(\Bitrix\Security\Mfa\Otp::$readEnabled >= 5,'native feature option read');
    ok(\Bitrix\Security\Mfa\Otp::$readMandatory >= 4,'native mandatory option read');

    // End-to-end policy boundary: the fallback port is used ONLY on an absent
    // native MFA row; present/inactive/unsupported credentials never downgrade.
    $rows = new MfaRowsFake();
    [$fallback,$global,$user] = setupMfa(new V5GlobalOtpConfig(true,false),true);
    $status = new V5NativeTotpStatus($rows,$fallback);
    ok($status->isTotpActive(42) === false,'no MFA row and authoritative per-user SMS approval');
    ok($user->calls === 1,'absent MFA read per-user approval exactly once');
    $rows->row = [
        'USER_ID'=>42,'ACTIVE'=>'Y','TYPE'=>'totp',
        'SECRET'=>str_repeat('a1',16),'DEACTIVATE_UNTIL'=>null,
    ];
    ok($status->isTotpActive(42) === true,'configured TOTP always wins');
    ok($user->calls === 1,'configured TOTP never opens SMS policy');
    $rows->row['ACTIVE'] = 'N';
    ok($status->isTotpActive(42) === null,'disabled native factor is unknown and denied');
    ok($user->calls === 1,'disabled factor cannot trigger SMS fallback');
    $rows->row = null;
    [$mandatory,$global2,$user2] = setupMfa(new V5GlobalOtpConfig(true,true),true);
    $status2 = new V5NativeTotpStatus($rows,$mandatory);
    ok($status2->isTotpActive(42) === null,'mandatory configured rule blocks no-MFA login');
    ok($user2->calls === 0,'mandatory rule skips SMS approval');

    echo "PASS: V5 login + native MFA policy boundaries ($passes assertions)\n";
    echo "USER_TABLE_READ=NO\nBITRIX_BOOTSTRAP=NO\nDATABASE_CONNECTIONS=NO\n";
    echo "CUSER_LOGIN_CALLED=NO\nSMS_SENT=NO\nJWT_CREATED=NO\n";
    echo "ACTUAL_SITE_LOGIN_RULES_IMPLEMENTED=NO\nPER_USER_SMS_POLICY_IMPLEMENTED=NO\n";
}
