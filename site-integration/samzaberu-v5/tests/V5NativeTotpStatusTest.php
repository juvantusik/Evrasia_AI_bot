<?php
declare(strict_types=1);

namespace Bitrix\Security\Mfa {
    final class UserTable {
        public static array $rows = [];
        public static array $options = [];
        public static bool $throw = false;
        public static function getList(array $options): object {
            if(self::$throw) throw new \RuntimeException('simulated unavailable');
            self::$options = $options;
            return new class(array_slice(self::$rows, 0, $options['limit'] ?? 1)) {
                private int $index = 0;
                public function __construct(private array $rows) {}
                public function fetch(): array|false {
                    return $this->rows[$this->index++] ?? false;
                }
            };
        }
    }
}
namespace Evrasia\Samzaberu\V5 {
    require_once __DIR__ . '/../src/V5NativeTotpStatus.php';

    final class FakeMfaRows implements V5MfaAccountRowSourcePort {
        public ?array $row = null;
        public int $calls = 0;
        public bool $unavailable = false;
        public function findCurrentRow(int $userId): ?array {
            $this->calls++;
            if($this->unavailable) throw new \RuntimeException('simulated backend unavailable');
            return $this->row;
        }
    }
    final class FakeNoMfaPolicy implements V5NoMfaFallbackPolicyPort {
        public ?bool $allowed = null;
        public int $calls = 0;
        public function permitsPasswordPlusSms(int $userId): ?bool {
            $this->calls++;
            return $this->allowed;
        }
    }

    $pass = 0;
    function check(bool $yes,string $reason): void {
        global $pass;
        if(!$yes) throw new \RuntimeException('FAIL: '.$reason);
        $pass++;
    }
    function activeRow(array $different=[]):array {
        return array_replace([
            'USER_ID'=>42, 'ACTIVE'=>'Y', 'TYPE'=>'totp',
            'SECRET'=>str_repeat('a1',16), 'DEACTIVATE_UNTIL'=>null,
        ],$different);
    }
    function fixture(?array $row=null,?bool $allowed=null):array {
        $r=new FakeMfaRows();$r->row=$row;
        $p=new FakeNoMfaPolicy();$p->allowed=$allowed;
        return [new V5NativeTotpStatus($r,$p),$r,$p];
    }
    [$m,$r,$p]=fixture(activeRow());
    check($m->isTotpActive(42)===true,'active valid native TOTP');
    check($p->calls===0,'configured MFA never consult fallback');
    [$m,$r,$p]=fixture(activeRow(['SECRET'=>str_repeat('F0',32)]));
    check($m->isTotpActive(42)===true,'64 uppercase hex secret supported');
    foreach([null,false,true] as $policy) {
        [$m,$r,$p]=fixture(null,$policy);
        check($m->isTotpActive(42)===($policy===true?false:null),'absent MFA depends on verified mandatory policy');
        check($p->calls===1,'policy consulted when row absent');
    }
    foreach([
        ['ACTIVE'=>'N'],['ACTIVE'=>null],['ACTIVE'=>'inactive'],
        ['TYPE'=>'hotp'],['TYPE'=>'push'],['TYPE'=>''],['TYPE'=>null],
        ['SECRET'=>''],['SECRET'=>null],['SECRET'=>str_repeat('A',31)],
        ['SECRET'=>str_repeat('a1',15)],['SECRET'=>str_repeat('a1',33)],
        ['SECRET'=>'not-valid-secret'],['DEACTIVATE_UNTIL'=>'2030-01-01 00:00:00'],
        ['DEACTIVATE_UNTIL'=>''],['USER_ID'=>43],['USER_ID'=>'0'],
        ['USER_ID'=>'042'],['USER_ID'=>null],['USER_ID'=>0],
    ] as $bad) {
        [$m,$r,$p]=fixture(activeRow($bad),true);
        check($m->isTotpActive(42)===null,'never downgrade incomplete/other MFA: '.implode(',',array_keys($bad)));
        check($p->calls===0,'fallback never consulted for existing record');
    }
    [$m,$r,$p]=fixture(activeRow(['USER_ID'=>'42']));
    check($m->isTotpActive(42)===true,'Bitrix numeric string user ID');
    [$m,$r,$p]=fixture(activeRow());
    check($m->isTotpActive(0)===null,'zero ID denied');
    check($m->isTotpActive(-1)===null,'negative ID denied');
    check($r->calls===0 && $p->calls===0,'no backend IO for invalid ID');
    [$m,$r,$p]=fixture(activeRow());$r->unavailable=true;
    try {$m->isTotpActive(42);check(false,'outage must throw');}
    catch(\RuntimeException $e){check($p->calls===0,'backend outage cannot downgrade');}

    $native = new V5NativeBitrixMfaRowSource();
    \Bitrix\Security\Mfa\UserTable::$rows=[activeRow()];
    $row=$native->findCurrentRow(42);
    check(is_array($row) && $row['USER_ID']===42,'native ORM row mapping');
    check(\Bitrix\Security\Mfa\UserTable::$options['filter']===['=USER_ID'=>42],'native OTP filters user ID');
    check(\Bitrix\Security\Mfa\UserTable::$options['limit']===2,'native OTP checks ambiguity');
    check(in_array('DEACTIVATE_UNTIL',\Bitrix\Security\Mfa\UserTable::$options['select'],true),'MFA deactivation read');
    check(in_array('SECRET',\Bitrix\Security\Mfa\UserTable::$options['select'],true),'MFA credential read');
    \Bitrix\Security\Mfa\UserTable::$rows=[];
    check($native->findCurrentRow(42)===null,'no native MFA row returns null');
    \Bitrix\Security\Mfa\UserTable::$rows=[activeRow(),activeRow()];
    try {$native->findCurrentRow(42);check(false,'two native MFA rows must reject');}
    catch(\RuntimeException $e){check(true,'two native MFA rows rejected');}
    \Bitrix\Security\Mfa\UserTable::$rows=[];
    try {$native->findCurrentRow(0);check(false,'zero user ID must reject');}
    catch(\RuntimeException $e){check(true,'native reader guards ID');}
    \Bitrix\Security\Mfa\UserTable::$throw=true;
    try {$native->findCurrentRow(42);check(false,'OTP ORM backend outage must throw');}
    catch(\RuntimeException $e){check(true,'OTP ORM outage fail closed');}

    echo "PASS: conservative native Bitrix MFA status ($pass assertions)\n";
    echo "BITRIX_BOOTSTRAP=NO\nDATABASE_CONNECTIONS=NO\nPRODUCTION_MODIFIED=NO\n";
    echo "NO_MFA_FALLBACK_POLICY_IMPLEMENTED=NO\n";
}
