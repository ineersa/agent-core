<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Contract\Extension\CommandHandlerInterface;
use Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions;
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

                public function map(string $runId, string $kind, array $payload, CommandCancellationOptions $cancellation): array
                {
                    unset($runId, $kind, $payload, $cancellation);

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

                public function map(string $runId, string $kind, array $payload, CommandCancellationOptions $cancellation): array
                {
                    unset($runId, $kind, $payload, $cancellation);

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
        self::assertStringContainsString('Unknown command options', (string) $routed->reason);
    }

    public function testInvalidCancelSafeOptionTypeDefaultsToFalseForExtensionCommands(): void
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

                public function map(string $runId, string $kind, array $payload, CommandCancellationOptions $cancellation): array
                {
                    unset($runId, $kind, $payload, $cancellation);

                    return [];
                }
            },
        ]));

        $command = $this->extensionCommand(kind: 'ext:compaction:compact', options: ['cancel_safe' => 'yes']);

        $routed = $router->route($command);

        self::assertSame('extension', $routed->status);
        self::assertSame(['cancel_safe' => false], $routed->options);
    }

    public function testRejectsCancelSafeOptionForCoreCommands(): void
    {
        $router = new CommandRouter(new CommandHandlerRegistry([]));

        $command = new ApplyCommand(
            runId: 'run-stage-07',
            turnNo: 1,
            stepId: 'step-stage-07',
            attempt: 1,
            idempotencyKey: 'core-cancel-safe',
            kind: 'cancel',
            payload: [],
            options: ['cancel_safe' => true],
        );

        $routed = $router->route($command);

        self::assertSame('rejected', $routed->status);
        self::assertStringContainsString('reserved for extension commands', (string) $routed->reason);
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
