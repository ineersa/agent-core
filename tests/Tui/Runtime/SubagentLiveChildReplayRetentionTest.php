<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Runtime\SubagentLiveChildReplayRetention;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubagentLiveChildReplayRetention::class)]
final class SubagentLiveChildReplayRetentionTest extends TestCase
{
    #[Test]
    public function pendingHitlRequestsKeepsOnlyUnresolvedRequests(): void
    {
        $events = [
            new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextDelta->value, 'run-a', 0, ['delta' => 'noise']),
            new RuntimeEvent(RuntimeEventTypeEnum::HumanInputRequested->value, 'run-a', 1, ['question_id' => 'q1', 'prompt' => 'one']),
            new RuntimeEvent(RuntimeEventTypeEnum::HumanInputAnswered->value, 'run-a', 2, ['question_id' => 'q1', 'answer' => 'done']),
            new RuntimeEvent(RuntimeEventTypeEnum::HumanInputRequested->value, 'run-a', 3, ['question_id' => 'q2', 'prompt' => 'two']),
            new RuntimeEvent(RuntimeEventTypeEnum::ToolQuestionRequested->value, 'run-a', 0, ['request_id' => 't1', 'prompt' => 'tool']),
            new RuntimeEvent(RuntimeEventTypeEnum::ToolQuestionRequested->value, 'run-a', 0, ['request_id' => 'finished', 'tool_call_id' => 'tc-finished', 'prompt' => 'old tool']),
            new RuntimeEvent(RuntimeEventTypeEnum::ToolExecutionCompleted->value, 'run-a', 4, ['tool_call_id' => 'tc-finished']),
            new RuntimeEvent(RuntimeEventTypeEnum::CompactionCompleted->value, 'run-a', 4, []),
        ];

        $pending = SubagentLiveChildReplayRetention::pendingHitlRequests($events);

        $this->assertSame(
            [
                ['human_input.requested', 'q2'],
                ['tool_question.requested', 't1'],
            ],
            array_map(
                static fn (RuntimeEvent $event): array => [
                    $event->type,
                    (string) ($event->payload['question_id'] ?? $event->payload['request_id'] ?? ''),
                ],
                $pending,
            ),
        );
    }
}
