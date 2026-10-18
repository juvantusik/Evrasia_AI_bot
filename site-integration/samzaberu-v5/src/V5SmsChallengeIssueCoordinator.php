<?php
declare(strict_types=1);
namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SmsChallengeFactory.php';
require_once __DIR__ . '/V5SmsLoginChallenge.php';
require_once __DIR__ . '/V5SmsReservedDispatcher.php';

use Throwable;

/**
 * Authoritative challenge persistence port. MUST atomically reject duplicate
 * attempts and persist the complete challenge before returning true.
 */
interface V5SmsChallengeIssueStore
{
    public function issueOnce(V5SmsLoginChallenge $challenge): bool;
}

/**
 * Login handler candidate: server-generated code/id, durable issuance,
 * quota reservation, then verified-account transport. Never issues tokens.
 * A resend must use a NEW attempt and fresh challenge; failed send consumes
 * its quota reservation and cannot authorize anything.
 */
final class V5SmsChallengeIssueCoordinator
{
    public function __construct(
        private V5SmsChallengeIssueStore $store,
        private V5SmsReservedDispatcher $dispatch,
    ) {}

    /** @return array{attempt_id:string,dispatched:bool}|null */
    public function start(int $verifiedUserId, string $serverSecret, int $now): ?array
    {
        if ($verifiedUserId < 1 || $verifiedUserId > 2147483647 || $now < 1) {
            return null;
        }
        try {
            $secret = V5SmsChallengeFactory::validateServerSecret($serverSecret);
            $id = V5SmsChallengeFactory::attemptId();
            $code = V5SmsChallengeFactory::code();
            $challenge = V5SmsLoginChallenge::issue($id, $verifiedUserId, $code, $secret, $now);
            if ($this->store->issueOnce($challenge) !== true) return null;
            $dispatched = $this->dispatch->dispatch($verifiedUserId, $id, $code, $now);
            return ['attempt_id' => $id, 'dispatched' => $dispatched];
        } catch (Throwable) {
            return null;
        }
    }
}
