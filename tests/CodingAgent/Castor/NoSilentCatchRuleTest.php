<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\Tools\PHPStan\Rules\NoSilentCatchRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Allowed: rethrow, new throw, throw expression, PSR logger call (also nullsafe),
 * a log call in only one branch (the rule is presence-based, not path-sensitive),
 * an object result built from the caught exception, the exception passed to a
 * private log helper or failure callback, a stored result or UI notice built
 * from $e->getMessage(), and a nested catch whose outer body logs. Forbidden:
 * empty catch, bare null/false/[]/string returns, bare return, continue, an
 * object result not built from the failure, a call that does not receive the
 * exception, a discarded $e->getMessage() (receiver only, not an argument), a
 * throw or capture only inside a closure, and a silent inner or sibling catch.
 *
 * @extends RuleTestCase<NoSilentCatchRule>
 */
final class NoSilentCatchRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'Catch block must rethrow, log through Psr\Log\LoggerInterface, pass the caught exception to a call, or return a typed result built from it.';

    public function testReportsOnlyUnhandledCatches(): void
    {
        $this->analyse([__DIR__.'/../Fixtures/PHPStan/SilentCatch/SilentCatchFixture.php'], [
            [self::MESSAGE, 138], // empty
            [self::MESSAGE, 146], // bare null
            [self::MESSAGE, 157], // bare false
            [self::MESSAGE, 169], // empty array
            [self::MESSAGE, 180], // magic string
            [self::MESSAGE, 191], // object not built from the failure
            [self::MESSAGE, 200], // call without the exception
            [self::MESSAGE, 209], // discarded $e->getMessage()
            [self::MESSAGE, 218], // bare return
            [self::MESSAGE, 228], // continue
            [self::MESSAGE, 238], // throw only inside a closure
            [self::MESSAGE, 252], // silent inner catch
            [self::MESSAGE, 264], // silent sibling catch
        ]);
    }

    protected function getRule(): Rule
    {
        return new NoSilentCatchRule();
    }
}
