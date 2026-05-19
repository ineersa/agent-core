<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuntimeEventType::class)]
final class RuntimeEventTypeTest extends TestCase
{
    /**
     * Every case must have a non-empty dot-separated string value.
     */
    public function test_every_case_has_non_empty_value(): void
    {
        $cases = RuntimeEventType::cases();

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
            RuntimeEventType::RunStarted,
            RuntimeEventType::TurnStarted,
            RuntimeEventType::TurnCompleted,
            RuntimeEventType::TurnFailed,
            RuntimeEventType::TurnCancelled,
            RuntimeEventType::RunCompleted,
            RuntimeEventType::RunFailed,
            RuntimeEventType::RunCancelled,

            // User input
            RuntimeEventType::UserMessageSubmitted,

            // Assistant message stream
            RuntimeEventType::AssistantMessageStarted,
            RuntimeEventType::AssistantTextStarted,
            RuntimeEventType::AssistantTextDelta,
            RuntimeEventType::AssistantTextCompleted,
            RuntimeEventType::AssistantThinkingStarted,
            RuntimeEventType::AssistantThinkingDelta,
            RuntimeEventType::AssistantThinkingCompleted,
            RuntimeEventType::AssistantMessageCompleted,
            RuntimeEventType::AssistantMessageFailed,

            // Tool call lifecycle
            RuntimeEventType::ToolCallStarted,
            RuntimeEventType::ToolCallArgumentsDelta,
            RuntimeEventType::ToolCallArgumentsCompleted,
            RuntimeEventType::ToolExecutionStarted,
            RuntimeEventType::ToolExecutionOutputDelta,
            RuntimeEventType::ToolExecutionCompleted,
            RuntimeEventType::ToolExecutionFailed,
            RuntimeEventType::ToolExecutionCancelled,

            // Progress / status
            RuntimeEventType::ProgressUpdated,
            RuntimeEventType::StatusUpdated,

            // HITL
            RuntimeEventType::HumanInputRequested,
            RuntimeEventType::HumanInputAnswered,
            RuntimeEventType::HumanInputRejected,
            RuntimeEventType::ApprovalRequested,
            RuntimeEventType::ApprovalApproved,
            RuntimeEventType::ApprovalRejected,

            // Cancellation
            RuntimeEventType::CancellationRequested,
            RuntimeEventType::OperationCancelled,

            // Model / usage / cost
            RuntimeEventType::ModelChanged,
            RuntimeEventType::ReasoningChanged,
            RuntimeEventType::UsageUpdated,
            RuntimeEventType::ContextUpdated,
            RuntimeEventType::CostUpdated,
        ];

        $cases = RuntimeEventType::cases();

        foreach ($expected as $expectedCase) {
            self::assertContains(
                $expectedCase,
                $cases,
                \sprintf(
                    'Expected case %s (value: "%s") is missing from RuntimeEventType',
                    $expectedCase->name,
                    $expectedCase->value,
                ),
            );
        }

        self::assertSameSize(
            $expected,
            $cases,
            'RuntimeEventType enum has unexpected extra cases — update this test',
        );
    }

    /**
     * Each event type string must match the documented format:
     * lowercase letters, digits, underscores, dots.
     */
    public function test_value_strings_match_naming_convention(): void
    {
        foreach (RuntimeEventType::cases() as $case) {
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

        foreach (RuntimeEventType::cases() as $case) {
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
    public function test_family(RuntimeEventType $case, string $expectedFamily): void
    {
        self::assertSame($expectedFamily, $case->family());
    }

    /**
     * @return iterable<string, array{RuntimeEventType, string}>
     */
    public static function familyProvider(): iterable
    {
        $lifecycle = [
            RuntimeEventType::RunStarted,
            RuntimeEventType::TurnStarted,
            RuntimeEventType::TurnCompleted,
            RuntimeEventType::TurnFailed,
            RuntimeEventType::TurnCancelled,
            RuntimeEventType::RunCompleted,
            RuntimeEventType::RunFailed,
            RuntimeEventType::RunCancelled,
        ];

        foreach ($lifecycle as $case) {
            yield $case->name => [$case, 'lifecycle'];
        }

        yield RuntimeEventType::UserMessageSubmitted->name => [RuntimeEventType::UserMessageSubmitted, 'user_input'];

        $assistant = [
            RuntimeEventType::AssistantMessageStarted,
            RuntimeEventType::AssistantTextStarted,
            RuntimeEventType::AssistantTextDelta,
            RuntimeEventType::AssistantTextCompleted,
            RuntimeEventType::AssistantThinkingStarted,
            RuntimeEventType::AssistantThinkingDelta,
            RuntimeEventType::AssistantThinkingCompleted,
            RuntimeEventType::AssistantMessageCompleted,
            RuntimeEventType::AssistantMessageFailed,
        ];

        foreach ($assistant as $case) {
            yield $case->name => [$case, 'assistant_stream'];
        }

        $tool = [
            RuntimeEventType::ToolCallStarted,
            RuntimeEventType::ToolCallArgumentsDelta,
            RuntimeEventType::ToolCallArgumentsCompleted,
            RuntimeEventType::ToolExecutionStarted,
            RuntimeEventType::ToolExecutionOutputDelta,
            RuntimeEventType::ToolExecutionCompleted,
            RuntimeEventType::ToolExecutionFailed,
            RuntimeEventType::ToolExecutionCancelled,
        ];

        foreach ($tool as $case) {
            yield $case->name => [$case, 'tool'];
        }

        $progress = [
            RuntimeEventType::ProgressUpdated,
            RuntimeEventType::StatusUpdated,
        ];

        foreach ($progress as $case) {
            yield $case->name => [$case, 'progress'];
        }

        $hitl = [
            RuntimeEventType::HumanInputRequested,
            RuntimeEventType::HumanInputAnswered,
            RuntimeEventType::HumanInputRejected,
            RuntimeEventType::ApprovalRequested,
            RuntimeEventType::ApprovalApproved,
            RuntimeEventType::ApprovalRejected,
        ];

        foreach ($hitl as $case) {
            yield $case->name => [$case, 'hitl'];
        }

        $cancellation = [
            RuntimeEventType::CancellationRequested,
            RuntimeEventType::OperationCancelled,
        ];

        foreach ($cancellation as $case) {
            yield $case->name => [$case, 'cancellation'];
        }

        $metadata = [
            RuntimeEventType::ModelChanged,
            RuntimeEventType::ReasoningChanged,
            RuntimeEventType::UsageUpdated,
            RuntimeEventType::ContextUpdated,
            RuntimeEventType::CostUpdated,
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
        self::assertTrue(RuntimeEventType::RunStarted->isLifecycle());
        self::assertFalse(RuntimeEventType::RunStarted->isAssistantStream());
        self::assertFalse(RuntimeEventType::RunStarted->isTool());
        self::assertFalse(RuntimeEventType::RunStarted->isHitl());
        self::assertFalse(RuntimeEventType::RunStarted->isCancellation());

        self::assertTrue(RuntimeEventType::AssistantTextDelta->isAssistantStream());
        self::assertFalse(RuntimeEventType::AssistantTextDelta->isLifecycle());

        self::assertTrue(RuntimeEventType::ToolCallStarted->isTool());
        self::assertTrue(RuntimeEventType::ToolExecutionCompleted->isTool());

        self::assertTrue(RuntimeEventType::HumanInputRequested->isHitl());
        self::assertTrue(RuntimeEventType::CancellationRequested->isCancellation());
    }

    /**
     * RuntimeEventType::from() must round-trip from the string value.
     */
    public function test_from_string_value_roundtrip(): void
    {
        foreach (RuntimeEventType::cases() as $case) {
            $restored = RuntimeEventType::from($case->value);
            self::assertSame($case, $restored);
        }
    }

    /**
     * RuntimeEventType::tryFrom() must return null for unknown values.
     */
    public function test_tryFrom_unknown_value_returns_null(): void
    {
        self::assertNull(RuntimeEventType::tryFrom('nonexistent.event'));
        self::assertNull(RuntimeEventType::tryFrom(''));
    }

    /**
     * Verify the total count of enumerated event types so accidental
     * additions or deletions are caught in review.
     */
    public function test_total_count_is_expected(): void
    {
        // 8 lifecycle + 1 user_input + 9 assistant + 8 tool + 2 progress
        // + 6 HITL + 2 cancellation + 5 metadata = 41
        self::assertCount(41, RuntimeEventType::cases());
    }
}
