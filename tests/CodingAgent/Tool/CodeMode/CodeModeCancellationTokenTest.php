<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Tool\DTO\DeferredToolCompletionMetadataDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeCancellationToken;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/** @covers \Ineersa\CodingAgent\Tool\CodeMode\CodeModeCancellationToken */
final class CodeModeCancellationTokenTest extends TestCase
{
    #[TestWith([null])]
    #[TestWith([7200])]
    public function testAcceptanceReplacesLauncherBudgetButKeepsStop(?int $explicitTimeout): void
    {
        $parent = $this->createStub(CancellationTokenInterface::class);
        $parent->method('isCancellationRequested')->willReturnOnConsecutiveCalls(false, true);
        $reference = new ToolPayloadReferenceDTO('correlation', 'run', 1, 'step', 1, 'key', 'call', 0, 'code_mode', str_repeat('a', 64), 1);
        $acceptedAt = new \DateTimeImmutable('@1');
        $repository = $this->createStub(DeferredToolCompletionRepositoryInterface::class);
        $repository->method('findMetadataByDeferredId')->willReturnOnConsecutiveCalls(
            new DeferredToolCompletionMetadataDTO('id', $reference, 'pending'),
            new DeferredToolCompletionMetadataDTO('id', $reference, 'pending', backgroundedAt: $acceptedAt),
            new DeferredToolCompletionMetadataDTO('id', $reference, 'pending', backgroundedAt: $acceptedAt, interruptionReason: 'stop'),
        );
        $token = new CodeModeCancellationToken($parent, $repository, 'id', $explicitTimeout);

        $this->assertFalse($token->isCancellationRequested());
        $this->assertSame(1, $token->wallLimit(1));
        $this->assertFalse($token->isCancellationRequested());
        $this->assertSame($explicitTimeout, $token->wallLimit(1));
        $this->assertTrue($token->isCancellationRequested());
    }

    public function testBlockedWorkChecksInvocationAgainBeforeItsEffect(): void
    {
        $parent = $this->createStub(CancellationTokenInterface::class);
        $reference = new ToolPayloadReferenceDTO('correlation', 'run', 1, 'step', 1, 'key', 'call', 0, 'code_mode', str_repeat('a', 64), 1);
        $repository = $this->createStub(DeferredToolCompletionRepositoryInterface::class);
        $repository->method('findMetadataByDeferredId')->willReturn(new DeferredToolCompletionMetadataDTO('id', $reference, 'pending'));
        $unavailable = false;
        $token = new CodeModeCancellationToken($parent, $repository, 'id', invocationUnavailable: static function () use (&$unavailable): bool { return $unavailable; });
        $work = new \Fiber(static function () use ($token): bool {
            \Fiber::suspend();

            return !$token->isCancellationRequested();
        });
        $this->assertFalse($token->isCancellationRequested());
        $work->start();
        $unavailable = true;
        $work->resume();
        $this->assertFalse($work->getReturn());
    }
}
