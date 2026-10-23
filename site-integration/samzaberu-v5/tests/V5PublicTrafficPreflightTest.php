<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5PublicTrafficPreflight.php';

use Evrasia\Samzaberu\V5\{
    V5PublicTrafficPreflight,
    V5PublicDependencyAttestationPort,
    V5TrustedInstalledNativeCoveragePort,
};

final class PublicPreflightFakePDO extends PDO {
    public string $driver = 'mysql';
    public int $mode = PDO::ERRMODE_EXCEPTION;
    public bool $transactionOpen = false;
    public bool $throw = false;
    public function __construct() {}
    public function getAttribute(int $attribute): mixed {
        if ($this->throw) throw new RuntimeException('sensitive db problem');
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => $this->driver,
            PDO::ATTR_ERRMODE => $this->mode,
            default => null,
        };
    }
    public function inTransaction(): bool { return $this->transactionOpen; }
}
final class PublicPreflightNativeFake implements V5TrustedInstalledNativeCoveragePort {
    public ?bool $ready = null;
    public bool $throw = false;
    public int $calls = 0;
    public int $userCalls = 0;
    public function releaseVerifiedOnThisServer(PDO $db): ?bool {
        $this->calls++;
        if ($this->throw) throw new RuntimeException('sensitive native problem');
        return $this->ready;
    }
    public function proofForUser(PDO $db, int $userId): ?array {
        $this->userCalls++;
        return null;
    }
}
final class PublicPreflightDepsFake implements V5PublicDependencyAttestationPort {
    public ?bool $ready = null;
    public bool $throw = false;
    public int $calls = 0;
    public function verifiedForPublicTraffic(PDO $db): ?bool {
        $this->calls++;
        if ($this->throw) throw new RuntimeException('sensitive provider problem');
        return $this->ready;
    }
}

$checks = 0;
function publicPreflightCheck(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
}

$db = new PublicPreflightFakePDO();
$native = new PublicPreflightNativeFake();
$deps = new PublicPreflightDepsFake();
$gate = new V5PublicTrafficPreflight($native, $deps);
$server = ['HTTPS'=>'on', 'REMOTE_ADDR'=>'198.51.100.23',
    'REQUEST_METHOD'=>'POST', 'REQUEST_URI'=>'/api/v5/auth/signin'];

publicPreflightCheck(!$gate->mayDispatch($db, $server), 'default no native attestation denies');
publicPreflightCheck($deps->calls === 0, 'default deny before dependency attestation');
$native->ready = false;
publicPreflightCheck(!$gate->mayDispatch($db, $server), 'native explicit false denies');
$native->ready = true;
publicPreflightCheck(!$gate->mayDispatch($db, $server), 'native release alone cannot expose public auth');
$deps->ready = false;
publicPreflightCheck(!$gate->mayDispatch($db, $server), 'public dependency failure denies');
$deps->ready = true;
publicPreflightCheck($gate->mayDispatch($db, $server), 'synthetic complete release preflight accepts');

foreach ([
    '192.0.2.12', '198.51.100.120', '203.0.113.13', '2001:db8::1',
    '2001:db8::aff', '', null,
] as $peer) {
    $changed = array_replace($server, ['REMOTE_ADDR'=>$peer]);
    publicPreflightCheck($gate->mayDispatch($db, $changed),
        'client public IP or missing REMOTE_ADDR does not restrict eligibility');
}
publicPreflightCheck($gate->mayDispatch($db, array_replace($server,
    ['HTTP_X_FORWARDED_FOR'=>'192.168.103.141', 'HTTP_X_REAL_IP'=>'127.0.0.1'])),
    'spoofed client IP headers cannot become an allowlist');
publicPreflightCheck(!$gate->mayDispatch($db, array_replace($server,
    ['HTTPS'=>'off', 'HTTP_X_FORWARDED_PROTO'=>'https'])),
    'forwarded HTTPS cannot bypass trusted web-server TLS');
foreach ([false, null, [], 'true', 'https', 'OFF'] as $https) {
    publicPreflightCheck(!$gate->mayDispatch($db, array_replace($server, ['HTTPS'=>$https])),
        'untrusted HTTPS state denies');
}
publicPreflightCheck($gate->mayDispatch($db, array_replace($server, ['HTTPS'=>'1'])),
    'trusted HTTPS one accepted by original contract');
foreach (['', null, str_repeat('b', 64)] as $pilot) {
    publicPreflightCheck(!$gate->mayDispatch($db, array_replace($server,
        ['HTTP_X_EVRASIA_V5_PILOT'=>$pilot])), 'pilot credential cannot open public channel');
}
$db->driver = 'sqlite';
publicPreflightCheck(!$gate->mayDispatch($db, $server), 'different SQL driver denies');
$db->driver = 'mysql'; $db->mode = PDO::ERRMODE_SILENT;
publicPreflightCheck(!$gate->mayDispatch($db, $server), 'non-exception SQL mode denies');
$db->mode = PDO::ERRMODE_EXCEPTION; $db->transactionOpen = true;
publicPreflightCheck(!$gate->mayDispatch($db, $server), 'inherited transaction denies');
$db->transactionOpen = false; $db->throw = true;
publicPreflightCheck(!$gate->mayDispatch($db, $server), 'PDO failure denies');
$db->throw = false; $native->throw = true;
publicPreflightCheck(!$gate->mayDispatch($db, $server), 'native proof exception denies');
$native->throw = false; $deps->throw = true;
publicPreflightCheck(!$gate->mayDispatch($db, $server), 'provider exception denies');
$deps->throw = false; $native->ready = null;
publicPreflightCheck(!$gate->mayDispatch($db, $server), 'native proof revoked denies');
publicPreflightCheck($native->userCalls === 0, 'preflight never substitutes per-user coverage');

printf("PASS: V5 public mobile preflight (%d synthetic assertions)\n", $checks);
echo "REAL_NATIVE_PROVIDERS_VERIFIED=NO\n";
echo "PUBLIC_FACTORY_INSTALLED=NO\n";
echo "LIVE_V5_AUTH_ENABLED=NO\n";
echo "PRODUCTION_DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\nSMS_SENT=NO\n";
