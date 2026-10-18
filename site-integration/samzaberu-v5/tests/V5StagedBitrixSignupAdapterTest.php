<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5StagedBitrixSignupAdapter.php';

use Evrasia\Samzaberu\V5\V5StagedBitrixSignupAdapter;

// Reflection instantiation avoids construction of native writer or a
// fabricated consent plan. This test never initializes Bitrix or database.
$class = new ReflectionClass(V5StagedBitrixSignupAdapter::class);
$adapter = $class->newInstanceWithoutConstructor();
if ($adapter->safeToCreateWithoutExternalEffects()) {
    throw new RuntimeException('FAIL: unapproved native signup admitted');
}
foreach (['createUserInsideOwnedTransaction', 'persistNativeConsentsFor'] as $method) {
    try {
        $method === 'createUserInsideOwnedTransaction'
            ? $adapter->$method()
            : $adapter->$method(123);
        throw new RuntimeException('FAIL: forbidden native method returned');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'v5_signup_cuser_side_effects_not_attested') {
            throw $e;
        }
    }
}
if ($adapter->verifyUncommitted(123, [11,12])
    || $adapter->transactionContinuityBeforeRollback()
    || $adapter->transactionContinuityBeforeCommit()
    || $adapter->verifyCommitted(123, [11,12])) {
    throw new RuntimeException('FAIL: unverified native evidence accepted');
}
echo "PASS: V5 native signup adapter remains disabled (7 negative guards)\n";
echo "BITRIX_BOOTSTRAP=NO\nDATABASE_CONNECTION=NO\nPRODUCTION_WRITE=NO\n";
