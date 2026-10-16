<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolInvocationOwnerReferenceTest extends TestCase
{
    #[Test]
    public function constructorPreservesIdentityAndNullablePresentationFields(): void
    {
        $full = new ToolInvocationOwnerReference('bash', 'record-1', 4242, '/tmp/bash.log');
        $this->assertSame('bash', $full->kind);
        $this->assertSame('record-1', $full->recordId);
        $this->assertSame(4242, $full->presentationPid);
        $this->assertSame('/tmp/bash.log', $full->presentationLogPath);

        $identityOnly = new ToolInvocationOwnerReference('bash', 'record-2');
        $this->assertNull($identityOnly->presentationPid);
        $this->assertNull($identityOnly->presentationLogPath);
    }

    #[Test]
    #[DataProvider('invalidConstructorCases')]
    public function constructorRejectsEmptyIdentityAndNonPositivePid(
        string $kind,
        string $recordId,
        ?int $presentationPid,
        string $expectedMessage,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        new ToolInvocationOwnerReference($kind, $recordId, $presentationPid);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: ?int, 3: string}>
     */
    public static function invalidConstructorCases(): iterable
    {
        yield 'empty kind' => ['', 'record-1', null, 'Tool invocation owner kind is required.'];
        yield 'empty record id' => ['bash', '', null, 'Tool invocation owner record ID is required.'];
        yield 'zero pid' => ['bash', 'record-1', 0, 'Tool invocation presentation PID must be positive when present.'];
        yield 'negative pid' => ['bash', 'record-1', -7, 'Tool invocation presentation PID must be positive when present.'];
    }
}
