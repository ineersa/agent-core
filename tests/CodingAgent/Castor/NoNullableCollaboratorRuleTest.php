<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\Tools\PHPStan\Rules\NoNullableCollaboratorRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Forbidden: nullable class/interface constructor parameters with a null default,
 * promoted or not, written as ?T, T|U|null or (A&B)|null. Allowed: required or
 * non-null defaults, nullable without a default, scalars, arrays, callables,
 * Closure, Throwable and subtypes, enums, DateTimeInterface implementations,
 * non-constructor methods, DTO namespaces, DTO short-name suffixes, and files
 * outside the configured source directory.
 *
 * @extends RuleTestCase<NoNullableCollaboratorRule>
 */
final class NoNullableCollaboratorRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__.'/../Fixtures/PHPStan/NullableCollaborator';
    private const string CLASS_NAME = 'Ineersa\CodingAgent\Tests\Fixtures\PHPStan\NullableCollaborator\Source\NullableCollaboratorFixture';

    public function testReportsNullableCollaboratorsWithNullDefault(): void
    {
        $this->analyse([self::FIXTURES.'/Source/NullableCollaboratorFixture.php'], [
            [$this->message('promotedNullable'), 16],
            [$this->message('plainNullable'), 17],
            [$this->message('unionWithNull'), 18],
            [$this->message('intersectionWithNull'), 19],
        ]);
    }

    public function testSkipsDtoClassesAndFilesOutsideSourceDirectory(): void
    {
        $this->analyse([
            self::FIXTURES.'/Source/DTO/NullableCollaboratorDTO.php',
            self::FIXTURES.'/Source/NullableCollaboratorSuffixDTO.php',
            self::FIXTURES.'/OutsideSourceFixture.php',
        ], []);
    }

    protected function getRule(): Rule
    {
        return new NoNullableCollaboratorRule((string) realpath(self::FIXTURES.'/Source'), $this->createReflectionProvider());
    }

    private function message(string $parameter): string
    {
        return \sprintf(
            'Constructor parameter $%s of %s is a nullable collaborator with a null default; inject a required service or a null object instead.',
            $parameter,
            self::CLASS_NAME,
        );
    }
}
