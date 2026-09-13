<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;

/**
 * @covers \Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO
 */
final class CodeModeArgumentsDTOTest extends TestCase
{
    public function testDefaultsAreWithinApprovedBounds(): void
    {
        $dto = new CodeModeArgumentsDTO(script: 'return 1;');

        $this->assertSame(CodeModeArgumentsDTO::DEFAULT_TIMEOUT_SECONDS, $dto->timeout_seconds);
        $this->assertSame(CodeModeArgumentsDTO::DEFAULT_MEMORY_LIMIT_MB, $dto->memory_limit_mb);
        $this->assertSame([], $this->validate($dto));
    }

    public function testCustomValuesInsideBoundsPassValidation(): void
    {
        $dto = new CodeModeArgumentsDTO(
            script: 'return 1;',
            timeout_seconds: 90,
            memory_limit_mb: 512,
        );

        $this->assertSame([], $this->validate($dto));
    }

    /**
     * @return iterable<string, array{0: CodeModeArgumentsDTO, 1: string}>
     */
    public static function invalidDtoProvider(): iterable
    {
        yield 'blank script' => [
            new CodeModeArgumentsDTO(script: '   '),
            '"script" argument is required',
        ];
        yield 'timeout below minimum' => [
            new CodeModeArgumentsDTO(script: 'return 1;', timeout_seconds: 0),
            '"timeout_seconds" argument must be an integer between 1 and 300',
        ];
        yield 'timeout above maximum' => [
            new CodeModeArgumentsDTO(script: 'return 1;', timeout_seconds: 301),
            '"timeout_seconds" argument must be an integer between 1 and 300',
        ];
        yield 'memory below minimum' => [
            new CodeModeArgumentsDTO(script: 'return 1;', memory_limit_mb: 0),
            '"memory_limit_mb" argument must be an integer between 1 and 1024',
        ];
        yield 'memory above maximum' => [
            new CodeModeArgumentsDTO(script: 'return 1;', memory_limit_mb: 1025),
            '"memory_limit_mb" argument must be an integer between 1 and 1024',
        ];
    }

    #[DataProvider('invalidDtoProvider')]
    public function testOutOfRangeAndBlankValuesFailValidation(CodeModeArgumentsDTO $dto, string $expectedMessageFragment): void
    {
        $messages = $this->validate($dto);

        $this->assertNotSame([], $messages);
        $this->assertTrue(
            array_any($messages, static fn (string $message): bool => str_contains($message, $expectedMessageFragment)),
            'Expected a violation containing '.$expectedMessageFragment.', got: '.implode(' | ', $messages),
        );
    }

    /**
     * @return list<string>
     */
    private function validate(object $dto): array
    {
        $violations = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
            ->validate($dto);

        return array_values(array_map(
            static fn (ConstraintViolationInterface $violation): string => (string) $violation->getMessage(),
            iterator_to_array($violations),
        ));
    }
}
