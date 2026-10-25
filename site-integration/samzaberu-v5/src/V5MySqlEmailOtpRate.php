<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlEmailOtp.php';

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * This MUST return the server-attested peer address, never untrusted
 * X-Forwarded-For, client JSON or an arbitrary mobile header. If the actual
 * reverse-proxy attribution is unknown, return null and refuse issuance.
 */
interface V5EmailOtpNetworkSourcePort
{
    public function currentVerifiedRemoteIp(): ?string;
}

/** Explicitly owner-configured individual quota; no default ceilings. */
final readonly class V5EmailOtpRateLimit
{
    public function __construct(
        public int $maximum,
        public int $windowSeconds,
        public bool $rollingCooldown = false,
    ) {
        if ($maximum < 1 || $maximum > 10000
            || $windowSeconds < 60 || $windowSeconds > 86400
            || ($rollingCooldown && ($maximum !== 1 || $windowSeconds > 3600))) {
            throw new InvalidArgumentException('v5_email_rate_policy_invalid');
        }
    }
}

/**
 * Reserve six local, HMAC-keyed windows in the SAME V5-owned InnoDB
 * transaction as b_user + AUTH_EPOCH + email challenge issuance.
 *
 * Account cooldown is a true rolling window rather than a minute bucket.
 * There is deliberately no shared global/day/hour cap for every guest.
 * Atomic row upsert, SELECT FOR UPDATE and compare-and-set UPDATE prevent
 * overlapping requests from bypassing limits. Only table proposal exists:
 * NO schema/data/website operations are performed by loading this file.
 */
final readonly class V5MySqlEmailOtpRate implements V5EmailOtpRatePort
{
    public const SCOPES = [
        'account_cooldown','account_hour','account_day',
        'email_hour','ip_hour','device_hour',
    ];

    public const UPSERT_SQL =
        'INSERT INTO ev_v5_email_rate_windows '
        .'(bucket_key,used_units,expires_at) VALUES (?,0,?) '
        .'ON DUPLICATE KEY UPDATE '
        .'used_units=IF(expires_at <= ?,0,used_units), '
        .'expires_at=IF(expires_at <= ?,VALUES(expires_at),expires_at)';

    public const LOCK_SQL =
        'SELECT used_units,expires_at FROM ev_v5_email_rate_windows '
        .'WHERE bucket_key = ? FOR UPDATE';

    public const CHARGE_SQL =
        'UPDATE ev_v5_email_rate_windows SET used_units = used_units + 1 '
        .'WHERE bucket_key = ? AND used_units = ? AND expires_at = ?';

    /** @param array<string,V5EmailOtpRateLimit> $limits */
    public function __construct(
        private PDO $db,
        private V5EmailOtpNetworkSourcePort $network,
        #[\SensitiveParameter] private string $rateHmacKey,
        private array $limits,
    ) {
        if (strlen($rateHmacKey) < 32 || strlen($rateHmacKey) > 1024
            || count($limits) !== count(self::SCOPES)
            || array_keys($limits) !== self::SCOPES) {
            throw new InvalidArgumentException('v5_email_rate_configuration_missing');
        }
        foreach (self::SCOPES as $scope) {
            if (!$limits[$scope] instanceof V5EmailOtpRateLimit)
                throw new InvalidArgumentException('v5_email_rate_configuration_missing');
        }
        if (!$limits['account_cooldown']->rollingCooldown
            || $limits['account_hour']->windowSeconds !== 3600
            || $limits['account_day']->windowSeconds !== 86400
            || $limits['email_hour']->windowSeconds !== 3600
            || $limits['ip_hour']->windowSeconds !== 3600
            || $limits['device_hour']->windowSeconds !== 3600) {
            throw new InvalidArgumentException('v5_email_rate_window_configuration_invalid');
        }
    }

    public function reserveWithLocks(
        PDO $db,int $userId,string $deviceId,string $emailStamp
    ):bool {
        if ($db !== $this->db || !$db->inTransaction() || $userId < 1
            || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || preg_match('/\Asz_[a-f0-9]{64}\z/D',$deviceId)!==1
            || preg_match('/\A[a-f0-9]{64}\z/D',$emailStamp)!==1) return false;

        try {
            $ip=$this->network->currentVerifiedRemoteIp();
            if (!is_string($ip) || filter_var($ip,FILTER_VALIDATE_IP)===false)
                return false;
            $now=self::number($this->exec('SELECT UNIX_TIMESTAMP()',[])->fetchColumn());
            if ($now===null || $now<1) return false;

            $identities=[
                'account_cooldown'=>(string)$userId,
                'account_hour'=>(string)$userId,
                'account_day'=>(string)$userId,
                'email_hour'=>$emailStamp,
                'ip_hour'=>inet_pton($ip),
                'device_hour'=>$deviceId,
            ];
            if (!is_string($identities['ip_hour'])) return false;

            $rows=[];
            foreach(self::SCOPES as $scope) {
                $limit=$this->limits[$scope];
                $window=$limit->windowSeconds;
                // Sliding cooldown retains a stable key and its first-send
                // deadline; fixed windows use a boundary-specific HMAC key.
                $period=$limit->rollingCooldown ? 'rolling' : (string)intdiv($now,$window);
                $expires=$limit->rollingCooldown
                    ? $now+$window
                    : (intdiv($now,$window)+1)*$window;
                if ($expires <= $now || $expires > PHP_INT_MAX-1) return false;
                $key=hash_hmac('sha256',
                    "evrasia:v5:email-rate:v1\0".$scope."\0"
                    .$identities[$scope]."\0".$period,$this->rateHmacKey);
                $rows[]=[$key,$expires,$limit->maximum];
            }
            // Lock the same six keys in canonical order across concurrent
            // requests; no shared-global limit or raw addresses are stored.
            usort($rows,static fn(array $a,array $b):int=>strcmp($a[0],$b[0]));
            foreach($rows as [$key,$expires,$maximum]) {
                $this->exec(self::UPSERT_SQL,[$key,$expires,$now,$now]);
                $row=$this->exec(self::LOCK_SQL,[$key])->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) return false;
                $used=self::number($row['used_units']??null);
                $actualExpiry=self::number($row['expires_at']??null);
                if ($used===null || $used >= $maximum
                    || $actualExpiry===null || $actualExpiry <= $now) return false;
                $write=$this->exec(self::CHARGE_SQL,[$key,$used,$actualExpiry]);
                if ($write->rowCount()!==1) return false;
            }
            // The caller owns the transaction: any false/exception makes the
            // issuer ROLLBACK all six charges and the pending challenge.
            return true;
        } catch(Throwable) {
            return false;
        }
    }

    private static function number(mixed $value):?int
    {
        if (is_int($value))return $value>=0?$value:null;
        if (!is_string($value)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)!==1) return null;
        $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        return $n===false?null:$n;
    }

    private function exec(string $sql,array $arguments):PDOStatement
    {
        $q=$this->db->prepare($sql);
        if (!$q instanceof PDOStatement || !$q->execute($arguments))
            throw new RuntimeException('v5_email_rate_sql_unavailable');
        return $q;
    }
}
