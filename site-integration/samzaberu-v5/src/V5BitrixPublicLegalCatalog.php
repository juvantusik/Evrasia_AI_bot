<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5LegalConsentContract.php';

/**
 * Read-only native Bitrix agreement catalog, no Bitrix bootstrap or user session.
 * Agreement IDs and paths are established production mappings, not document revisions.
 */
final class V5BitrixPublicLegalCatalog
{
    public static function read(string $settingsPath): ?array
    {
        try {
            $settings = require $settingsPath;
            $c = $settings['connections']['value']['default'] ?? null;
            if (!is_array($c)) return null;
            $db = (string)($c['database'] ?? '');
            $login = (string)($c['login'] ?? '');
            $password = (string)($c['password'] ?? '');
            $host = (string)($c['host'] ?? '');
            if ($db === '' || $host === '' || $login === '') return null;
            $pdo = new \PDO(
                'mysql:host=' . $host . ';dbname=' . $db . ';charset=utf8mb4',
                $login,
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]
            );
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $pdo->beginTransaction();
            $stmt = $pdo->query(
                'SELECT ID,CODE,ACTIVE,NAME,AGREEMENT_TEXT,USE_URL,URL,IS_AGREEMENT_TEXT_HTML
                 FROM b_consent_agreement WHERE ID IN (1,2,3) ORDER BY ID'
            );
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $pdo->rollBack();
            if (count($rows) !== 3) return null;
            $paths = [
                1 => '/legal/public-offer/',
                2 => '/legal/personal-data-consent/',
                3 => '/legal/marketing-consent/',
            ];
            $types = [1 => 'offer', 2 => 'personal_data', 3 => 'marketing'];
            $documents = [];
            foreach ($rows as $row) {
                $id = (int)$row['ID'];
                if (!isset($types[$id]) || $row['ACTIVE'] !== 'Y') return null;
                $format = $row['IS_AGREEMENT_TEXT_HTML'] === 'Y' ? 'html' : 'text';
                $content = (string)($row['AGREEMENT_TEXT'] ?? '');
                // The native agreement's content may be empty when it points to a legal URL.
                if (trim($content) === '') {
                    $format = 'url';
                    $content = null;
                }
                $documents[] = [
                    'code' => (string)$row['CODE'],
                    'type' => $types[$id],
                    'title' => (string)$row['NAME'],
                    'required' => $id !== 3,
                    'format' => $format,
                    'content' => $content,
                    'url' => 'https://evrasia.spb.ru' . $paths[$id],
                ];
            }
            return V5LegalConsentContract::publicDocuments($documents);
        } catch (\Throwable) {
            return null;
        }
    }
}
