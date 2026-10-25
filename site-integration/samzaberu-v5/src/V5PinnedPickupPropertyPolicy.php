<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixNativePickupMaterializer.php';

use RuntimeException;

/**
 * Exact verified V4 permanent checkout flag: IGNORE_RESTIS=Y.
 * The source/happytime literals are still masked in read-only diagnostics:
 * a trusted site config supplies them, never an application HTTP payload.
 *
 * This class only formats Bitrix properties; it does NOT suppress, enqueue
 * or dispatch a RestIS order and cannot substitute the existing Sale hook.
 */
final readonly class V5PinnedPickupPropertyPolicy implements V5PickupPropertyPolicyPort
{
    public function __construct(
        private string $siteSource,
        private string $siteHappytime,
    ) {
        if ($siteSource === '' || strlen($siteSource) > 120
            || strlen($siteHappytime) > 120
            || !mb_check_encoding($siteSource, 'UTF-8')
            || !mb_check_encoding($siteHappytime, 'UTF-8')) {
            throw new RuntimeException('checkout_site_property_policy_unverified');
        }
    }

    /** @return array<string,string> */
    public function additions(int $userId, array $business): array
    {
        if ($userId < 1 || !is_int($business['rid'] ?? null)
            || $business['rid'] < 1
            || !is_string($business['dispatch'] ?? null)) {
            throw new RuntimeException('checkout_site_property_input_unavailable');
        }
        return [
            'source' => $this->siteSource,
            'happytime' => $this->siteHappytime,
            'IGNORE_RESTIS' => 'Y',
        ];
    }
}
