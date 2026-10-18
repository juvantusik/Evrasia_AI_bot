<?php
declare(strict_types=1);

namespace Bitrix\Main\DB {
    final class SignupFakeSqlHelper {
        public function forSql(string $value): string { return addslashes($value); }
    }
    final class SignupFakeResult {
        private int $at = 0;
        public function __construct(private array $rows) {}
        public function fetch(): mixed { return $this->rows[$this->at++] ?? false; }
    }
    final class MysqliConnection {
        public int $reads = 0;
        public int $writes = 0;
        public int $beginCalls = 0;
        public int $commitCalls = 0;
        public int $rollbackCalls = 0;
        private bool $transaction = false;
        public function getSqlHelper(): SignupFakeSqlHelper {
            return new SignupFakeSqlHelper();
        }
        public function startTransaction(): bool {
            $this->beginCalls++;
            if ($this->transaction) throw new \RuntimeException('duplicate tx');
            $this->transaction = true;
            return true;
        }
        public function commitTransaction(): bool {
            $this->commitCalls++;
            $this->transaction = false;
            return true;
        }
        public function rollbackTransaction(): bool {
            $this->rollbackCalls++;
            $this->transaction = false;
            return true;
        }
        public function query(string $sql): SignupFakeResult {
            $this->reads++;
            if ($sql === 'SELECT CONNECTION_ID() AS cid, @@session.in_transaction AS tx') {
                return new SignupFakeResult([[
                    'cid'=>'123','tx'=>$this->transaction ? '1' : '0',
                ]]);
            }
            throw new \RuntimeException('unreviewed fake query');
        }
        public function queryExecute(string $sql): bool {
            $this->writes++;
            throw new \RuntimeException('no native write permitted in tests');
        }
        public function getAffectedRowsCount(): int { return 0; }
    }
}
namespace Bitrix\Main {
    final class Application {
        public static ?\Bitrix\Main\DB\MysqliConnection $connection = null;
        public static function getConnection(): \Bitrix\Main\DB\MysqliConnection {
            if (self::$connection === null) throw new \RuntimeException('no native');
            return self::$connection;
        }
    }
}
namespace Bitrix\Main\UserConsent {
    // Deliberately NOT a source-attested native Bitrix class.
    final class Consent {
        public static int $calls = 0;
        public static function addByContext(
            $agreementId, $originatorId = null, $originId = null,
            array $params = [],
        ): ?int {
            self::$calls++;
            return 991;
        }
    }
}

namespace {
    require_once __DIR__ . '/../src/V5BitrixStagedSignupConsentWriter.php';

    use Bitrix\Main\Application;
    use Bitrix\Main\DB\MysqliConnection;
    use Bitrix\Main\UserConsent\Consent;
    use Evrasia\Samzaberu\V5\{
        V5BitrixPdoCompatibilityBridge,
        V5NativeBitrixExclusiveTransactionPermitPort,
        V5VerifiedNativeTransactionDepthPort,
        V5BitrixStagedSignupConsentWriter,
        V5SignupNativeConsentPlan
    };

    final class SyntheticNativeSignupPermit
        implements V5NativeBitrixExclusiveTransactionPermitPort {
        public function safeToBeginOn(object $nativeBitrixConnection): ?bool {
            return true;
        }
    }
    final class SyntheticNativeSignupDepth
        implements V5VerifiedNativeTransactionDepthPort {
        public int $reported = 1;
        public function depthFor(object $nativeConnection): ?int {
            return $this->reported;
        }
    }

    $checks=0;
    $pass=static function (bool $ok, string $what) use (&$checks): void {
        if (!$ok) throw new \RuntimeException('FAIL: '.$what);
        $checks++;
    };
    $denied=static function (callable $run, string $what) use ($pass): void {
        try {
            $run();
        } catch (\RuntimeException) {
            $pass(true, $what);
            return;
        }
        $pass(false, $what);
    };

    $docs = [
        ['code'=>'EVRASIA_OFFER_20261018','type'=>'offer','title'=>'Synthetic offer',
            'required'=>true,'format'=>'url','content'=>null,
            'url'=>'https://evrasia.spb.ru/legal/public-offer/'],
        ['code'=>'EVRASIA_PD_20261018','type'=>'personal_data','title'=>'Synthetic PD',
            'required'=>true,'format'=>'url','content'=>null,
            'url'=>'https://evrasia.spb.ru/legal/personal-data-consent/'],
    ];
    $rows=[
        ['ID'=>11,'CODE'=>'EVRASIA_OFFER_20261018','ACTIVE'=>'Y'],
        ['ID'=>12,'CODE'=>'EVRASIA_PD_20261018','ACTIVE'=>'Y'],
    ];
    $plan=V5SignupNativeConsentPlan::fromFreshNativeRows([
        'agreements'=>['EVRASIA_PD_20261018','EVRASIA_OFFER_20261018'],
    ],$docs,$rows);
    $native=new MysqliConnection();
    Application::$connection=$native;
    $db=new V5BitrixPdoCompatibilityBridge($native,new SyntheticNativeSignupPermit());
    $depth=new SyntheticNativeSignupDepth();

    // Default path: no staging acknowledgement, never create any consent.
    putenv('V5_STAGE_WRITE_ACK');
    $writer=new V5BitrixStagedSignupConsentWriter($db,$native,$depth);
    $denied(static fn()=> $writer->persistForNewUser(5678,$plan),
        'requires an isolated approved native transaction by default');
    $pass($native->writes===0 && Consent::$calls===0
        && $native->commitCalls===0,
        'default-deny does not touch native user or consents');
    $denied(static fn()=> $writer->persistForNewUser(5678,$plan),
        'writer instance is single-use even on failure');

    // Even when staging is acknowledged, no transaction may be inferred.
    putenv('V5_STAGE_WRITE_ACK=ISOLATED_STAGING_ONLY');
    $writer=new V5BitrixStagedSignupConsentWriter($db,$native,$depth);
    $denied(static fn()=> $writer->persistForNewUser(5678,$plan),
        'staging signal never substitutes for bridge ownership');
    $pass($native->reads===0 && $native->writes===0,
        'no unowned DB reads/writes');

    $pass($db->beginTransaction(),'synthetic bridge acquired a transaction');
    $depth->reported=0;
    $writer=new V5BitrixStagedSignupConsentWriter($db,$native,$depth);
    $denied(static fn()=> $writer->persistForNewUser(5678,$plan),
        'native Bitrix depth must be independently attested');
    $pass(Consent::$calls===0 && $native->writes===0,
        'unverified depth never invokes Consent::addByContext');
    $depth->reported=1;

    // A fake class with an identical static method/signature still cannot
    // pass the exact installed native source SHA / case-sensitive path pin.
    $writer=new V5BitrixStagedSignupConsentWriter($db,$native,$depth);
    $denied(static fn()=> $writer->persistForNewUser(5678,$plan),
        'synthetic or tampered Consent.php source cannot pass pin');
    $pass($native->reads===1 && $native->writes===0
        && Consent::$calls===0,'source check denies BEFORE any user/consent SQL');
    $pass($native->commitCalls===0,'writer never commits underlying transaction');
    $pass($db->rollBack(),'caller still owns rollback after failed native proof');
    $pass($native->rollbackCalls===1 && $native->commitCalls===0,
        'rollback responsibility remains with independent transaction owner');

    putenv('V5_STAGE_WRITE_ACK');
    echo "PASS: V5 staged native Bitrix signup consent writer negative guards "
        . "($checks assertions)\n";
    echo "SOURCE=UNTRUSTED_SYNTHETIC_BITRIX_ONLY\n";
    echo "REAL_BITRIX_NATIVE_CALLS=NO\n";
    echo "CONSENT_WRITE=NO\n";
    echo "SITE_FILE_WRITE=NO\n";
    echo "PRODUCTION_DEPLOYED=NO\n";
    echo "V5_HTTP_ACTIVATED=NO\n";
}
