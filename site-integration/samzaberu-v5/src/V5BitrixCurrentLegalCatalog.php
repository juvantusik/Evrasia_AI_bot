<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5AuthHttpHandler.php';

use RuntimeException;
use Throwable;

/**
 * Native Bitrix read-only candidate for the anonymous V5 legal catalog.
 *
 * No Bitrix bootstrap, no SQL write/transaction, no USER_ID, no JWT.
 * Requires an already initialized, current native Bitrix mysqli connection.
 * This PHP source is NOT installed on the site. Installed schema/URL values
 * require read-only verification before any live integration.
 */
final readonly class V5BitrixCurrentLegalCatalog implements V5PublicLegalConsentsPort
{
    // Three approved site legal families; no actual revision code is pinned.
    // More than one active revision per family must FAIL CLOSED.
    private const SQL = "SELECT CODE, NAME, ACTIVE, USE_URL, URL, "
        . "AGREEMENT_TEXT, IS_AGREEMENT_TEXT_HTML FROM b_consent_agreement "
        . "WHERE ACTIVE = 'Y' AND "
        . "CODE REGEXP '^EVRASIA_(OFFER|PD|MARKETING)_[0-9]{8}$' LIMIT 8";

    public function __construct(private object $native)
    {
        if (!is_a($native, 'Bitrix\\Main\\DB\\MysqliConnection')
            || !method_exists($native, 'query')) {
            throw new RuntimeException('v5_native_legal_connection_unavailable');
        }
    }

    /** @return list<array<string,mixed>> */
    public function currentPublicDocuments(): array
    {
        if (!class_exists('Bitrix\\Main\\Application')
            || !method_exists('Bitrix\\Main\\Application', 'getConnection')) {
            throw new RuntimeException('v5_native_legal_bootstrap_missing');
        }

        try {
            if (\Bitrix\Main\Application::getConnection() !== $this->native) {
                throw new RuntimeException('v5_native_legal_connection_mismatch');
            }
            $result = $this->native->query(self::SQL);
            if (!is_object($result) || !method_exists($result, 'fetch')) {
                throw new RuntimeException('v5_native_legal_reader_invalid');
            }

            $rows = [];
            for ($i = 0; $i < 9; $i++) {
                $row = $result->fetch();
                if ($row === false || $row === null) break;
                if (!is_array($row) || $i >= 7) {
                    throw new RuntimeException('v5_native_legal_rows_unbounded');
                }
                $rows[] = $row;
            }
            return V5BitrixLegalAgreementRows::mapCurrent($rows);
        } catch (Throwable) {
            // Do not expose native schema/DB errors to anonymous callers.
            throw new RuntimeException('v5_native_legal_catalog_unavailable');
        }
    }
}

/**
 * Pure mapping from the pinned native b_consent_agreement column projection.
 * DATE and active status are derived from the current live DB read, never from
 * a client-controlled current-version code or a cached user consent state.
 */
final class V5BitrixLegalAgreementRows
{
    private const PATH = [
        'offer' => '/legal/public-offer/',
        'personal_data' => '/legal/personal-data-consent/',
        'marketing' => '/legal/marketing-consent/',
    ];

    /** @return list<array<string,mixed>> */
    public static function mapCurrent(array $rows): array
    {
        if (!array_is_list($rows) || count($rows) < 2 || count($rows) > 3) {
            throw new RuntimeException('v5_native_legal_current_set_unverified');
        }

        $documents = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)
                || !array_key_exists('CODE', $row)
                || !array_key_exists('NAME', $row)
                || !array_key_exists('ACTIVE', $row)
                || !array_key_exists('USE_URL', $row)
                || !array_key_exists('URL', $row)
                || !array_key_exists('AGREEMENT_TEXT', $row)
                || !array_key_exists('IS_AGREEMENT_TEXT_HTML', $row)) {
                throw new RuntimeException('v5_native_legal_row_shape_unverified');
            }

            $code = $row['CODE'];
            if (!is_string($code)
                || preg_match('/\AEVRASIA_(OFFER|PD|MARKETING)_[0-9]{8}\z/D', $code, $m) !== 1
                || $row['ACTIVE'] !== 'Y') {
                throw new RuntimeException('v5_native_legal_revision_unverified');
            }

            $type = match ($m[1]) {
                'OFFER' => 'offer',
                'PD' => 'personal_data',
                'MARKETING' => 'marketing',
            };
            if (isset($documents[$type])) {
                throw new RuntimeException('v5_native_legal_multiple_active_revisions');
            }

            $expectedPath = self::PATH[$type];
            $rawUrl = $row['URL'];
            $url = 'https://evrasia.spb.ru' . $expectedPath;
            if ($rawUrl !== null && $rawUrl !== '') {
                if (!is_string($rawUrl)) {
                    throw new RuntimeException('v5_native_legal_url_invalid');
                }
                if ($rawUrl === $expectedPath) {
                    $url = 'https://evrasia.spb.ru' . $rawUrl;
                } elseif ($rawUrl === 'https://evrasia.spb.ru' . $expectedPath
                    || $rawUrl === 'https://evrasia.rest' . $expectedPath) {
                    $url = $rawUrl;
                } else {
                    throw new RuntimeException('v5_native_legal_url_outside_canonical');
                }
            }
            if (!is_string($row['NAME'])
                || !in_array($row['USE_URL'], ['Y','N'], true)
                || !in_array($row['IS_AGREEMENT_TEXT_HTML'], ['Y','N'], true)) {
                throw new RuntimeException('v5_native_legal_definition_unverified');
            }

            $format = 'url';
            $content = null;
            if ($row['USE_URL'] === 'N') {
                $format = $row['IS_AGREEMENT_TEXT_HTML'] === 'Y' ? 'html' : 'text';
                $content = $row['AGREEMENT_TEXT'];
            } elseif ($rawUrl === null || $rawUrl === '') {
                // USE_URL=Y without a live legal link is an inconsistent row.
                throw new RuntimeException('v5_native_legal_link_missing');
            }

            $documents[$type] = [
                'code' => $code,
                'type' => $type,
                'title' => $row['NAME'],
                'required' => $type !== 'marketing',
                'format' => $format,
                'content' => $content,
                'url' => $url,
            ];
        }

        if (!isset($documents['offer'], $documents['personal_data'])) {
            throw new RuntimeException('v5_native_legal_mandatory_documents_missing');
        }

        $ordered = [];
        foreach (['offer','personal_data','marketing'] as $type) {
            if (isset($documents[$type])) $ordered[] = $documents[$type];
        }
        if (V5LegalConsentContract::publicDocuments($ordered) === null) {
            throw new RuntimeException('v5_native_legal_contract_unverified');
        }
        return $ordered;
    }
}
