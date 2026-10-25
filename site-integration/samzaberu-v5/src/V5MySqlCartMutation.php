<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5AuthHttpHandler.php';

/** Native mobile cart edits use only the existing V5 strong-session store. */
final readonly class V5MySqlCartMutation implements V5CartHttpMutationPort
{
    public function __construct(
        private V5MySqlStrongSessionStore $sessions,
        private V5LockedCartMutatorPort $mutator,
    ) {}

    public function changeVerifiedCartItem(
        #[\SensitiveParameter] string $accessJwt,
        int $itemId, ?int $quantity
    ): ?bool {
        return $this->sessions->changeCartForAccess(
            $accessJwt,$itemId,$quantity,$this->mutator
        );
    }
}
