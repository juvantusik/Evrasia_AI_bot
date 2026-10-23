<?php
declare(strict_types=1);

require_once __DIR__ . '/../migration/V5V4ReplacementGate.php';

use Evrasia\Samzaberu\V5\Migration\V5V4ReplacementGate;

$assertions = 0;
function replacementAssert(bool $ok, string $reason): void
{
    global $assertions;
    if (!$ok) {
        throw new RuntimeException("FAIL: {$reason}");
    }
    $assertions++;
}
function replacementReject(array $plan, string $reason): void
{
    try {
        V5V4ReplacementGate::inspect($plan);
    } catch (InvalidArgumentException) {
        replacementAssert(true, $reason);
        return;
    }
    replacementAssert(false, $reason);
}

$manifest = V5V4ReplacementGate::readJsonFile(
    __DIR__ . '/../migration/v4-replacement-plan.json'
);
$report = V5V4ReplacementGate::inspect($manifest);
replacementAssert(
    $report['status'] === 'V4_MUST_REMAIN_ACTIVE'
    && $report['source_registrations'] === 62
    && $report['source_inventory_rows'] === 0
    && $report['known_workstreams'] === 33
    && $report['v5_feature_targets'] === 5
    && $report['accepted_or_retired_workstreams'] === 0
    && $report['automatic_shutdown'] === false,
    'incomplete source-only parity explicitly retains V4 while building V5'
);
replacementAssert(
    in_array('V4_ROUTE_INVENTORY_INCOMPLETE', $report['reasons'], true)
    && in_array('V4_WORKSTREAMS_PENDING', $report['reasons'], true)
    && in_array('CONSUMER_INVENTORY_INCOMPLETE', $report['reasons'], true)
    && in_array('V5_AUTH_NOT_NATIVE_ACCEPTED', $report['reasons'], true)
    && in_array('CUTOVER_GATES_NOT_MET', $report['reasons'], true),
    'unproven endpoint coverage, mobile adoption, strong MFA and usage block shutdown'
);
replacementAssert(count($report['missing_gates']) === 10,
    'all ten V4 sunset obligations remain open');

$bad = $manifest;
$bad['target_public_base'] = 'https://evrasia.spb.ru/api/v5';
replacementReject($bad, 'public API remains evrasia.rest');

$bad = $manifest;
$bad['automatic_v4_shutdown'] = true;
replacementReject($bad, 'no configuration can automatically stop V4');

$bad = $manifest;
$bad['v4_transition_state'] = 'disabled';
replacementReject($bad, 'premature V4 disablement is forbidden');

$bad = $manifest;
$bad['shared_services_rule'] = 'forward_V5_JWT_to_V4_API';
replacementReject($bad, 'V5 cannot tunnel V4 HTTP bearer/session');

$bad = $manifest;
$bad['v4_evidence']['observed_router_sha256'] = str_repeat('0', 64);
replacementReject($bad, 'stale/invented V4 source hash cannot replace production observation');

$bad = $manifest;
$bad['v4_evidence']['route_inventory_complete'] = true;
replacementAssert(
    V5V4ReplacementGate::inspect($bad)['status'] === 'V4_MUST_REMAIN_ACTIVE',
    'claiming complete with no 62 route entries still denies cutover'
);

$bad = $manifest;
$bad['cutover_gates'] = array_fill_keys(
    array_keys($bad['cutover_gates']), true
);
replacementAssert(
    V5V4ReplacementGate::inspect($bad)['status'] === 'V4_MUST_REMAIN_ACTIVE',
    'all booleans changed to true cannot bypass missing routes/consumers/auth'
);

$bad = $manifest;
$bad['client_consumer_inventory']['complete'] = true;
replacementAssert(
    V5V4ReplacementGate::inspect($bad)['status'] === 'V4_MUST_REMAIN_ACTIVE',
    'unsubstantiated consumer inventory claim alone does not retire V4'
);

$bad = $manifest;
$bad['workstreams'][0]['v5_route'] = 'POST /v4/signin';
replacementReject($bad, 'V5 target must exist in current route map');

$bad = $manifest;
$bad['workstreams'][0]['v5_route'] = 'POST /signup';
replacementReject($bad, 'unimplemented native V5 signup route cannot be invented');

$bad = $manifest;
$bad['workstreams'][0]['phase'] = 'accepted';
replacementReject($bad, 'route cannot be accepted without parity acceptance');

$bad = $manifest;
$bad['workstreams'][0]['parity_accepted'] = true;
$bad['workstreams'][0]['phase'] = 'accepted';
replacementAssert(
    V5V4ReplacementGate::inspect($bad)['status'] === 'V4_MUST_REMAIN_ACTIVE',
    'one accepted route is not equivalent to 62 migrated V4 registrations'
);

$bad = $manifest;
$bad['workstreams'][1] = $bad['workstreams'][0];
replacementReject($bad, 'workstream identifiers must remain unique');

$bad = $manifest;
$bad['cutover_gates']['v5_native_auth_mfa_sms_epoch_accepted'] = 'true';
replacementReject($bad, 'a string must never be accepted as security evidence');

$bad = $manifest;
$bad['v5_evidence']['production_auth_enabled'] = true;
replacementReject($bad, 'production auth cannot be on without positive native acceptance');

$bad = $manifest;
$bad['v4_evidence']['exact_route_inventory'] = [
    ['registration_id' => 'x1', 'disposition'=>'v5_accepted', 'evidence_ref'=>''],
];
replacementReject($bad, 'one route needs recorded evidence and cannot be silently migrated');

echo "PASS: one-API V5 replacement plan fail-closed decision ({$assertions} assertions)\n";
echo "SOURCE_V4_ROUTER_EXECUTED=NO\n";
echo "V4_AUTOMATIC_SHUTDOWN=NO\n";
echo "NEW_ACCOUNTS_CREATED=NO\n";
echo "MOCK_API_CREATED=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\nDATABASE_CONNECTION=NO\nSMS_SENT=NO\n";
