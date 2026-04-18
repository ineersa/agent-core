<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Contract\Extension\CommandHandlerInterface;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use PHPUnit\Framework\TestCase;

final class CommandRouterContractTest extends TestCase
{
    public function testRoutesExtensionCommandWhenHandlerAllowsCancelSafe(): void
    {
        $router = new CommandRouter(new CommandHandlerRegistry([
            new class implements CommandHandlerInterface {
                public function supports(string $kind): bool
                {
                    return 'ext:compaction:compact' === $kind;
                }

                public function supportsCancelSafe(string $kind): bool
                {
                    return 'ext:compaction:compact' === $kind;
                }

                public function map(string $runId, string $kind, array $payload, array $options = []): array
                {
                    return [];
                }
            },
        ]));

        $command = $this->extensionCommand(kind: 'ext:compaction:compact', options: ['cancel_safe' => true]);

        $routed = $router->route($command);

        self::assertSame('extension', $routed->status);
        self::assertNull($routed->reason);
    }

    public function testRejectsExtensionCommandWhenCancelSafeCapabilityIsMissing(): void
    {
        $router = new CommandRouter(new CommandHandlerRegistry([
            new class implements CommandHandlerInterface {
                public function supports(string $kind): bool
                {
                    return 'ext:compaction:compact' === $kind;
                }

                public function supportsCancelSafe(string $kind): bool
                {
                    return false;
                }

                public function map(string $runId, string $kind, array $payload, array $options = []): array
                {
                    return [];
                }
            },
        ]));

        $command = $this->extensionCommand(kind: 'ext:compaction:compact', options: ['cancel_safe' => true]);

        $routed = $router->route($command);

        self::assertSame('rejected', $routed->status);
        self::assertStringContainsString('does not allow cancel_safe=true', (string) $routed->reason);
    }

    public function testRejectsUnknownExtensionCommandDeterministically(): void
    {
        $router = new CommandRouter(new CommandHandlerRegistry([]));

        $command = $this->extensionCommand(kind: 'ext:compaction:compact', options: []);

        $routed = $router->route($command);

        self::assertSame('rejected', $routed->status);
        self::assertStringContainsString('No extension command handler registered', (string) $routed->reason);
    }

    public function testRejectsUnknownExtensionCommandOptionKeys(): void
    {
        $router = new CommandRouter(new CommandHandlerRegistry([]));

        $command = $this->extensionCommand(kind: 'ext:compaction:compact', options: ['unknown' => true]);

        $routed = $router->route($command);

        self::assertSame('rejected', $routed->status);
        self::assertStringContainsString('Unknown extension command options', (string) $routed->reason);
    }

    public function testRejectsInvalidCancelSafeOptionType(): void
    {
        $router = new CommandRouter(new CommandHandlerRegistry([]));

        $command = $this->extensionCommand(kind: 'ext:compaction:compact', options: ['cancel_safe' => 'yes']);

        $routed = $router->route($command);

        self::assertSame('rejected', $routed->status);
        self::assertStringContainsString('must be boolean', (string) $routed->reason);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function extensionCommand(string $kind, array $options): ApplyCommand
    {
        return new ApplyCommand(
            runId: 'run-stage-01',
            turnNo: 1,
            stepId: 'step-stage-01',
            attempt: 1,
            idempotencyKey: hash('sha256', $kind.'|stage-01'),
            kind: $kind,
            payload: ['command' => 'compact'],
            options: $options,
        );
    }
}
