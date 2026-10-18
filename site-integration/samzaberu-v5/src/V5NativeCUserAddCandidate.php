<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5StagedCUserSourceGate.php';
require_once __DIR__ . '/V5SignupNativeConsentPlan.php';

use RuntimeException;

/**
 * Implementation candidate for the Bitrix CUser::Add half of V5 signup.
 *
 * Not wired to V5StagedBitrixSignupAdapter or HTTP. Source pinning does NOT
 * establish atomicity of hooks, ratings, cache, file or event side effects.
 * This method cannot run on production; it requires the same isolated CLI
 * gating already used by the source gate, and its caller must separately
 * attest exclusive native transaction ownership before invoking it.
 */
final class V5NativeCUserAddCandidate
{
    private bool $used = false;

    public function __construct(private string $stagingRoot) {}

    /**
     * @param array<string,mixed> $fields Validated server-created Bitrix fields.
     * @return int Native Bitrix user ID, never caller supplied.
     */
    public function add(array $fields): int
    {
        if ($this->used) {
            throw new RuntimeException('v5_signup_cuser_candidate_reused');
        }
        $this->used = true;

        // Validate all server-controlled arguments BEFORE native side effects.
        // Keep the candidate intentionally narrow, without file uploads,
        // groups, phone authentication, UF_* or arbitrary native CUser fields.
        $allowed = ['LOGIN', 'EMAIL', 'PASSWORD', 'CONFIRM_PASSWORD', 'ACTIVE'];
        if (array_keys($fields) !== $allowed) {
            throw new RuntimeException('v5_signup_cuser_candidate_fields_invalid');
        }
        foreach (['LOGIN', 'EMAIL', 'PASSWORD', 'CONFIRM_PASSWORD'] as $key) {
            if (!is_string($fields[$key]) || $fields[$key] === ''
                || strlen($fields[$key]) > 255 || str_contains($fields[$key], "\0")) {
                throw new RuntimeException('v5_signup_cuser_candidate_fields_invalid');
            }
        }
        if ($fields['ACTIVE'] !== 'Y'
            || $fields['PASSWORD'] !== $fields['CONFIRM_PASSWORD']
            || strlen($fields['PASSWORD']) < 12
            || filter_var($fields['EMAIL'], FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('v5_signup_cuser_candidate_fields_invalid');
        }

        V5StagedCUserSourceGate::assertReviewed($this->stagingRoot);

        // A native transaction permit alone is insufficient. This candidate
        // deliberately remains NON-EXECUTABLE until isolated side-effect
        // acceptance: no CUser object is constructed and no user is written.
        throw new RuntimeException('v5_signup_cuser_side_effects_not_attested');
    }
}
