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
    public function test_every_case_has_non_empty_value(): void
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
    public function test_all_planned_event_names_are_covered(): void
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
    public function test_value_strings_match_naming_convention(): void
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
    public function test_no_duplicate_string_values(): void
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
    public function test_family(RuntimeEventTypeEnum $case, string $expectedFamily): void
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
    }

    /**
     * Verify the helper predicates.
     */
    public function test_helper_predicates(): void
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
    }

    /**
     * RuntimeEventTypeEnum::from() must round-trip from the string value.
     */
    public function test_from_string_value_roundtrip(): void
    {
        foreach (RuntimeEventTypeEnum::cases() as $case) {
            $restored = RuntimeEventTypeEnum::from($case->value);
            self::assertSame($case, $restored);
        }
    }

    /**
     * RuntimeEventTypeEnum::tryFrom() must return null for unknown values.
     */
    public function test_tryFrom_unknown_value_returns_null(): void
    {
        self::assertNull(RuntimeEventTypeEnum::tryFrom('nonexistent.event'));
        self::assertNull(RuntimeEventTypeEnum::tryFrom(''));
    }

    /**
     * Verify the total count of enumerated event types so accidental
     * additions or deletions are caught in review.
     */
    public function test_total_count_is_expected(): void
    {
        // 8 lifecycle + 1 user_input + 9 assistant + 8 tool + 2 progress
        // + 6 HITL + 2 cancellation + 5 metadata + 2 command = 43
        self::assertCount(43, RuntimeEventTypeEnum::cases());
    }
}
