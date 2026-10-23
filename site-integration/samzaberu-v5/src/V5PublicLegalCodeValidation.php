<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5LegalConsentContract.php';

/**
 * Actual V5 public legal-code validation, not consent acceptance.
 *
 * Caller MUST supply the freshly read, authoritative site catalog; no ID,
 * document revision, agreement text or server state comes from the client.
 * This code never creates an account or writes an agreement receipt.
 */
final class V5PublicLegalCodeValidation
{
    /**
     * @return array{status:int,body:array<string,mixed>}
     */
    public static function evaluate(mixed $request, mixed $currentDocuments): array
    {
        // Wrong JSON shape is not evidence of an outdated document.
        if (!is_array($request) || array_is_list($request)
            || array_keys($request) !== ['agreements']
            || !is_array($request['agreements'])
            || !array_is_list($request['agreements'])
            || count($request['agreements']) < 2
            || count($request['agreements']) > 3) {
            return self::response(400, ['error' => 'invalid_request']);
        }

        $seen = [];
        foreach ($request['agreements'] as $code) {
            if (!is_string($code)
                || preg_match('/\A[A-Z][A-Z0-9_]{3,95}\z/D', $code) !== 1
                || isset($seen[$code])) {
                return self::response(400, ['error' => 'invalid_request']);
            }
            $seen[$code] = true;
        }

        $documents = V5LegalConsentContract::publicDocuments($currentDocuments);
        if ($documents === null) {
            return self::response(503, ['error' => 'temporarily_unavailable']);
        }
        $accepted = V5LegalConsentContract::acceptedForSignup(
            $request['agreements'], $documents
        );
        if ($accepted === null) {
            return self::response(409, ['error' => 'documents_changed']);
        }

        // Positive result is a read-only precheck, not legal acceptance and
        // never proof that signup would succeed or that consent was recorded.
        return self::response(200, [
            'valid' => true,
            'agreements' => $accepted,
        ]);
    }

    /** @param array<string,mixed> $body
     * @return array{status:int,body:array<string,mixed>}
     */
    private static function response(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
