<?php
declare(strict_types=1);

// SYNTHETIC fake native class owners for offline tests ONLY. This source is
// never loaded on the actual Bitrix site and performs no SQL/HTTP I/O.
namespace Bitrix\Main\DB {
    final class TestLegalRows {
        private int $offset = 0;
        public function __construct(private array $rows) {}
        public function fetch(): mixed { return $this->rows[$this->offset++] ?? false; }
    }
    final class MysqliConnection {
        public int $queryCalls = 0;
        public bool $throw = false;
        public bool $badResult = false;
        public string $lastQuery = '';
        public function __construct(public array $rows) {}
        public function query(string $sql): mixed
        {
            $this->queryCalls++;
            $this->lastQuery = $sql;
            if ($this->throw) throw new \RuntimeException('PRIVATE_DB_CANARY');
            if ($this->badResult) return false;
            if (!str_starts_with($sql, 'SELECT ')
                || !str_contains($sql, ' FROM b_consent_agreement ')
                || !str_contains($sql, "ACTIVE = 'Y'")
                || !str_contains($sql, ' LIMIT 8')
                || str_contains($sql, 'b_consent_user_consent')
                || str_contains($sql, 'USER_ID')
                || str_contains($sql, 'DELETE ')
                || str_contains($sql, 'INSERT ')
                || str_contains($sql, 'UPDATE ')) {
                throw new \RuntimeException('only bounded public agreement SELECT is permitted');
            }
            return new TestLegalRows($this->rows);
        }
    }
}
namespace Bitrix\Main {
    final class Application {
        public static mixed $connection = null;
        public static function getConnection(): mixed { return self::$connection; }
    }
}

namespace {
    require_once __DIR__ . '/../src/V5BitrixCurrentLegalCatalog.php';
    require_once __DIR__ . '/../src/V5HttpTransportBoundary.php';

    use Bitrix\Main\Application;
    use Bitrix\Main\DB\MysqliConnection;
    use Evrasia\Samzaberu\V5\{
        V5BitrixCurrentLegalCatalog,V5BitrixLegalAgreementRows,
        V5AuthHttpHandler,V5HttpTransportBoundary,V5StagedHttpRequest
    };

    $count = 0;
    $check = static function (bool $ok, string $what) use (&$count): void {
        if (!$ok) throw new \RuntimeException('FAIL: '.$what);
        $count++;
    };
    $denied = static function (callable $operation, string $what) use ($check): void {
        try {
            $operation();
        } catch (\RuntimeException $e) {
            $check($what, true);
            return;
        }
        $check($what, false);
    };

    $make = static function (
        string $code,string $name,string $url,
        string $useUrl = 'Y', ?string $content = null,string $html = 'N'
    ): array {
        return [
            'CODE' => $code, 'NAME' => $name, 'ACTIVE' => 'Y',
            'USE_URL' => $useUrl, 'URL' => $url,
            'AGREEMENT_TEXT' => $content,
            'IS_AGREEMENT_TEXT_HTML' => $html,
        ];
    };
    $offer = $make('EVRASIA_OFFER_20260911','Current offer', '/legal/public-offer/');
    $pd = $make('EVRASIA_PD_20260911','Current personal data', '/legal/personal-data-consent/');
    $marketing = $make('EVRASIA_MARKETING_20260911','Optional marketing', '/legal/marketing-consent/');
    $rows = [$marketing, $pd, $offer]; // Native order is NOT reliable.
    $conn = new MysqliConnection($rows);
    Application::$connection = $conn;
    $catalog = new V5BitrixCurrentLegalCatalog($conn);
    $documents = $catalog->currentPublicDocuments();

    $check(count($documents) === 3, 'three native current documents');
    $check(array_column($documents,'type') === ['offer','personal_data','marketing'],
        'canonical order unaffected by MySQL order');
    $check(array_column($documents,'code') === [
        'EVRASIA_OFFER_20260911','EVRASIA_PD_20260911','EVRASIA_MARKETING_20260911',
    ], 'exact live native revision codes used');
    $check(array_column($documents,'required') === [true,true,false],
        'offer/PD required and marketing optional');
    $check($documents[0]['url'] === 'https://evrasia.spb.ru/legal/public-offer/',
        'canonical source URL resolved without network');
    $check($conn->queryCalls === 1
        && str_contains($conn->lastQuery, 'CODE REGEXP')
        && !str_contains($conn->lastQuery, '20260911'),
        'bounded current-native-read does not pin an old revision');
    $check(!isset($documents[0]['accepted']) && !isset($documents[0]['USER_ID']),
        'anonymous response never contains identity or acceptance');

    // Catalog-only binding requires NO login workflow, token validator, Bitrix
    // sessions, consent writer or user account. Other V5 operations stay 503.
    $boundary = new V5HttpTransportBoundary(new V5AuthHttpHandler(
        null,null,null,$catalog
    ));
    $read = new V5StagedHttpRequest('GET', '/api/v5/legal/consents', true, '', '');
    $r = $boundary->dispatch($read);
    $json = json_decode($r->jsonBody,true,16,JSON_THROW_ON_ERROR);
    $check($r->status === 200 && $json === ['documents'=>$documents],
        'native agreement source integrated with public V5 JSON handler');
    $check(str_contains($r->headers['Cache-Control'] ?? '', 'no-store')
        && !isset($r->headers['Set-Cookie']), 'legal read never sets auth cookies');
    foreach ([
        ['POST','/api/v5/auth/signin'],
        ['POST','/api/v5/auth/challenge/verify'],
        ['POST','/api/v5/auth/challenge/resend'],
        ['POST','/api/v5/auth/refresh'],
        ['POST','/api/v5/auth/token/validate'],
        ['POST','/api/v5/loyalty/pin'],
    ] as [$method,$path]) {
        $raw='{"unrelated":true}';
        $r=$boundary->dispatch(new V5StagedHttpRequest(
            $method,$path,true,'application/json',$raw
        ));
        $check($r->status===503
            && json_decode($r->jsonBody,true,16,JSON_THROW_ON_ERROR)
                === ['error'=>'v5_unavailable'],
            'legal-only handler cannot enable protected V5 routes');
    }

    // Native code works with a new revision WITHOUT client-side/static code
    // replacement. All other older active codes must be deactivated.
    $newPd = $make('EVRASIA_PD_20261018','Updated PD','/legal/personal-data-consent/');
    $conn->rows = [$offer,$newPd];
    $new=$catalog->currentPublicDocuments();
    $check(count($new) === 2 && $new[1]['code'] === 'EVRASIA_PD_20261018',
        'new live current revision used without updating PHP');
    $conn->rows = [$offer, $newPd, $pd];
    $denied(static fn() => $catalog->currentPublicDocuments(),
        'two simultaneously active PD revisions fail closed');
    $conn->rows = [$pd,$marketing];
    $denied(static fn() => $catalog->currentPublicDocuments(),
        'missing active offer fails closed');
    $conn->rows = [$offer];
    $denied(static fn() => $catalog->currentPublicDocuments(),
        'incomplete native catalog fails closed');
    $conn->rows = [$offer, $pd, $marketing, $newPd];
    $denied(static fn() => $catalog->currentPublicDocuments(),
        'more than three native rows fails closed');

    $invalidRows = [
        [$offer, array_replace($pd,['ACTIVE'=>'N'])],
        [$offer, array_replace($pd,['CODE'=>'FOREIGN_PD_20260911'])],
        [$offer, array_replace($pd,['CODE'=>'EVRASIA_PD_20260911?'])],
        [$offer, array_replace($pd,['CODE'=>'EVRASIA_OFFER_20260911'])],
        [$offer, array_replace($pd,['URL'=>'https://evil.example/legal/personal-data-consent/'])],
        [$offer, array_replace($pd,['URL'=>'/legal/public-offer/'])],
        [$offer, array_replace($pd,['URL'=>'http://evrasia.spb.ru/legal/personal-data-consent/'])],
        [$offer, array_replace($pd,['URL'=>'https://evrasia.spb.ru/legal/personal-data-consent/?v=1'])],
        [$offer, array_replace($pd,['USE_URL'=>'Y','URL'=>''])],
        [$offer, array_replace($pd,['NAME'=>str_repeat('X',241)])],
        [$offer, array_replace($pd,['NAME'=>''])],
        [$offer, array_replace($pd,['IS_AGREEMENT_TEXT_HTML'=>'UNKNOWN'])],
        [$offer, array_replace($pd,['AGREEMENT_TEXT'=>'','USE_URL'=>'N','URL'=>'',
            'IS_AGREEMENT_TEXT_HTML'=>'Y'])],
        [$offer, array_replace($pd,['AGREEMENT_TEXT'=>str_repeat('x',65537),
            'USE_URL'=>'N','URL'=>'','IS_AGREEMENT_TEXT_HTML'=>'N'])],
        [$offer, array_replace($pd,['IS_AGREEMENT_TEXT_HTML'=>null])],
        [$offer, array_replace($pd,['NAME'=>"\xC3\x28"])],
        [$offer, array_replace($pd,['URL'=>'https://evrasia.spb.ru@evil.test/legal/personal-data-consent/'])],
    ];
    foreach ($invalidRows as $bad) {
        $denied(static fn() => V5BitrixLegalAgreementRows::mapCurrent($bad),
            'malformed native legal definition denies output');
    }

    $validHtmlPd = array_replace($pd, [
        'USE_URL'=>'N','URL'=>'','IS_AGREEMENT_TEXT_HTML'=>'Y',
        'AGREEMENT_TEXT'=>'<p>Current PD agreement</p>',
    ]);
    $mapped = V5BitrixLegalAgreementRows::mapCurrent([$validHtmlPd,$offer]);
    $check($mapped[1]['format']==='html'
        && $mapped[1]['content']==='<p>Current PD agreement</p>'
        && $mapped[1]['url']==='https://evrasia.spb.ru/legal/personal-data-consent/',
        'native current HTML text returned only when USE_URL=N');
    $validTextPd = array_replace($validHtmlPd, [
        'IS_AGREEMENT_TEXT_HTML'=>'N','AGREEMENT_TEXT'=>'Current PD plain text',
    ]);
    $mapped = V5BitrixLegalAgreementRows::mapCurrent([$offer,$validTextPd]);
    $check($mapped[1]['format']==='text'
        && $mapped[1]['content']==='Current PD plain text',
        'native current text returned without interpreting as HTML');

    // Independent connection identity and bad source I/O are hard failures.
    $conn->rows = [$offer,$pd,$marketing];
    Application::$connection = new MysqliConnection([]);
    $denied(static fn() => $catalog->currentPublicDocuments(),
        'wrong native application connection rejected before SELECT');
    Application::$connection = $conn;
    $conn->badResult=true;
    $denied(static fn() => $catalog->currentPublicDocuments(),
        'native result unavailable rejects');
    $conn->badResult=false;
    $conn->throw=true;
    try {
        $catalog->currentPublicDocuments();
        $check(false,'native read throws failure');
    } catch (\RuntimeException $e) {
        $check($e->getMessage()==='v5_native_legal_catalog_unavailable',
            'native private DB exception never propagated to public handler');
    }
    $conn->throw=false;
    $denied(static fn() => new V5BitrixCurrentLegalCatalog(new \stdClass()),
        'invalid native driver refused before any query');
    Application::$connection=null;

    echo "PASS: V5 native Bitrix legal adapter synthetic source tests ($count checks)\n";
    echo "REAL_BITRIX_USED=NO\nDATABASE_CONNECTION=NO\nBITRIX_BOOTSTRAP=NO\n";
    echo "AUTHORIZATION_WRITE=NO\nCONSENT_WRITE=NO\nPRODUCTION_DEPLOYED=NO\n";
}
