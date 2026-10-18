<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/**
 * V5-only, side-effect-free legal agreement contract.
 * Current codes/texts MUST come from a trusted, current site-side source.
 * Never use hardcoded agreement revisions or a client-supplied catalog.
 *
 * First registration accepts codes together with user creation. This class
 * is NOT an anonymous consent writer and never creates a Bitrix user/event.
 */
final class V5LegalConsentContract
{
    private const TYPES = ['offer', 'personal_data', 'marketing'];
    private const FIELDS = ['code','content','format','required','title','type','url'];

    /** @return list<array<string,mixed>>|null */
    public static function publicDocuments(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)
            || count($value) < 2 || count($value) > 3) {
            return null;
        }

        $seenCodes = [];
        $seenTypes = [];
        $totalContentBytes = 0;

        foreach ($value as $document) {
            if (!is_array($document) || array_is_list($document)) return null;
            $keys = array_keys($document);
            sort($keys, SORT_STRING);
            if ($keys !== self::FIELDS) return null;

            $code = $document['code'];
            $type = $document['type'];
            $title = $document['title'];
            $required = $document['required'];
            $format = $document['format'];
            $content = $document['content'];
            $url = $document['url'];

            if (!is_string($code) || strlen($code) > 96
                || preg_match('/\A[A-Z][A-Z0-9_]{3,95}\z/D', $code) !== 1
                || isset($seenCodes[$code])
                || !is_string($type) || !in_array($type, self::TYPES, true)
                || isset($seenTypes[$type])
                || !is_bool($required)
                || $required !== ($type !== 'marketing')
                || !is_string($title) || trim($title) === ''
                || strlen($title) > 240 || preg_match('//u', $title) !== 1
                || preg_match('/[\x00-\x1f\x7f]/', $title) === 1
                || !is_string($format) || !in_array($format, ['url','text','html'], true)
                || !self::publicLegalUrl($url)) {
                return null;
            }
            if ($format === 'url') {
                if ($content !== null) return null;
            } elseif (!is_string($content) || trim($content) === ''
                || strlen($content) > 65536 || preg_match('//u', $content) !== 1) {
                return null;
            }
            $totalContentBytes += is_string($content) ? strlen($content) : 0;
            if ($totalContentBytes > 98304) return null;
            $seenCodes[$code] = true;
            $seenTypes[$type] = true;
        }

        if (!isset($seenTypes['offer'], $seenTypes['personal_data'])) return null;
        return $value;
    }

    /**
     * Evaluate only AFTER refreshing the authoritative current document set
     * at signup submission time. Native registration + addByContext writes
     * still require a separate reviewed transaction-bound Bitrix adapter.
     *
     * @return list<string>|null Current accepted codes in catalog order.
     */
    public static function acceptedForSignup(mixed $submitted, array $freshCurrentDocuments): ?array
    {
        $documents = self::publicDocuments($freshCurrentDocuments);
        if ($documents === null || !is_array($submitted) || !array_is_list($submitted)
            || count($submitted) < 2 || count($submitted) > count($documents)) {
            return null;
        }

        $requested = [];
        foreach ($submitted as $code) {
            if (!is_string($code) || isset($requested[$code])) return null;
            $requested[$code] = true;
        }

        $result = [];
        foreach ($documents as $document) {
            $code = $document['code'];
            if ($document['required'] && !isset($requested[$code])) return null;
            if (isset($requested[$code])) {
                $result[] = $code;
                unset($requested[$code]);
            }
        }
        return $requested === [] ? $result : null;
    }

    private static function publicLegalUrl(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) > 512
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1) return false;
        $parts = parse_url($value);
        if (!is_array($parts)) return false;
        return ($parts['scheme'] ?? null) === 'https'
            && in_array($parts['host'] ?? null, ['evrasia.spb.ru','evrasia.rest'], true)
            && (!isset($parts['port']) || $parts['port'] === 443)
            && !isset($parts['user'], $parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && str_starts_with($parts['path'] ?? '', '/legal/');
    }
}
