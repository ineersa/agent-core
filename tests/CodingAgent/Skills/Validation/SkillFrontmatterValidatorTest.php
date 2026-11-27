<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Skills\Validation;

use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Skills\Exception\SkillFrontmatterValidationException;
use Ineersa\CodingAgent\Skills\Validation\SkillFrontmatterValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class SkillFrontmatterValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function acceptedFlagProvider(): iterable
    {
        yield 'boolean true' => ['true', true];
        yield 'boolean false' => ['false', false];
        yield 'quoted false' => ['"false"', false];
        yield 'quoted true' => ['"true"', true];
        yield 'yes' => ['yes', true];
        yield 'no' => ['no', false];
        yield 'on uppercase' => ['ON', true];
        yield 'off' => ['off', false];
        yield 'quoted one' => ['"1"', true];
        yield 'quoted zero' => ['"0"', false];
        yield 'integer one' => ['1', true];
        yield 'integer zero' => ['0', false];
        yield 'blank value' => ['', false];
    }

    #[DataProvider('acceptedFlagProvider')]
    public function testAcceptsBooleanSpellingsForDisableModelInvocation(string $yaml, bool $expected): void
    {
        $dto = $this->validator()->validate($this->parse("disable-model-invocation: {$yaml}"));

        $this->assertSame($expected, $dto->disableModelInvocation);
    }

    public function testMissingFlagDefaultsToFalseAndUnknownKeysAreAllowed(): void
    {
        $dto = $this->validator()->validate($this->parse("name: castor\ndescription: Runs tasks\nlicense: MIT\nallowed-tools: [read]"));

        $this->assertSame('castor', $dto->name);
        $this->assertSame('Runs tasks', $dto->description);
        $this->assertFalse($dto->disableModelInvocation);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'unknown flag word' => ['disable-model-invocation: maybe', 'disable-model-invocation', '"disable-model-invocation" must be true or false.'];
        yield 'integer two flag' => ['disable-model-invocation: 2', 'disable-model-invocation', '"disable-model-invocation" must be true or false.'];
        yield 'list flag' => ['disable-model-invocation: [true]', 'disable-model-invocation', '"disable-model-invocation" must be true or false.'];
        yield 'integer name' => ['name: 123', 'name', '"name" must be a string.'];
        yield 'list name' => ['name: [a]', 'name', '"name" must be a string.'];
        yield 'integer description' => ['description: 42', 'description', '"description" must be a string.'];
        yield 'blank name' => ["name: '  '", 'name', 'Omit to use the skill directory name, or enter a non-empty name.'];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidValuesWithFieldAndReadableMessage(string $yaml, string $field, string $message): void
    {
        try {
            $this->validator()->validate($this->parse($yaml));
            $this->fail('Expected SkillFrontmatterValidationException.');
        } catch (SkillFrontmatterValidationException $e) {
            $this->assertSame($field, $e->field);
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function validator(): SkillFrontmatterValidator
    {
        return new SkillFrontmatterValidator(...AttributeSerializerValidatorTestFactory::create());
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parse(string $yaml): array
    {
        $parsed = Yaml::parse($yaml);
        $this->assertIsArray($parsed);

        return $parsed;
    }
}
