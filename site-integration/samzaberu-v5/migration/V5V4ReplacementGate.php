<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5\Migration;

require_once __DIR__ . '/../src/V5RouteMap.php';

use Evrasia\Samzaberu\V5\V5RouteMap;
use InvalidArgumentException;
use JsonException;

/**
 * OFFLINE, read-only V4 retirement planning gate.
 *
 * V5 becomes the sole API ONLY after all consumers, route registrations,
 * native authorization gates and production behavior have been accepted.
 * This NEVER updates a web server, Bitrix, API V4, credentials or database.
 * A "candidate" is for an independent operator review, NOT permission
 * for an automated shutdown.
 */
final class V5V4ReplacementGate
{
    private const GATES = [
        'all_62_route_registrations_classified',
        'all_client_workstreams_accepted_or_retired',
        'all_mobile_and_partner_consumers_migrated',
        'background_webhooks_and_integrations_rehomed',
        'v5_native_auth_mfa_sms_epoch_accepted',
        'v5_personal_data_and_cart_order_parity_accepted',
        'external_evrasia_rest_v5_live_e2e_accepted',
        'v4_traffic_zero_in_agreed_observation_window',
        'rollback_and_emergency_reenable_rehearsed',
        'operator_cutover_approval_recorded',
    ];

    /**
     * @param array<string,mixed> $plan
     * @return array{
     *  status:string,source_registrations:int,source_inventory_rows:int,
     *  known_workstreams:int,v5_feature_targets:int,
     *  accepted_or_retired_workstreams:int,missing_gates:list<string>,
     *  reasons:list<string>,automatic_shutdown:bool
     * }
     */
    public static function inspect(array $plan): array
    {
        self::expect(($plan['schema_version'] ?? null) === 1, 'schema version');
        self::expect(
            ($plan['decision'] ?? null) === 'V5_SOLE_PUBLIC_API_AFTER_CONTROLLED_CUTOVER',
            'sole V5 target'
        );
        self::expect(
            ($plan['target_public_base'] ?? null) === 'https://evrasia.rest/api/v5',
            'canonical evrasia.rest public domain'
        );
        self::expect(
            ($plan['v4_transition_state'] ?? null) === 'active_until_consumers_migrated',
            'V4 must remain active during migration'
        );
        self::expect(
            ($plan['shared_services_rule'] ?? null)
                === 'reuse_Bitrix_business_services_without_V4_HTTP_or_JWT',
            'reuse business services, not legacy HTTP or V4 JWT'
        );
        self::expect(
            ($plan['automatic_v4_shutdown'] ?? null) === false,
            'automatic V4 deletion, redirects or shutdown forbidden'
        );

        $source = $plan['v4_evidence'] ?? null;
        self::expect(is_array($source), 'V4 source evidence');
        self::expect(
            ($source['observed_router_sha256'] ?? null)
                === '5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d',
            'observed installed source, not the earlier stale V4 SHA'
        );
        self::expect(
            ($source['observed_route_registrations'] ?? null) === 62
                && ($source['observed_controllers'] ?? null) === 11
                && ($source['observed_source_only'] ?? null) === true,
            'observed lexical V4 route count and controller evidence'
        );
        $complete = $source['route_inventory_complete'] ?? null;
        self::expect(is_bool($complete), 'route inventory complete type');
        $inventory = $source['exact_route_inventory'] ?? null;
        self::expect(is_array($inventory) && array_is_list($inventory),
            'exact V4 route inventory must be a list');
        self::expect(count($inventory) <= 62, 'route inventory overclaims observed size');

        // This inventory is deliberately EMPTY until actual 62 per-registration
        // identifiers can be independently reconciled to installed V4.
        // A group in the parity plan is not equivalent to one router registration.
        $ids = [];
        foreach ($inventory as $row) {
            self::expect(is_array($row)
                && is_string($row['registration_id'] ?? null)
                && preg_match('/\A[a-z0-9._:-]{3,120}\z/D', $row['registration_id']) === 1
                && in_array($row['disposition'] ?? null,
                    ['v5_accepted','internal_migrated','retired_with_evidence'], true)
                && is_string($row['evidence_ref'] ?? null)
                && strlen($row['evidence_ref']) >= 12,
                'per-registration migration and proof');
            self::expect(!isset($ids[$row['registration_id']]),
                'each original V4 registration must be counted once');
            $ids[$row['registration_id']] = true;
        }

        $v5 = $plan['v5_evidence'] ?? null;
        self::expect(is_array($v5), 'V5 readiness evidence');
        self::expect(
            ($v5['published_route_map_source'] ?? null)
                === 'site-integration/samzaberu-v5/src/V5RouteMap.php'
                && is_bool($v5['native_bitrix_auth_accepted'] ?? null)
                && is_bool($v5['production_auth_enabled'] ?? null),
            'V5 code and native auth states must be explicit'
        );
        self::expect(
            $v5['production_auth_enabled'] !== true
                || $v5['native_bitrix_auth_accepted'] === true,
            'production auth cannot be marked ON with unaccepted native auth'
        );

        $consumers = $plan['client_consumer_inventory'] ?? null;
        self::expect(is_array($consumers)
            && is_bool($consumers['complete'] ?? null)
            && is_array($consumers['verified_consumers'] ?? null)
            && array_is_list($consumers['verified_consumers']),
            'mobile, partner and callback consumer inventory'
        );

        $groups = $plan['workstreams'] ?? null;
        self::expect(is_array($groups) && array_is_list($groups)
                && count($groups) >= 1 && count($groups) <= 100,
            'tracked V4 migration workstreams');
        $seen = [];
        $featureTargets = 0;
        $doneGroups = 0;
        foreach ($groups as $row) {
            self::expect(is_array($row), 'workstream structure');
            $id = $row['id'] ?? null;
            $legacy = $row['v4_route'] ?? null;
            $target = $row['v5_route'] ?? null;
            $phase = $row['phase'] ?? null;
            self::expect(is_string($id)
                    && preg_match('/\A[a-z][a-z0-9-]{2,80}\z/D', $id) === 1
                    && !isset($seen[$id]), 'unique workstream id');
            $seen[$id] = true;
            self::expect(
                is_string($legacy) && strlen($legacy) <= 160
                    && preg_match('/\A(?:GET|POST|PUT|PATCH|DELETE) \//D', $legacy) === 1,
                'workstream V4 route must be explicit'
            );
            self::expect(
                $target === null || (
                    is_string($target) && array_key_exists($target, V5RouteMap::ROUTES)
                ),
                'V5 target must really be registered; no invented route or V4 alias'
            );
            self::expect(
                in_array($phase, ['not_implemented','feature_only','origin_verified',
                    'accepted','retired_with_evidence'], true)
                    && is_bool($row['parity_accepted'] ?? null),
                'explicit per-workstream acceptance'
            );
            if ($target === null) {
                self::expect(!in_array($phase, ['feature_only','origin_verified','accepted'], true),
                    'cannot claim working V5 endpoint for an unmapped V4 route');
            } else {
                $featureTargets++;
            }
            if ($phase === 'accepted') {
                self::expect($target !== null && $row['parity_accepted'] === true,
                    'accepted V5 endpoint must exist with parity evidence');
            }
            if ($phase === 'retired_with_evidence') {
                self::expect($target === null && $row['parity_accepted'] === true,
                    'retired internal V4 route needs documented acceptance');
            }
            if ($phase === 'accepted' || $phase === 'retired_with_evidence') {
                $doneGroups++;
            }
        }

        $gates = $plan['cutover_gates'] ?? null;
        self::expect(is_array($gates)
            && count($gates) === count(self::GATES), 'exact cutover gate set');
        $missing = [];
        foreach (self::GATES as $gate) {
            self::expect(array_key_exists($gate, $gates) && is_bool($gates[$gate]),
                'cutover gate type and presence');
            if (!$gates[$gate]) {
                $missing[] = $gate;
            }
        }

        $reasons = [];
        if (!$complete || count($inventory) !== 62) {
            $reasons[] = 'V4_ROUTE_INVENTORY_INCOMPLETE';
        }
        if ($doneGroups !== count($groups)) {
            $reasons[] = 'V4_WORKSTREAMS_PENDING';
        }
        if (!$consumers['complete']) {
            $reasons[] = 'CONSUMER_INVENTORY_INCOMPLETE';
        }
        if (!$v5['native_bitrix_auth_accepted']
            || !$v5['production_auth_enabled']) {
            $reasons[] = 'V5_AUTH_NOT_NATIVE_ACCEPTED';
        }
        if ($missing !== []) {
            $reasons[] = 'CUTOVER_GATES_NOT_MET';
        }

        $candidate = $reasons === [];
        return [
            'status' => $candidate ? 'MANUAL_CUTOVER_REVIEW_CANDIDATE' : 'V4_MUST_REMAIN_ACTIVE',
            'source_registrations' => 62,
            'source_inventory_rows' => count($inventory),
            'known_workstreams' => count($groups),
            'v5_feature_targets' => $featureTargets,
            'accepted_or_retired_workstreams' => $doneGroups,
            'missing_gates' => $missing,
            'reasons' => $reasons,
            'automatic_shutdown' => false,
        ];
    }

    public static function readJsonFile(string $file): array
    {
        self::expect(is_file($file) && is_readable($file) && !is_link($file)
            && filesize($file) <= 100000, 'readable checked small plan');
        try {
            $plan = json_decode(
                (string)file_get_contents($file), true, 24, JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new InvalidArgumentException('invalid plan JSON');
        }
        self::expect(is_array($plan) && !array_is_list($plan),
            'plan JSON must be an object');
        return $plan;
    }

    private static function expect(bool $condition, string $reason): void
    {
        if (!$condition) {
            throw new InvalidArgumentException('V4 replacement manifest invalid: '.$reason);
        }
    }
}

// Independent CI preflight. NEVER mutates an API, server or repo.
if (PHP_SAPI === 'cli' && isset($argv[0])
    && realpath((string)$argv[0]) === __FILE__) {
    try {
        if (count($argv) > 2) {
            throw new InvalidArgumentException('usage: php V5V4ReplacementGate.php [plan.json]');
        }
        $path = $argv[1] ?? __DIR__.'/v4-replacement-plan.json';
        $report = V5V4ReplacementGate::inspect(
            V5V4ReplacementGate::readJsonFile($path)
        );
        echo "=== V5 SOLE API REPLACEMENT GATE (READ ONLY) ===\n";
        echo "MANIFEST_VALID=YES\n";
        echo "TARGET_PUBLIC_API=https://evrasia.rest/api/v5\n";
        echo "V4_SOURCE_REGISTRATIONS=".$report['source_registrations']."\n";
        echo "RECONCILED_REGISTRATIONS=".$report['source_inventory_rows']."\n";
        echo "TRACKED_WORKSTREAMS=".$report['known_workstreams']."\n";
        echo "V5_REGISTERED_FEATURE_TARGETS=".$report['v5_feature_targets']."\n";
        echo "ACCEPTED_OR_RETIRED_WORKSTREAMS=".$report['accepted_or_retired_workstreams']."\n";
        echo "CUTOVER_GATE_FAILURES=".count($report['missing_gates'])."\n";
        echo "CURRENT_STATUS=".$report['status']."\n";
        echo "REASONS=".implode(',', $report['reasons'])."\n";
        echo "V4_AUTOMATIC_SHUTDOWN=NO\n";
        echo "PRODUCTION_CODE_WRITE=NO\nDATABASE_CONNECTION=NO\nSMS_SENT=NO\n";
        echo "FINAL_STATUS=PASS\n";
    } catch (InvalidArgumentException $e) {
        echo "FINAL_STATUS=FAIL\n";
        echo "ERROR=INVALID_MIGRATION_MANIFEST\n";
        exit(2);
    }
}
