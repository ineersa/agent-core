<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuntimeEventTypeEnum::class)]
final class RuntimeEventTypeTest extends TestCase
{
    /**
     * Every case must have a non-empty dot-separated string value.
     */
    public function testEveryCaseHasNonEmptyValue(): void
    {
        $cases = RuntimeEventTypeEnum::cases();

        self::assertNotEmpty($cases, 'Enum must have at least one case');

        foreach ($cases as $case) {
            self::assertNotEmpty(
                $case->value,
                \sprintf('Case %s must have a non-empty string value', $case->name),
            );
            self::assertStringContainsString(
                '.',
                $case->value,
                \sprintf('Case %s value "%s" must contain a dot separator', $case->name, $case->value),
            );
        }
    }

    /**
     * The enum must cover every event name listed in the plan.
     *
     * This is the authoritative list from
     * .pi/plans/runtime-transcript-vertical-slice-plan.md § Proposed
     * normalized runtime event families.
     */
    public function testAllPlannedEventNamesAreCovered(): void
    {
        $expected = [
            // Run/turn lifecycle
            RuntimeEventTypeEnum::RunStarted,
            RuntimeEventTypeEnum::TurnStarted,
            RuntimeEventTypeEnum::TurnCompleted,
            RuntimeEventTypeEnum::TurnFailed,
            RuntimeEventTypeEnum::TurnCancelled,
            RuntimeEventTypeEnum::RunCompleted,
            RuntimeEventTypeEnum::RunFailed,
            RuntimeEventTypeEnum::RunCancelled,

            // User input
            RuntimeEventTypeEnum::UserMessageSubmitted,

            // Assistant message stream
            RuntimeEventTypeEnum::AssistantMessageStarted,
            RuntimeEventTypeEnum::AssistantTextStarted,
            RuntimeEventTypeEnum::AssistantTextDelta,
            RuntimeEventTypeEnum::AssistantTextCompleted,
            RuntimeEventTypeEnum::AssistantThinkingStarted,
            RuntimeEventTypeEnum::AssistantThinkingDelta,
            RuntimeEventTypeEnum::AssistantThinkingCompleted,
            RuntimeEventTypeEnum::AssistantMessageCompleted,
            RuntimeEventTypeEnum::AssistantMessageFailed,

            // Tool call lifecycle
            RuntimeEventTypeEnum::ToolCallStarted,
            RuntimeEventTypeEnum::ToolCallArgumentsDelta,
            RuntimeEventTypeEnum::ToolCallArgumentsCompleted,
            RuntimeEventTypeEnum::ToolExecutionStarted,
            RuntimeEventTypeEnum::ToolExecutionOutputDelta,
            RuntimeEventTypeEnum::ToolExecutionCompleted,
            RuntimeEventTypeEnum::ToolExecutionFailed,
            RuntimeEventTypeEnum::ToolExecutionCancelled,

            // Progress / status
            RuntimeEventTypeEnum::ProgressUpdated,
            RuntimeEventTypeEnum::StatusUpdated,

            // HITL
            RuntimeEventTypeEnum::HumanInputRequested,
            RuntimeEventTypeEnum::HumanInputAnswered,
            RuntimeEventTypeEnum::HumanInputRejected,
            RuntimeEventTypeEnum::ApprovalRequested,
            RuntimeEventTypeEnum::ApprovalApproved,
            RuntimeEventTypeEnum::ApprovalRejected,

            // Cancellation
            RuntimeEventTypeEnum::CancellationRequested,
            RuntimeEventTypeEnum::OperationCancelled,

            // Model / usage / cost
            RuntimeEventTypeEnum::ModelChanged,
            RuntimeEventTypeEnum::ReasoningChanged,
            RuntimeEventTypeEnum::UsageUpdated,
            RuntimeEventTypeEnum::ContextUpdated,
            RuntimeEventTypeEnum::CostUpdated,

            // Command protocol (controller <-> TUI)
            RuntimeEventTypeEnum::CommandAck,
            RuntimeEventTypeEnum::CommandRejected,

            // Runtime lifecycle (controller process)
            RuntimeEventTypeEnum::RuntimeReady,
            RuntimeEventTypeEnum::ProtocolError,
            RuntimeEventTypeEnum::RunResumed,

            // Tool-local questions
            RuntimeEventTypeEnum::ToolQuestionRequested,

            // Background process completion
            RuntimeEventTypeEnum::BackgroundProcessCompleted,

            // Model notification
            RuntimeEventTypeEnum::ModelNotification,
        ];

        $cases = RuntimeEventTypeEnum::cases();

        foreach ($expected as $expectedCase) {
            self::assertContains(
                $expectedCase,
                $cases,
                \sprintf(
                    'Expected case %s (value: "%s") is missing from RuntimeEventTypeEnum',
                    $expectedCase->name,
                    $expectedCase->value,
                ),
            );
        }

        self::assertSameSize(
            $expected,
            $cases,
            'RuntimeEventTypeEnum enum has unexpected extra cases — update this test',
        );
    }

    /**
     * Each event type string must match the documented format:
     * lowercase letters, digits, underscores, dots.
     */
    public function testValueStringsMatchNamingConvention(): void
    {
        foreach (RuntimeEventTypeEnum::cases() as $case) {
            self::assertMatchesRegularExpression(
                '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/',
                $case->value,
                \sprintf(
                    'Case %s value "%s" must match <family>.<name> convention (lowercase, dots, underscores)',
                    $case->name,
                    $case->value,
                ),
            );
        }
    }

    /**
     * No two enum cases may share the same string value.
     */
    public function testNoDuplicateStringValues(): void
    {
        $seen = [];

        foreach (RuntimeEventTypeEnum::cases() as $case) {
            self::assertArrayNotHasKey(
                $case->value,
                $seen,
                \sprintf(
                    'Duplicate event type string "%s" found on case %s (already used by %s)',
                    $case->value,
                    $case->name,
                    $seen[$case->value] ?? '???',
                ),
            );
            $seen[$case->value] = $case->name;
        }
    }

    /**
     * Verify family() returns the expected category for every case.
     */
    #[DataProvider('familyProvider')]
    public function testFamily(RuntimeEventTypeEnum $case, string $expectedFamily): void
    {
        self::assertSame($expectedFamily, $case->family());
    }

    /**
     * @return iterable<string, array{RuntimeEventTypeEnum, string}>
     */
    public static function familyProvider(): iterable
    {
        $lifecycle = [
            RuntimeEventTypeEnum::RunStarted,
            RuntimeEventTypeEnum::TurnStarted,
            RuntimeEventTypeEnum::TurnCompleted,
            RuntimeEventTypeEnum::TurnFailed,
            RuntimeEventTypeEnum::TurnCancelled,
            RuntimeEventTypeEnum::RunCompleted,
            RuntimeEventTypeEnum::RunFailed,
            RuntimeEventTypeEnum::RunCancelled,
            RuntimeEventTypeEnum::RunResumed,
        ];

        foreach ($lifecycle as $case) {
            yield $case->name => [$case, 'lifecycle'];
        }

        yield RuntimeEventTypeEnum::UserMessageSubmitted->name => [RuntimeEventTypeEnum::UserMessageSubmitted, 'user_input'];

        $assistant = [
            RuntimeEventTypeEnum::AssistantMessageStarted,
            RuntimeEventTypeEnum::AssistantTextStarted,
            RuntimeEventTypeEnum::AssistantTextDelta,
            RuntimeEventTypeEnum::AssistantTextCompleted,
            RuntimeEventTypeEnum::AssistantThinkingStarted,
            RuntimeEventTypeEnum::AssistantThinkingDelta,
            RuntimeEventTypeEnum::AssistantThinkingCompleted,
            RuntimeEventTypeEnum::AssistantMessageCompleted,
            RuntimeEventTypeEnum::AssistantMessageFailed,
        ];

        foreach ($assistant as $case) {
            yield $case->name => [$case, 'assistant_stream'];
        }

        $tool = [
            RuntimeEventTypeEnum::ToolCallStarted,
            RuntimeEventTypeEnum::ToolCallArgumentsDelta,
            RuntimeEventTypeEnum::ToolCallArgumentsCompleted,
            RuntimeEventTypeEnum::ToolExecutionStarted,
            RuntimeEventTypeEnum::ToolExecutionOutputDelta,
            RuntimeEventTypeEnum::ToolExecutionCompleted,
            RuntimeEventTypeEnum::ToolExecutionFailed,
            RuntimeEventTypeEnum::ToolExecutionCancelled,
        ];

        foreach ($tool as $case) {
            yield $case->name => [$case, 'tool'];
        }

        $progress = [
            RuntimeEventTypeEnum::ProgressUpdated,
            RuntimeEventTypeEnum::StatusUpdated,
        ];

        foreach ($progress as $case) {
            yield $case->name => [$case, 'progress'];
        }

        $hitl = [
            RuntimeEventTypeEnum::HumanInputRequested,
            RuntimeEventTypeEnum::HumanInputAnswered,
            RuntimeEventTypeEnum::HumanInputRejected,
            RuntimeEventTypeEnum::ApprovalRequested,
            RuntimeEventTypeEnum::ApprovalApproved,
            RuntimeEventTypeEnum::ApprovalRejected,
        ];

        foreach ($hitl as $case) {
            yield $case->name => [$case, 'hitl'];
        }

        $cancellation = [
            RuntimeEventTypeEnum::CancellationRequested,
            RuntimeEventTypeEnum::OperationCancelled,
        ];

        foreach ($cancellation as $case) {
            yield $case->name => [$case, 'cancellation'];
        }

        $command = [
            RuntimeEventTypeEnum::CommandAck,
            RuntimeEventTypeEnum::CommandRejected,
        ];

        foreach ($command as $case) {
            yield $case->name => [$case, 'command'];
        }

        yield RuntimeEventTypeEnum::RuntimeReady->name => [RuntimeEventTypeEnum::RuntimeReady, 'runtime'];

        yield RuntimeEventTypeEnum::ProtocolError->name => [RuntimeEventTypeEnum::ProtocolError, 'protocol'];

        $metadata = [
            RuntimeEventTypeEnum::ModelChanged,
            RuntimeEventTypeEnum::ReasoningChanged,
            RuntimeEventTypeEnum::UsageUpdated,
            RuntimeEventTypeEnum::ContextUpdated,
            RuntimeEventTypeEnum::CostUpdated,
        ];

        foreach ($metadata as $case) {
            yield $case->name => [$case, 'metadata'];
        }

        yield RuntimeEventTypeEnum::ToolQuestionRequested->name => [RuntimeEventTypeEnum::ToolQuestionRequested, 'tool_question'];

        yield RuntimeEventTypeEnum::BackgroundProcessCompleted->name => [RuntimeEventTypeEnum::BackgroundProcessCompleted, 'background_process_completion'];
    }

    /**
     * Verify the helper predicates.
     */
    public function testHelperPredicates(): void
    {
        self::assertTrue(RuntimeEventTypeEnum::RunStarted->isLifecycle());
        self::assertFalse(RuntimeEventTypeEnum::RunStarted->isAssistantStream());
        self::assertFalse(RuntimeEventTypeEnum::RunStarted->isTool());
        self::assertFalse(RuntimeEventTypeEnum::RunStarted->isHitl());
        self::assertFalse(RuntimeEventTypeEnum::RunStarted->isCancellation());

        self::assertTrue(RuntimeEventTypeEnum::AssistantTextDelta->isAssistantStream());
        self::assertFalse(RuntimeEventTypeEnum::AssistantTextDelta->isLifecycle());

        self::assertTrue(RuntimeEventTypeEnum::ToolCallStarted->isTool());
        self::assertTrue(RuntimeEventTypeEnum::ToolExecutionCompleted->isTool());

        self::assertTrue(RuntimeEventTypeEnum::HumanInputRequested->isHitl());
        self::assertTrue(RuntimeEventTypeEnum::CancellationRequested->isCancellation());

        self::assertTrue(RuntimeEventTypeEnum::RuntimeReady->isRuntime());
        self::assertFalse(RuntimeEventTypeEnum::RuntimeReady->isLifecycle());

        self::assertTrue(RuntimeEventTypeEnum::ProtocolError->isProtocol());
        self::assertFalse(RuntimeEventTypeEnum::ProtocolError->isLifecycle());

        self::assertTrue(RuntimeEventTypeEnum::RunResumed->isLifecycle());
        self::assertFalse(RuntimeEventTypeEnum::RunResumed->isCancellation());

        self::assertTrue(RuntimeEventTypeEnum::ToolQuestionRequested->isToolQuestion());
        self::assertFalse(RuntimeEventTypeEnum::ToolQuestionRequested->isLifecycle());
        self::assertFalse(RuntimeEventTypeEnum::ToolQuestionRequested->isHitl());

        self::assertSame('background_process_completion', RuntimeEventTypeEnum::BackgroundProcessCompleted->family());
        self::assertFalse(RuntimeEventTypeEnum::BackgroundProcessCompleted->isLifecycle());
        self::assertFalse(RuntimeEventTypeEnum::BackgroundProcessCompleted->isTool());
        self::assertFalse(RuntimeEventTypeEnum::BackgroundProcessCompleted->isHitl());
    }
}
