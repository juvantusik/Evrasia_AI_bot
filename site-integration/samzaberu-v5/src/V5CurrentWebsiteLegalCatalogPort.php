<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5AuthHttpHandler.php';
require_once __DIR__ . '/V5BitrixPublicLegalCatalog.php';

use RuntimeException;

/**
 * Reuses the PUBLIC, already-deployed native Bitrix legal document catalog.
 * Does not accept caller-supplied consent revision or hardcode legal HTML.
 */
final readonly class V5CurrentWebsiteLegalCatalogPort implements V5PublicLegalConsentsPort
{
    public function __construct(private string $settingsPath)
    {
        if (!str_ends_with($settingsPath, '/bitrix/.settings.php')
            || !is_file($settingsPath) || is_link($settingsPath)) {
            throw new RuntimeException('v5_signup_legal_source_unavailable');
        }
    }

    public function currentPublicDocuments(): array
    {
        $documents = V5BitrixPublicLegalCatalog::read($this->settingsPath);
        if ($documents === null) {
            throw new RuntimeException('v5_signup_legal_catalog_unavailable');
        }
        return $documents;
    }
}
