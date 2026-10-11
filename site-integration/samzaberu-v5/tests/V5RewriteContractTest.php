<?php
declare(strict_types=1);

/**
 * Static contract test only. Actual .htaccess behavior needs Apache
 * staging tests, including nested routes and direct /router.php access.
 */
$source = file_get_contents(__DIR__ . '/../api/v5/.htaccess');
if (!is_string($source)) {
    throw new RuntimeException('V5 .htaccess unavailable');
}

$directives = [];
foreach (preg_split('/\r?\n/', $source) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    $directives[] = $line;
}
$expected = [
    '<IfModule mod_rewrite.c>',
    'RewriteEngine On',
    'RewriteBase /api/v5/',
    'RewriteRule ^router\.php$ - [L]',
    'RewriteRule ^ router.php [L,QSA]',
    '</IfModule>',
];
if ($directives !== $expected) {
    throw new RuntimeException('V5 rewrite contract changed');
}

foreach (['/api/v4/', 'index.php', 'http://', 'https://'] as $forbidden) {
    if (str_contains($source, 'RewriteBase ' . $forbidden)
        || str_contains($source, 'RewriteRule ^ ' . $forbidden)
    ) {
        throw new RuntimeException('Unexpected rewrite target');
    }
}

echo "PASS: V5 rewrite candidate static contract\n";
echo "APACHE_INTEGRATION_TESTED=NO\n";
echo "PRODUCTION_MODIFIED=NO\n";
