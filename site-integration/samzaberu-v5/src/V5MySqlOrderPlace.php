<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5AuthHttpHandler.php';

/**
 * V5 checkout uses ONLY its signed strong access JWT and the site-owned
 * native D7 business writer, never the V4 /cart/order controller.
 *
 * No checkout writer is installed implicitly. Without one, HTTP is 503.
 */
final readonly class V5MySqlOrderPlace implements V5OrderPlaceHttpPort
{
    public function __construct(
        private V5MySqlStrongSessionStore $sessions,
        private V5LockedOrderPlacementPort $orders
    ) {}

    /** @return array{id:int,number:string,status:string}|null */
    public function placeVerifiedOrder(
        #[\SensitiveParameter] string $accessJwt,
        string $requestId,
        array $checkout
    ): ?array {
        return $this->sessions->placeOrderForAccess(
            $accessJwt, $requestId, $checkout, $this->orders
        );
    }
}
