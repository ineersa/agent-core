<?php

declare(strict_types=1);

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Tests\Support\SessionColdReconstructionTestFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$runId = 'cold-recon-memory';
$tmpDir = TestDirectoryIsolation::createOsTempDir('cold-recon-memory');
TestDirectoryIsolation::createHatfieldTree($tmpDir, withSessions: true);
$sessionDir = $tmpDir.'/.hatfield/sessions/'.$runId;
mkdir($sessionDir, 0777, true);
$eventsPath = $sessionDir.'/events.jsonl';
$normalizer = new EventPayloadNormalizer();
$handle = fopen($eventsPath, 'wb');
if (false === $handle) {
    throw new RuntimeException('Unable to open events.jsonl');
}

$write = static function (RunEvent $event) use ($handle, $normalizer): void {
    fwrite($handle, json_encode($normalizer->normalizeRunEvent($event), \JSON_THROW_ON_ERROR)."\n");
};

$write(new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, [
    'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Answer A prompt']]]],
]));
$write(new RunEvent($runId, 2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]));
$write(new RunEvent($runId, 3, 1, RunEventTypeEnum::HistoryPositionSet->value, [
    'position_turn_no' => 1,
    'reason' => 'continue',
]));
$write(new RunEvent($runId, 4, 1, RunEventTypeEnum::LlmStepCompleted->value, [
    'step_id' => 'step-a',
    'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Answer A']]],
    'usage' => ['input_tokens' => 11, 'output_tokens' => 7, 'cost' => 0.01],
]));

// Retained bookkeeping that would explode if buffered as retainedCandidates:
// many completed LLM steps and compaction histories with large payloads, still
// on the live retained tip (no discard yet).
$seq = 5;
for ($i = 0; $i < 180; ++$i) {
    $blob = str_repeat((string) ($i % 10), 262144);
    $write(new RunEvent($runId, $seq++, 1, RunEventTypeEnum::LlmStepCompleted->value, [
        'step_id' => 'hist-'.$i,
        'assistant_message' => [
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'historical bookkeeping '.$i]],
        ],
        'usage' => ['input_tokens' => 1000 + $i, 'output_tokens' => 20, 'cost' => 0.001],
        'bookkeeping' => $blob,
    ]));
    $write(new RunEvent($runId, $seq++, 1, RunEventTypeEnum::ContextCompacted->value, [
        'messages' => [[
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'compacted history '.$i.' '.$blob]],
        ]],
        'summary' => $blob,
    ]));
}

$write(new RunEvent($runId, $seq++, 1, RunEventTypeEnum::AgentCommandApplied->value, [
    'kind' => 'follow_up',
    'text' => 'discarded prompt',
]));
$discardedTurnSeq = $seq;
$write(new RunEvent($runId, $seq++, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]));
$write(new RunEvent($runId, $seq++, 2, RunEventTypeEnum::HistoryPositionSet->value, [
    'position_turn_no' => 2,
    'reason' => 'continue',
]));
$write(new RunEvent($runId, $seq++, 2, RunEventTypeEnum::LlmStepCompleted->value, [
    'step_id' => 'step-b',
    'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Answer B discarded']]],
]));
// Unmatched post-completion pending launch before history_select (must suppress).
$pendingLaunchSeq = $seq;
$write(new RunEvent($runId, $seq++, 2, RunEventTypeEnum::AgentCommandApplied->value, [
    'kind' => 'follow_up',
    'idempotency_key' => 'pending-discarded-launch',
    'text' => 'should not seed retained state',
]));
$write(new RunEvent($runId, $seq++, 1, RunEventTypeEnum::HistoryPositionSet->value, [
    'position_turn_no' => 1,
    'previous_position_turn_no' => 2,
    'reason' => 'history_select',
]));
$write(new RunEvent($runId, $seq++, 1, RunEventTypeEnum::HistoryTailDiscarded->value, [
    'after_turn_no' => 1,
]));
$write(new RunEvent($runId, $seq++, 1, RunEventTypeEnum::AgentCommandApplied->value, [
    'kind' => 'follow_up',
    'text' => 'Answer C prompt',
]));
$write(new RunEvent($runId, $seq++, 3, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 3]));
$write(new RunEvent($runId, $seq++, 3, RunEventTypeEnum::HistoryPositionSet->value, [
    'position_turn_no' => 3,
    'reason' => 'continue',
]));
$write(new RunEvent($runId, $seq++, 3, RunEventTypeEnum::LlmStepCompleted->value, [
    'step_id' => 'step-c',
    'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Answer C active']]],
    'usage' => ['input_tokens' => 5, 'output_tokens' => 3, 'cost' => 0.002],
]));
$write(new RunEvent($runId, $seq++, 3, RunEventTypeEnum::AgentEnd->value, [
    'reason' => 'completed',
]));
$lastSeq = $seq - 1;
fclose($handle);
file_put_contents($sessionDir.'/'.Ineersa\CodingAgent\Session\FileRunSequenceAllocator::COUNTER_BASENAME, (string) $lastSeq."\n");

$rangeCalls = 0;
$allForCalls = 0;
$store = new class($runId, $eventsPath, $rangeCalls, $allForCalls, $normalizer) implements EventStoreInterface {
    public function __construct(
        private string $runId,
        private string $eventsPath,
        private int &$rangeCalls,
        private int &$allForCalls,
        private EventPayloadNormalizer $normalizer,
    ) {
    }

    public function append(RunEvent $event): RunEvent
    {
        throw new RuntimeException('append not supported');
    }

    public function appendMany(array $events): array
    {
        throw new RuntimeException('appendMany not supported');
    }

    public function latestSequenceFor(string $runId): ?int
    {
        // Tip lookup is independent of the cold reconstruction scan counter.
        $max = null;
        $handle = fopen($this->eventsPath, 'rb');
        if (false === $handle) {
            return null;
        }
        try {
            while (false !== ($line = fgets($handle))) {
                $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                $event = $this->normalizer->denormalizeRunEvent($decoded);
                if (null === $event || $event->runId !== $runId) {
                    continue;
                }
                $max = $event->seq;
            }
        } finally {
            fclose($handle);
        }

        return $max;
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        ++$this->rangeCalls;
        $handle = fopen($this->eventsPath, 'rb');
        if (false === $handle) {
            return;
        }
        try {
            while (false !== ($line = fgets($handle))) {
                $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                $event = $this->normalizer->denormalizeRunEvent($decoded);
                if (null === $event) {
                    continue;
                }
                if ($event->runId !== $runId) {
                    continue;
                }
                if ($event->seq > $endSeq) {
                    break;
                }
                if ($event->seq >= $startSeq) {
                    yield $event;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    public function readAfterSeq(string $runId, int $cursor): array
    {
        $events = [];
        foreach ($this->rangeFor($runId, 1, \PHP_INT_MAX) as $event) {
            if ($event->seq > $cursor) {
                $events[] = $event;
            }
        }

        return $events;
    }
};

try {
    $service = SessionColdReconstructionTestFactory::create($store);
    $result = $service->reconstruct($runId, publishSharedState: false, publishHistory: true);
    $joined = implode("\n", array_map(
        static fn ($block): string => $block->text,
        $result->transcript->transcriptBlocks,
    ));
    $messageTexts = [];
    foreach ($result->runState->messages as $message) {
        $messageTexts[] = json_encode($message, \JSON_THROW_ON_ERROR);
    }
    $messagesJoined = implode("\n", $messageTexts);

    fwrite(\STDOUT, json_encode([
        'limit' => ini_get('memory_limit'),
        'peak_bytes' => memory_get_peak_usage(true),
        'last_seq' => $result->lastSeq,
        'position' => $result->historySnapshot->history->positionTurnNo,
        'messages' => count($result->runState->messages),
        'has_a' => str_contains($joined, 'Answer A'),
        'has_c' => str_contains($joined, 'Answer C active'),
        'has_discarded' => str_contains($joined, 'Answer B discarded') || str_contains($messagesJoined, 'Answer B discarded'),
        'has_pending_launch' => str_contains($messagesJoined, 'should not seed retained state'),
        'usage_input' => $result->transcript->resume->usageInputTokens,
        'usage_output' => $result->transcript->resume->usageOutputTokens,
        'range_calls' => $rangeCalls,
        'all_for_calls' => $allForCalls,
        'shell_only' => $result->isShellOnlySession,
        'terminal_reason' => $result->terminalActivityReason,
        'pending_launch_seq' => $pendingLaunchSeq,
        'discarded_turn_seq' => $discardedTurnSeq,
        'archive_bytes' => filesize($eventsPath),
    ], \JSON_THROW_ON_ERROR)."\n");
} finally {
    TestDirectoryIsolation::removeDirectory($tmpDir);
}
