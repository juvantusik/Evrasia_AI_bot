<?php
declare(strict_types=1);

namespace Bitrix\Main {
    final class UserTable {
        public static array $rows = [];
        public static ?array $options = null;
        public static bool $throw = false;
        public static function getList(array $options): object {
            if(self::$throw) throw new \RuntimeException('source error');
            self::$options = $options;
            $items = array_slice(self::$rows,0,$options['limit'] ?? 1);
            return new class($items) {
                private int $offset = 0;
                public function __construct(private array $rows) {}
                public function fetch(): array|false {
                    return $this->rows[$this->offset++] ?? false;
                }
            };
        }
    }
}
namespace {
    class PhoneConfirm {
        public static int $calls = 0;
        public static function setPhoneMask(string $input): string {
            self::$calls++;
            if ($input === 'invalid') return '';
            return 'mask_' . $input;
        }
    }
}
namespace Evrasia\Samzaberu\V5 {
    require_once __DIR__ . '/../src/V5SiteAccountReader.php';

    final class FakeRows implements V5AccountRowSourcePort {
        public array $rows = [];
        public int $calls=0;
        public ?string $field=null;
        public ?string $login=null;
        public bool $outage=false;
        public function findAtMostTwo(string $field,string $normalizedLogin):array {
            $this->calls++;$this->field=$field;$this->login=$normalizedLogin;
            if($this->outage)throw new \RuntimeException('db outage');
            return $this->rows;
        }
    }
    final class FakePhone implements V5PhoneLoginNormalizerPort {
        public int $calls=0;
        public bool $outage=false;
        public ?string $value='mask_79210000000';
        public function normalizeForLookup(string $input):?string {
            $this->calls++;
            if($this->outage)throw new \RuntimeException('formatter outage');
            return $this->value;
        }
    }
    final class FakeTotp implements V5NativeTotpStatusPort {
        public int $calls=0;public ?bool $status=false;
        public function isTotpActive(int $id):?bool {$this->calls++;return $this->status;}
    }
    final class FakePolicy implements V5ExistingLoginPolicyPort {
        public int $calls=0;public ?bool $allowed=true;
        public ?int $attempts=null;
        public function permits(int $userId,int $loginAttempts):?bool {
            $this->calls++;$this->attempts=$loginAttempts;return $this->allowed;
        }
    }

    function row(array $overrides=[]):array {
        return array_replace([
            'ID'=>'123','LOGIN'=>'mask_79210000000','EMAIL'=>'a@example.invalid',
            'PASSWORD'=>'synthetic_hash','ACTIVE'=>'Y','BLOCKED'=>'N',
            'PASSWORD_EXPIRED'=>'N','LOGIN_ATTEMPTS'=>'2',
        ],$overrides);
    }
    function testFixture(array $rows=[]):array {
        $r=new FakeRows();$r->rows=$rows;$p=new FakePhone();$m=new FakeTotp();$l=new FakePolicy();
        return [new V5SiteAccountReader($r,$p,$m,$l),$r,$p,$m,$l];
    }
    $passes=0;
    function ok(bool $v,string $name):void {
        global $passes;
        if(!$v)throw new \RuntimeException('FAIL: '.$name);
        $passes++;
    }
    function failAuth(callable $fn,string $name):void {
        try{$fn();}catch(AuthenticationRejected $e){ok(true,$name);return;}
        ok(false,$name);
    }

    [$reader,$r,$p,$m,$l]=testFixture([row()]);
    $x=$reader->findUniqueByLogin('a@example.invalid');
    ok($x instanceof V5PasswordAccountSnapshot,'email identified');
    ok($r->field==='EMAIL' && $r->login==='a@example.invalid','email filter');
    ok($p->calls===0,'email skips phone mask');
    ok($x->userId===123 && $x->passwordHash==='synthetic_hash','server ID and hash');
    ok($x->bitrixActive && $x->antiFraudAllowed && !$x->nativeTotpActive,'normal account allowed');
    ok($l->attempts===2 && $m->calls===1,'policy and OTP consulted');

    [$reader,$r,$p,$m,$l]=testFixture([row()]);
    $x=$reader->findUniqueByLogin('79210000000');
    ok($x instanceof V5PasswordAccountSnapshot,'phone identified');
    ok($r->field==='LOGIN' && $r->login==='mask_79210000000','phone formatted through site port');
    ok($p->calls===1,'phone formatter invoked exactly once');

    [$reader,$r,$p,$m,$l]=testFixture([]);
    ok($reader->findUniqueByLogin('a@example.invalid')===null,'not-found denied');
    ok($m->calls===0 && $l->calls===0,'no OTP/status queries for missing account');

    [$reader,$r,$p,$m,$l]=testFixture([row(),row(['ID'=>124])]);
    failAuth(fn()=>$reader->findUniqueByLogin('a@example.invalid'),'duplicate denied');
    ok($m->calls===0 && $l->calls===0,'duplicate never checks user policy');

    [$reader,$r,$p,$m,$l]=testFixture([row(),row(),row()]);
    failAuth(fn()=>$reader->findUniqueByLogin('a@example.invalid'),'invalid 3-row source denied');
    ok($m->calls===0,'invalid rows never considered');

    foreach ([
        ['ACTIVE'=>'N'], ['BLOCKED'=>'Y'], ['PASSWORD_EXPIRED'=>'Y'],
    ] as $flags) {
        [$reader,$r,$p,$m,$l]=testFixture([row($flags)]);
        $x=$reader->findUniqueByLogin('a@example.invalid');
        ok($x instanceof V5PasswordAccountSnapshot,'disallowed account still representable');
        ok(!($x->bitrixActive && $x->antiFraudAllowed),'disallowed account fail closed');
    }
    [$reader,$r,$p,$m,$l]=testFixture([row()]);
    $m->status=true;$x=$reader->findUniqueByLogin('a@example.invalid');
    ok($x->nativeTotpActive,'native TOTP active propagated');

    foreach ([null,false] as $policy) {
        [$reader,$r,$p,$m,$l]=testFixture([row()]);
        $l->allowed=$policy;
        if($policy===null){failAuth(fn()=>$reader->findUniqueByLogin('a@example.invalid'),'unknown policy denies');}
        else {ok($reader->findUniqueByLogin('a@example.invalid')->bitrixActive===false,'login policy denies');}
    }
    [$reader,$r,$p,$m,$l]=testFixture([row()]);
    $m->status=null;failAuth(fn()=>$reader->findUniqueByLogin('a@example.invalid'),'unknown OTP denies');

    foreach ([
        ['ID'=>null],['ID'=>0],['ID'=>'001'],['ID'=>'x'],['ID'=>-1],
        ['PASSWORD'=>null],['PASSWORD'=>''],['PASSWORD'=>str_repeat('X',4097)],
        ['ACTIVE'=>null],['ACTIVE'=>'MAYBE'],['BLOCKED'=>null],['BLOCKED'=>1],
        ['PASSWORD_EXPIRED'=>null],['PASSWORD_EXPIRED'=>''],
        ['LOGIN_ATTEMPTS'=>null],['LOGIN_ATTEMPTS'=>-1],
        ['LOGIN_ATTEMPTS'=>'-1'],['LOGIN_ATTEMPTS'=>'1.5'],
        ['LOGIN_ATTEMPTS'=>'a'],['LOGIN_ATTEMPTS'=>str_repeat('9',70)],
    ] as $broken) {
        [$reader,$r,$p,$m,$l]=testFixture([row($broken)]);
        failAuth(fn()=>$reader->findUniqueByLogin('a@example.invalid'),'bad row denied '.implode(',',array_keys($broken)));
        ok($m->calls===0 && $l->calls===0,'bad row avoids side policy calls');
    }

    foreach(['',str_repeat('a',321)] as $login) {
        [$reader,$r,$p,$m,$l]=testFixture([row()]);
        ok($reader->findUniqueByLogin($login)===null,'invalid login denied');
        ok($r->calls===0 && $p->calls===0,'invalid login no query');
    }
    foreach([null,'',str_repeat('a',321)] as $canonical) {
        [$reader,$r,$p,$m,$l]=testFixture([row()]);
        $p->value=$canonical;
        failAuth(fn()=>$reader->findUniqueByLogin('79210000000'),'bad phone canonical denied');
        ok($r->calls===0,'bad phone canonical no ORM query');
    }
    [$reader,$r,$p,$m,$l]=testFixture([row()]);
    $p->outage=true;
    try {$reader->findUniqueByLogin('79210000000');ok(false,'phone outage must throw');}
    catch(\RuntimeException $ex){ok($r->calls===0,'phone outage fail closed');}
    [$reader,$r,$p,$m,$l]=testFixture([row()]);
    $r->outage=true;
    try {$reader->findUniqueByLogin('a@example.invalid');ok(false,'ORM outage must throw');}
    catch(\RuntimeException $ex){ok($m->calls===0,'ORM outage fail closed');}

    $native=new V5NativeBitrixUserRowSource();
    \Bitrix\Main\UserTable::$rows=[row()];
    $got=$native->findAtMostTwo('EMAIL','a@example.invalid');
    ok(count($got)===1,'native ORM first row');
    ok(\Bitrix\Main\UserTable::$options['filter']===['EMAIL'=>'a@example.invalid'],'native ORM exact filter');
    ok(\Bitrix\Main\UserTable::$options['limit']===2,'native ORM limit 2');
    ok(in_array('PASSWORD',\Bitrix\Main\UserTable::$options['select'],true),'native ORM includes password hash');
    ok(in_array('PASSWORD_EXPIRED',\Bitrix\Main\UserTable::$options['select'],true),'native ORM includes expiration');
    ok(in_array('LOGIN_ATTEMPTS',\Bitrix\Main\UserTable::$options['select'],true),'native ORM includes attempts');
    ok(!array_key_exists('BLOCKED',\Bitrix\Main\UserTable::$options['filter']),'native lookup does not prefilter BLOCKED');
    \Bitrix\Main\UserTable::$rows=[row(),row(['ID'=>124]),row(['ID'=>125])];
    ok(count($native->findAtMostTwo('LOGIN','mask_79210000000'))===2,'native ORM detects ambiguity');
    try {$native->findAtMostTwo('BLOCKED','anything');ok(false,'field injection must deny');}
    catch(\RuntimeException $ex){ok(true,'unknown field denied');}
    \Bitrix\Main\UserTable::$throw=true;
    try {$native->findAtMostTwo('EMAIL','a@example.invalid');ok(false,'ORM exception must propagate');}
    catch(\RuntimeException $ex){ok(true,'ORM exception fail closed');}

    $phone=new V5NativePhoneLoginNormalizer();
    $before=\PhoneConfirm::$calls;
    ok($phone->normalizeForLookup('79210000000')==='mask_79210000000','native phone legacy mask');
    ok(\PhoneConfirm::$calls===$before+1,'native phone method invoked');
    ok($phone->normalizeForLookup('invalid')==='','invalid phone remains invalid');

    echo "PASS: V5 site account reader ($passes assertions)\n";
    echo "BITRIX_BOOTSTRAP=NO\nDATABASE_READ=NO\nUSER_RECORDS_READ=NO\n";
    echo "PRODUCTION_MODIFIED=NO\nLOGIN_POLICY_PROVIDER_IMPLEMENTED=NO\nNATIVE_MFA_PROVIDER_IMPLEMENTED=NO\n";
}
