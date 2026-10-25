<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixEmailOtpTransport.php';

use PDO;
use PDOStatement;
use Throwable;

/**
 * Read-only evidence from the EXISTING site Bitrix mail registry. Never
 * fetches MESSAGE/EMAIL_TO bodies: MySQL returns only boolean placeholder
 * presence. No PDO fallback, synthetic accounts, new database or DDL.
 *
 * Uses the same physical Bitrix DB connection through the strict PDO bridge.
 * Call only AFTER the email issuance transaction commits, immediately before
 * the D7 mail enqueue. This proof NEVER sends a message.
 */
final readonly class V5BitrixCurrentEmailLoginTemplateProof
    implements V5CurrentBitrixEmailLoginTemplatePort
{
    public const SITE_ID = 's1';

    // Both SELECTs are exact allowlisted SQL forms in the Bitrix PDO bridge.
    // Use CHAR(35) for '#' because the bridge rejects raw comment markers.
    public const TEMPLATE_SQL =
        "SELECT ID,EVENT_NAME,ACTIVE,LID,"
        ."IF(INSTR(COALESCE(EMAIL_TO,''),CONCAT(CHAR(35),'EMAIL_TO',CHAR(35)))>0,1,0) AS email_to_ok,"
        ."IF(INSTR(COALESCE(MESSAGE,''),CONCAT(CHAR(35),'VERIFY_CODE',CHAR(35)))>0,1,0) AS verify_code_ok "
        ."FROM b_event_message WHERE ID = ? LIMIT 2";

    public const SITE_SQL =
        'SELECT SITE_ID FROM b_event_message_site '
        .'WHERE EVENT_MESSAGE_ID = ? ORDER BY SITE_ID LIMIT 2';

    public function __construct(private PDO $bitrixConnection) {}

    public function approvedForLogin(
        string $eventName, string $siteId, int $messageId
    ): ?bool {
        if ($eventName !== V5BitrixEmailOtpTransport::LIVE_LOGIN_EVENT
            || $messageId !== V5BitrixEmailOtpTransport::LIVE_LOGIN_TEMPLATE_ID
            || $siteId !== self::SITE_ID) {
            return false;
        }

        try {
            if ($this->bitrixConnection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
                || $this->bitrixConnection->getAttribute(PDO::ATTR_ERRMODE)
                    !== PDO::ERRMODE_EXCEPTION
                || $this->bitrixConnection->inTransaction()) {
                // Issuance commits BEFORE native D7 enqueue; never send mail
                // within a V5-auth-owned locking transaction.
                return false;
            }

            $template=$this->readAtMostTwo(
                self::TEMPLATE_SQL,[$messageId]
            );
            if (count($template)!==1) return false;

            $site=$this->readAtMostTwo(
                self::SITE_SQL,[$messageId]
            );
            return self::matchesAttestedMetadata($template,$site);
        } catch (Throwable) {
            // Missing mail tables, foreign DB scope, unknown connection
            // or malformed metadata must never cause a positive response.
            return false;
        }
    }

    /**
     * Pure policy check for the observed Bitrix event 95 / site s1.
     * The template's #CODE_TTL_MINUTES# field is intentionally OPTIONAL;
     * the server-owned OTP lifecycle enforces a 300-second expiration.
     *
     * @param list<array<string,mixed>> $templateRows
     * @param list<array<string,mixed>> $siteRows
     */
    public static function matchesAttestedMetadata(
        array $templateRows,array $siteRows
    ):bool {
        if (count($templateRows)!==1 || count($siteRows)!==1) return false;
        $t=$templateRows[0];$site=$siteRows[0];
        if (!is_array($t) || !is_array($site)) return false;

        $id=$t['ID']??null;
        $recipient=$t['email_to_ok']??null;
        $code=$t['verify_code_ok']??null;
        return ($id==='95' || $id===95)
            && ($t['EVENT_NAME']??null)
                === V5BitrixEmailOtpTransport::LIVE_LOGIN_EVENT
            && ($t['ACTIVE']??null)==='Y'
            && ($t['LID']??null)===self::SITE_ID
            && ($recipient==='1' || $recipient===1)
            && ($code==='1' || $code===1)
            && ($site['SITE_ID']??null)===self::SITE_ID;
    }

    /** @return list<array<string,mixed>> */
    private function readAtMostTwo(string $sql,array $parameters):array
    {
        $stmt=$this->bitrixConnection->prepare($sql);
        if (!$stmt instanceof PDOStatement || !$stmt->execute($parameters))
            throw new \RuntimeException('v5_login_template_query_unavailable');
        $rows=[];
        for($i=0;$i<2;$i++){
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if ($row===false) break;
            if (!is_array($row))throw new \RuntimeException('v5_mail_registry_invalid');
            $rows[]=$row;
        }
        // An extra row would make the template/site selection ambiguous.
        if ($stmt->fetch(PDO::FETCH_ASSOC)!==false)
            throw new \RuntimeException('v5_mail_registry_ambiguous');
        return $rows;
    }
}
