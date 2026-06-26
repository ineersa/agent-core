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
        $this->assertSame(
            ['steer', 'follow_up', 'cancel', 'human_response', 'continue', 'compact'],
            CoreCommandKind::ALL,
        );
    }

    #[DataProvider('coreCommandKindIsCoreProvider')]
    public function testCoreCommandKindIsCore(string $kind, bool $expected): void
    {
        $this->assertSame($expected, CoreCommandKind::isCore($kind));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function coreCommandKindIsCoreProvider(): array
    {
        return [
            'steer' => [CoreCommandKind::Steer, true],
            'follow_up' => [CoreCommandKind::FollowUp, true],
            'cancel' => [CoreCommandKind::Cancel, true],
            'human_response' => [CoreCommandKind::HumanResponse, true],
            'continue' => [CoreCommandKind::Continue, true],
            'compact' => [CoreCommandKind::Compact, true],
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

        $this->assertSame('core', $command->status);
        $this->assertSame('steer', $command->kind);
        $this->assertSame(['input' => 'hello'], $command->payload);
        $this->assertSame(['cancel_safe' => true], $command->options);
        $this->assertNull($command->reason);
        $this->assertFalse($command->isRejected());
    }

    public function testRoutedCommandExtensionFactory(): void
    {
        $command = RoutedCommand::extension(
            kind: 'ext_custom_action',
            payload: ['action' => 'summarize'],
            options: [],
        );

        $this->assertSame('extension', $command->status);
        $this->assertSame('ext_custom_action', $command->kind);
        $this->assertSame(['action' => 'summarize'], $command->payload);
        $this->assertSame([], $command->options);
        $this->assertNull($command->reason);
        $this->assertFalse($command->isRejected());
    }

    public function testRoutedCommandRejectedFactory(): void
    {
        $command = RoutedCommand::rejected(
            kind: 'steer',
            reason: 'Validation failed: input too long',
        );

        $this->assertSame('rejected', $command->status);
        $this->assertSame('steer', $command->kind);
        $this->assertSame([], $command->payload);
        $this->assertSame([], $command->options);
        $this->assertSame('Validation failed: input too long', $command->reason);
        $this->assertTrue($command->isRejected());
    }

    /* ─── PendingCommand ─── */

    public function testPendingCommandDefaults(): void
    {
        $command = new PendingCommand(
            runId: 'run-pending',
            kind: 'steer',
            idempotencyKey: 'ik-abc',
        );

        $this->assertSame('run-pending', $command->runId);
        $this->assertSame('steer', $command->kind);
        $this->assertSame('ik-abc', $command->idempotencyKey);
        $this->assertSame([], $command->payload);
        $this->assertInstanceOf(CommandCancellationOptions::class, $command->options);
        $this->assertFalse($command->options->safe);
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

        $this->assertSame('cancel', $command->kind);
        $this->assertSame(['reason' => 'user requested'], $command->payload);
        $this->assertTrue($command->options->safe);
    }
}
