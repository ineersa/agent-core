<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Run;

use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrentOperationDTOTest extends TestCase
{
    public static function invalidIdentityCases(): iterable
    {
        yield 'negative turn' => [-1, 'step', 1, 'key'];
        yield 'blank step' => [0, ' ', 1, 'key'];
        yield 'zero attempt' => [0, 'step', 0, 'key'];
        yield 'blank key' => [0, 'step', 1, ' '];
    }

    #[DataProvider('invalidIdentityCases')]
    public function testRejectsInvalidIdentity(int $turnNo, string $stepId, int $attempt, string $idempotencyKey): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CurrentOperationDTO($turnNo, $stepId, $attempt, $idempotencyKey);
    }

    public static function nonMatchingMessageCases(): iterable
    {
        yield 'turn' => [2, 'step', 1, 'key'];
        yield 'step' => [1, 'other-step', 1, 'key'];
        yield 'attempt' => [1, 'step', 2, 'key'];
        yield 'key' => [1, 'step', 1, 'other-key'];
    }

    #[DataProvider('nonMatchingMessageCases')]
    public function testMatchesMessageRequiresTheFullEnvelope(int $turnNo, string $stepId, int $attempt, string $idempotencyKey): void
    {
        $operation = new CurrentOperationDTO(1, 'step', 1, 'key');
        $message = new LlmStepResult('run', $turnNo, $stepId, $attempt, $idempotencyKey, null, [], null, null);

        $this->assertFalse($operation->matchesMessage($message));
    }

    public function testMatchesMessageAcceptsTheExactEnvelope(): void
    {
        $operation = new CurrentOperationDTO(1, 'step', 1, 'key');
        $message = new LlmStepResult('run', 1, 'step', 1, 'key', null, [], null, null);

        $this->assertTrue($operation->matchesMessage($message));
    }
}
