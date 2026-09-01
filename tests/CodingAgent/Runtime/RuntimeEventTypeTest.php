<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuntimeEventTypeEnum::class)]
final class RuntimeEventTypeTest extends TestCase
{
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
            RuntimeEventTypeEnum::UserMessageQueued,

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

            // Command protocol (controller <-> TUI)
            RuntimeEventTypeEnum::CommandAck,
            RuntimeEventTypeEnum::CommandRejected,

            // Runtime lifecycle (controller process)
            RuntimeEventTypeEnum::RuntimeReady,
            RuntimeEventTypeEnum::ProtocolError,
            RuntimeEventTypeEnum::RunResumed,
            RuntimeEventTypeEnum::RunHistoryPositionChanged,

            // Tool-local questions
            RuntimeEventTypeEnum::ToolQuestionRequested,

            // Background process completion
            RuntimeEventTypeEnum::BackgroundProcessCompleted,

            // Extension agent jobs
            RuntimeEventTypeEnum::ExtensionAgentJobFailed,

            // Compaction
            RuntimeEventTypeEnum::CompactionStarted,
            RuntimeEventTypeEnum::CompactionCompleted,
            RuntimeEventTypeEnum::CompactionFailed,

            // Model notification
            RuntimeEventTypeEnum::ModelNotification,
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
}
