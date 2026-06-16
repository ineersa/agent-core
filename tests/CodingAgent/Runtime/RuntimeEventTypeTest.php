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

        $this->assertNotEmpty($cases, 'Enum must have at least one case');

        foreach ($cases as $case) {
            $this->assertNotEmpty(
                $case->value,
                \sprintf('Case %s must have a non-empty string value', $case->name),
            );
            $this->assertStringContainsString(
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
        ];

        $cases = RuntimeEventTypeEnum::cases();

        foreach ($expected as $expectedCase) {
            $this->assertContains(
                $expectedCase,
                $cases,
                \sprintf(
                    'Expected case %s (value: "%s") is missing from RuntimeEventTypeEnum',
                    $expectedCase->name,
                    $expectedCase->value,
                ),
            );
        }

        $this->assertSameSize(
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
            $this->assertMatchesRegularExpression(
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
            $this->assertArrayNotHasKey(
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
        $this->assertSame($expectedFamily, $case->family());
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
        $this->assertTrue(RuntimeEventTypeEnum::RunStarted->isLifecycle());
        $this->assertFalse(RuntimeEventTypeEnum::RunStarted->isAssistantStream());
        $this->assertFalse(RuntimeEventTypeEnum::RunStarted->isTool());
        $this->assertFalse(RuntimeEventTypeEnum::RunStarted->isHitl());
        $this->assertFalse(RuntimeEventTypeEnum::RunStarted->isCancellation());

        $this->assertTrue(RuntimeEventTypeEnum::AssistantTextDelta->isAssistantStream());
        $this->assertFalse(RuntimeEventTypeEnum::AssistantTextDelta->isLifecycle());

        $this->assertTrue(RuntimeEventTypeEnum::ToolCallStarted->isTool());
        $this->assertTrue(RuntimeEventTypeEnum::ToolExecutionCompleted->isTool());

        $this->assertTrue(RuntimeEventTypeEnum::HumanInputRequested->isHitl());
        $this->assertTrue(RuntimeEventTypeEnum::CancellationRequested->isCancellation());

        $this->assertTrue(RuntimeEventTypeEnum::RuntimeReady->isRuntime());
        $this->assertFalse(RuntimeEventTypeEnum::RuntimeReady->isLifecycle());

        $this->assertTrue(RuntimeEventTypeEnum::ProtocolError->isProtocol());
        $this->assertFalse(RuntimeEventTypeEnum::ProtocolError->isLifecycle());

        $this->assertTrue(RuntimeEventTypeEnum::RunResumed->isLifecycle());
        $this->assertFalse(RuntimeEventTypeEnum::RunResumed->isCancellation());

        $this->assertTrue(RuntimeEventTypeEnum::ToolQuestionRequested->isToolQuestion());
        $this->assertFalse(RuntimeEventTypeEnum::ToolQuestionRequested->isLifecycle());
        $this->assertFalse(RuntimeEventTypeEnum::ToolQuestionRequested->isHitl());

        $this->assertSame('background_process_completion', RuntimeEventTypeEnum::BackgroundProcessCompleted->family());
        $this->assertFalse(RuntimeEventTypeEnum::BackgroundProcessCompleted->isLifecycle());
        $this->assertFalse(RuntimeEventTypeEnum::BackgroundProcessCompleted->isTool());
        $this->assertFalse(RuntimeEventTypeEnum::BackgroundProcessCompleted->isHitl());
    }

}
