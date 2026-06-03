<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Command;

use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Command\RoutedCommand;
use Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandBoundaryTest extends TestCase
{
    /* ─── CoreCommandKind ─── */

    public function testCoreCommandKindAllOrderedList(): void
    {
        self::assertSame(
            ['steer', 'follow_up', 'cancel', 'human_response', 'continue'],
            CoreCommandKind::ALL,
        );
    }

    #[DataProvider('coreCommandKindIsCoreProvider')]
    public function testCoreCommandKindIsCore(string $kind, bool $expected): void
    {
        self::assertSame($expected, CoreCommandKind::isCore($kind));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function coreCommandKindIsCoreProvider(): array
    {
        return [
            'steer' => ['steer', true],
            'follow_up' => ['follow_up', true],
            'cancel' => ['cancel', true],
            'human_response' => ['human_response', true],
            'continue' => ['continue', true],
            'ext_custom' => ['ext_custom', false],
            'unknown' => ['unknown', false],
            'empty_string' => ['', false],
        ];
    }

    /* ─── RoutedCommand ─── */

    public function testRoutedCommandCoreFactory(): void
    {
        $command = RoutedCommand::core(
            kind: 'steer',
            payload: ['input' => 'hello'],
            options: ['cancel_safe' => true],
        );

        self::assertSame('core', $command->status);
        self::assertSame('steer', $command->kind);
        self::assertSame(['input' => 'hello'], $command->payload);
        self::assertSame(['cancel_safe' => true], $command->options);
        self::assertNull($command->reason);
        self::assertFalse($command->isRejected());
    }

    public function testRoutedCommandExtensionFactory(): void
    {
        $command = RoutedCommand::extension(
            kind: 'ext_custom_action',
            payload: ['action' => 'summarize'],
            options: [],
        );

        self::assertSame('extension', $command->status);
        self::assertSame('ext_custom_action', $command->kind);
        self::assertSame(['action' => 'summarize'], $command->payload);
        self::assertSame([], $command->options);
        self::assertNull($command->reason);
        self::assertFalse($command->isRejected());
    }

    public function testRoutedCommandRejectedFactory(): void
    {
        $command = RoutedCommand::rejected(
            kind: 'steer',
            reason: 'Validation failed: input too long',
        );

        self::assertSame('rejected', $command->status);
        self::assertSame('steer', $command->kind);
        self::assertSame([], $command->payload);
        self::assertSame([], $command->options);
        self::assertSame('Validation failed: input too long', $command->reason);
        self::assertTrue($command->isRejected());
    }

    /* ─── PendingCommand ─── */

    public function testPendingCommandDefaults(): void
    {
        $command = new PendingCommand(
            runId: 'run-pending',
            kind: 'steer',
            idempotencyKey: 'ik-abc',
        );

        self::assertSame('run-pending', $command->runId);
        self::assertSame('steer', $command->kind);
        self::assertSame('ik-abc', $command->idempotencyKey);
        self::assertSame([], $command->payload);
        self::assertInstanceOf(CommandCancellationOptions::class, $command->options);
        self::assertFalse($command->options->safe);
    }

    public function testPendingCommandWithExplicitOptions(): void
    {
        $options = new CommandCancellationOptions(safe: true);
        $command = new PendingCommand(
            runId: 'run-pending',
            kind: 'cancel',
            idempotencyKey: 'ik-xyz',
            payload: ['reason' => 'user requested'],
            options: $options,
        );

        self::assertSame('cancel', $command->kind);
        self::assertSame(['reason' => 'user requested'], $command->payload);
        self::assertTrue($command->options->safe);
    }
}
