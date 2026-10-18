<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Independent CUser source identity check for a future staging-only adapter.
 * This checks code identity, NOT transaction safety of CUser::Add, ratings,
 * user hooks, filesystem/cache, or successful native commit/rollback.
 */
final class V5StagedCUserSourceGate
{
    public const EXPECTED_SHA256 =
        'bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99';
    private const SUFFIX = '/bitrix/modules/main/classes/general/user.php';

    /**
     * Fail closed if the loaded actual native class/method does not match
     * the reviewed Bitrix version and exact staging document root.
     */
    public static function assertReviewed(string $expectedStagingRoot): void
    {
        $host = gethostname();
        $first = is_string($host) ? strtolower(explode('.', $host)[0]) : '';
        $root = realpath($expectedStagingRoot);
        if (PHP_SAPI !== 'cli'
            || getenv('V5_STAGE_WRITE_ACK') !== 'ISOLATED_STAGING_ONLY'
            || $first === ''
            || in_array($first, ['evrasia', 'eur-bot-01', 'prod', 'production'], true)
            || $root === false || $root !== $expectedStagingRoot
            || $root === '/home/site_evrasia/web/evrasia.spb.ru/public_html'
            || !isset($_SERVER['DOCUMENT_ROOT'])
            || !is_string($_SERVER['DOCUMENT_ROOT'])
            || realpath($_SERVER['DOCUMENT_ROOT']) !== $root
            || !class_exists('CUser', false)) {
            throw new RuntimeException('v5_signup_cuser_scope_unverified');
        }

        $class = new ReflectionClass('CUser');
        if (!$class->hasMethod('Add')) {
            throw new RuntimeException('v5_signup_cuser_method_unverified');
        }
        $method = new ReflectionMethod('CUser', 'Add');
        $path = $method->getFileName();
        if (!$method->isPublic() || $method->isStatic()
            || !is_string($path) || !str_ends_with($path, self::SUFFIX)
            || !str_starts_with($path, $root . '/')
            || !is_file($path) || is_link($path)
            || realpath($path) !== $path
            || @hash_file('sha256', $path) !== self::EXPECTED_SHA256
            || $method->getNumberOfRequiredParameters() !== 1) {
            throw new RuntimeException('v5_signup_cuser_source_unverified');
        }
    }
}
