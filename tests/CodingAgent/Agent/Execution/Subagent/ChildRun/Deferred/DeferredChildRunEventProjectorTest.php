<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunEventProjector;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis: committed child projection must expose only safe tool argument pairs,
 * never raw secrets or full arg blobs, while preserving assistant result text and
 * honoring parent-committed status/turn overrides.
 */
final class DeferredChildRunEventProjectorTest extends TestCase
{
    public function testApplyEnforcesPrivacyStatusOverridesAndMalformedArgumentSafety(): void
    {
        $projector = new DeferredChildRunEventProjector();
        $current = new DeferredChildRunLifecycleProjectionDTO(
            childStatus: RunStatus::Running,
            childTurnNo: 0,
            lastCommittedSeq: 0,
        );

        $longText = str_repeat('Z', 300);
        $secretArgs = json_encode(['path' => '/safe/path.php', 'api_key' => 'super-secret', 'new_string' => 'leak'], \JSON_THROW_ON_ERROR);
        $summaries = [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
                'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $longText]]],
            ]),
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => '']],
                    'tool_calls' => [['id' => 'tc1', 'name' => 'read', 'arguments' => $secretArgs]],
                ],
            ]),
            new AfterTurnCommitEventSummary(3, RunEventTypeEnum::ToolExecutionEnd->value, ['tool_call_id' => 'tc1']),
        ];

        $projection = $projector->apply(
            $current,
            $summaries,
            definitionModel: null,
            committedStatus: RunStatus::WaitingHuman,
            committedTurnNo: 4,
        );

        $this->assertNotEmpty($projection->recentTools);
        $this->assertStringContainsString('safe/path.php', $projection->recentTools[0]);
        $json = json_encode($projection, \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('super-secret', $json);
        $this->assertStringNotContainsString('leak', $json);
        $this->assertStringNotContainsString('"args"', $json);
        $this->assertSame(RunStatus::WaitingHuman, $projection->childStatus);
        $this->assertSame(4, $projection->childTurnNo);
        $this->assertSame($longText, $projection->assistantResultText);
        $this->assertLessThanOrEqual(220, mb_strlen((string) $projection->assistantExcerpt));

        $projection = $projector->apply(
            $projection,
            [new AfterTurnCommitEventSummary(4, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 5])],
            definitionModel: null,
            committedStatus: RunStatus::Running,
            committedTurnNo: 5,
        );
        $this->assertSame(RunStatus::Running, $projection->childStatus);
        $this->assertSame(5, $projection->childTurnNo);

        $malformed = new AfterTurnCommitEventSummary(5, RunEventTypeEnum::LlmStepCompleted->value, [
            'assistant_message' => [
                'role' => 'assistant',
                'tool_calls' => [['id' => 'tc2', 'name' => 'grep', 'arguments' => '{not-json']],
            ],
        ]);
        $projection = $projector->apply(
            $projection,
            [$malformed],
            definitionModel: null,
            committedStatus: RunStatus::Compacting,
            committedTurnNo: 5,
        );
        $this->assertSame(RunStatus::Compacting, $projection->childStatus);
        $this->assertStringContainsString('grep', json_encode($projection, \JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('not-json', json_encode($projection, \JSON_THROW_ON_ERROR));

        $projection = $projector->apply(
            $projection,
            [new AfterTurnCommitEventSummary(6, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 6])],
            definitionModel: null,
            committedStatus: RunStatus::Cancelling,
            committedTurnNo: 6,
        );
        $this->assertSame(RunStatus::Cancelling, $projection->childStatus);
        $this->assertSame(6, $projection->childTurnNo);
    }
}
