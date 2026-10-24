<?php
declare(strict_types=1);

require_once __DIR__ . '/../deployment/website-mfa/VerifiedEmailStatus.php';
require_once __DIR__ . '/../deployment/website-mfa/EmailOwnershipProofStore.php';

use Evrasia\WebMfa\VerifiedEmailStatus as Status;
use Evrasia\WebMfa\EmailOwnershipProofStore as Store;

/** No SQL server: fake PDO enforces source SQL shape and transaction steps. */
final class MemoryStorePdo extends PDO
{
    public bool $transaction = false;
    public bool $failWrite = false;
    public bool $failQuery = false;
    public array $calls = [];
    public array|false $statusRow = false;
    public array|false $lockedRow = false;

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->failQuery) {
            throw new RuntimeException('query failure');
        }
        return new MemoryStoreStmt($this, $query);
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function beginTransaction(): bool
    {
        $this->calls[] = 'BEGIN';
        $this->transaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->calls[] = 'COMMIT';
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->calls[] = 'ROLLBACK';
        $this->transaction = false;
        return true;
    }
}

final class MemoryStoreStmt extends PDOStatement
{
    private MemoryStorePdo $db;
    private string $sql;

    public function __construct(MemoryStorePdo $db, string $sql)
    {
        $this->db = $db;
        $this->sql = $sql;
    }

    public function execute(?array $params = null): bool
    {
        $which = str_contains($this->sql, 'FOR UPDATE')
            ? 'LOCK' : (str_contains($this->sql, 'INSERT INTO') ? 'WRITE' : 'READ');
        $this->db->calls[] = [$which, $params, $this->sql];
        if ($which === 'WRITE' && $this->db->failWrite) {
            throw new RuntimeException('DB insert fail');
        }
        return true;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return str_contains($this->sql, 'FOR UPDATE')
            ? $this->db->lockedRow : $this->db->statusRow;
    }
}

$passes = 0;
$check = static function(bool $ok, string $label) use (&$passes): void {
    if (!$ok) {
        throw new RuntimeException('failed_' . $label);
    }
    $passes++;
};

try {
    $now = 1800000000;
    $stamp = $now - 240;
    $userId = 77;
    $email = 'verified@example.com';
    $secret = str_repeat('P', 48);
    $mac = Status::emailMac($userId,$email,$secret);
    $check(is_string($mac),'generated_email_mac');
    $pdo=new MemoryStorePdo();
    $store=new Store($pdo,$secret);
    $check(Store::TABLE==='ev_web_email_ownership_proof','dedicated_table');
    $schema=Store::expectedTableDdl();
    $check(str_contains($schema,'CREATE TABLE ev_web_email_ownership_proof')
        && str_contains($schema,'ENGINE=InnoDB')
        && str_contains($schema,'PRIMARY KEY (user_id)')
        && !str_contains($schema,'IF NOT EXISTS')
        && !str_contains($schema,'EMAIL VARCHAR')
        && !str_contains($schema,'PASSWORD')
        && !str_contains($schema,' DROP '),
        'no_automatic_schema_install_or_raw_email');
    $check($store->status(0,$now)['verified']===false,'no_user_denied');
    $check($store->status($userId,$now)['verified']===false,
        'no_proof_fails_closed');
    $pdo->statusRow=[
        'profile_email'=>$email,'profile_stamp'=>(string)$stamp,
        'proof_user'=>(string)$userId,'proof_mac'=>$mac,
        'proof_stamp'=>(string)$stamp,'proof_time'=>(string)($now-20),
        'proof_source'=>Status::SOURCE_OTP,'proof_revoked'=>'0',
    ];
    $s=$store->status($userId,$now);
    $check($s['verified']===true && $s['login_email_allowed']===true,
        'read_source_verified_proof_allows_green_and_login');
    $check($pdo->transaction===false,'status_read_never_begins_transaction');
    $pdo->statusRow['profile_stamp']=(string)($stamp+1);
    $check($store->status($userId,$now)['verified']===false,
        'profile_updated_after_verification_invalidates');
    $pdo->statusRow['profile_stamp']=(string)$stamp;
    $pdo->statusRow['proof_revoked']='1';
    $check($store->status($userId,$now)['verified']===false,
        'revoked_row_cannot_authorize_email');
    $pdo->statusRow['proof_revoked']='0';
    $pdo->statusRow['profile_email']='changed@example.com';
    $check($store->status($userId,$now)['verified']===false,
        'changed_email_cannot_reuse_old_proof');
    $pdo->statusRow['profile_email']=$email;
    $pdo->failQuery=true;
    $check($store->status($userId,$now)['verified']===false,
        'DB_failure_denies_green_and_email_login');
    $pdo->failQuery=false;

    $receipt=[
        'user_id'=>$userId,
        'current_email_mac'=>$mac,
        'profile_updated_at'=>$stamp,
        'email_changed_at'=>$stamp,
        'verified_at'=>$now-1,
        'source'=>Status::SOURCE_OTP,
        'revoked'=>false,
    ];
    $pdo->lockedRow=['EMAIL'=>$email,'stamp'=>(string)$stamp];
    $pdo->calls=[];
    $check($store->saveAfterProof($receipt,$now),'correct_receipt_persisted');
    $check($pdo->transaction===false,'transaction_committed');
    $check($pdo->calls[0]==='BEGIN' && $pdo->calls[count($pdo->calls)-1]==='COMMIT',
        'lock_then_insert_then_commit');
    $queries=array_values(array_filter($pdo->calls,'is_array'));
    $check(count($queries)===2 && $queries[0][0]==='LOCK'
        && $queries[1][0]==='WRITE','only_current_user_lock_and_ledger_write');
    $check(str_contains($queries[0][2],'FROM b_user WHERE ID=? FOR UPDATE'),
        'source_Bitrix_user_locked_by_id');
    $check(str_contains($queries[1][2],'ON DUPLICATE KEY UPDATE')
        && str_contains($queries[1][2],'ev_web_email_ownership_proof'),
        'only_dedicated_proof_ledger_written');
    $check($queries[1][1][1]===$mac
        && !str_contains(json_encode($queries[1][1],JSON_THROW_ON_ERROR),$email),
        'no_raw_email_in_ledger_rows');

    $pdo->lockedRow['EMAIL']='other@example.com';$pdo->calls=[];
    $check(!$store->saveAfterProof($receipt,$now),
        'mismatched_email_after_OTP_write_denied');
    $check($pdo->calls[count($pdo->calls)-1]==='ROLLBACK',
        'mismatch_rollback');
    $pdo->lockedRow=['EMAIL'=>$email,'stamp'=>(string)($stamp+1)];
    $check(!$store->saveAfterProof($receipt,$now),
        'profile_changed_after_OTP_denied');
    $pdo->lockedRow=['EMAIL'=>$email,'stamp'=>(string)$stamp];
    $pdo->failWrite=true;
    $check(!$store->saveAfterProof($receipt,$now)
        && !$pdo->inTransaction(),
        'DB_write_failure_rolls_back_and_fails_closed');
    $pdo->failWrite=false;
    $pdo->transaction=true;
    $check(!$store->saveAfterProof($receipt,$now),
        'no_write_inside_unowned_transaction');
    $pdo->transaction=false;

    $bad=$receipt;$bad['source']='legacy_timestamp';
    $check(!$store->saveAfterProof($bad,$now),
        'legacy_signup_field_not_accepted_as_OTP_proof');
    $bad=$receipt;$bad['verified_at']=$now-100;
    $check(!$store->saveAfterProof($bad,$now),
        'stale_receipt_denied');
    $bad=$receipt;$bad['email_changed_at']=$stamp-1;
    $check(!$store->saveAfterProof($bad,$now),
        'profile_stamp_inconsistent_denied');
    $bad=$receipt;$bad['revoked']=true;
    $check(!$store->saveAfterProof($bad,$now),
        'revoked_receipt_denied');

    echo 'PASS: email ownership ledger source-controlled SQL and fail-closed '
        . $passes . " assertions\n";
    echo "MODE=OFFLINE_FAKE_PDO\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
    echo "EMAIL_SENT=NO\nSMS_SENT=NO\nPRODUCTION_SITE_WRITE=NO\n";
    echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
} catch (Throwable) {
    echo "FAIL: ownership ledger offline test\n";
    echo "FINAL_STATUS=FAIL\nFINAL_RC=1\n";
    exit(1);
}
