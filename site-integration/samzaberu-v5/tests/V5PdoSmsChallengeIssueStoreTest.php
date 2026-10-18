<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5PdoSmsChallengeIssueStore.php';

use Evrasia\Samzaberu\V5\V5PdoSmsChallengeIssueStore;
use Evrasia\Samzaberu\V5\V5SmsLoginChallenge;

final class IssueRejectedPDO extends PDO
{
    public int $begins = 0;
    public bool $ambient = false;
    public function __construct() {}
    public function inTransaction(): bool { return $this->ambient; }
    public function beginTransaction(): bool { $this->begins++; return false; }
}
$db = new IssueRejectedPDO();
$store = new V5PdoSmsChallengeIssueStore($db);
$challenge = V5SmsLoginChallenge::issue(
    str_repeat('a',32),123,'012345',str_repeat('K',40),1000
);
if ($store->issueOnce($challenge)) {
    throw new RuntimeException('FAIL: rejected transaction permitted SMS');
}
if ($db->begins !== 1) throw new RuntimeException('FAIL: expected one BEGIN');
$db->ambient = true;
if ($store->issueOnce($challenge) || $db->begins !== 1) {
    throw new RuntimeException('FAIL: joined ambient transaction');
}
echo "PASS: SMS challenge issue store denies failed BEGIN and ambient transaction\n";
echo "DATABASE_CONNECTION=FAKE\nPRODUCTION_WRITE=NO\n";
