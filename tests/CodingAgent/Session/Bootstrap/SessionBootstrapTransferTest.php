<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Bootstrap;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapDescriptorDTO;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapProducer;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapTransfer;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SessionBootstrapTransferTest extends IsolatedKernelTestCase
{
    public function testSealedOwnerAttentionIsContentFreeAndSuffixKeepsTheOpening(): void
    {
        [$descriptor, $store, $transfer, $logger] = $this->prepare();
        $resume = $store->acknowledge($descriptor);
        $this->assertSame(2, $resume->foreground()['current_foreground_response_seq']);
        $this->assertSame([
            ['request_id' => 'question', 'category' => 'question'],
            ['request_id' => 'approval', 'category' => 'approval'],
        ], $resume->foreground()['pending_foreground_requests']);
        $this->assertStringNotContainsString('SECRET', json_encode($resume->toArray(), \JSON_THROW_ON_ERROR));
        $this->append($descriptor->runId, [
            ['agent_command_applied', ['kind' => 'human_response', 'question_id' => 'question']],
            ['turn_advanced', ['turn_no' => 2]],
        ]);
        $events = iterator_to_array($transfer->catchUp($descriptor, $resume));
        $ready = $events[array_key_last($events)];
        $this->assertSame(RuntimeEventTypeEnum::SessionReady->value, $ready->type);
        $this->assertSame(2, $ready->payload['foreground']['current_foreground_response_seq']);
        $this->assertSame([['request_id' => 'approval', 'category' => 'approval']], $ready->payload['foreground']['pending_foreground_requests']);
        $reads = array_values(array_filter($logger->records, static fn (array $record): bool => 'session.bootstrap.suffix_read' === $record['message']));
        $this->assertCount(1, $reads);
        $this->assertSame($ready->payload['end_offset'] - $descriptor->endOffset, $reads[0]['context']['archive_bytes_read']);
        $this->assertFalse($reads[0]['context']['full_scan']);
    }

    #[DataProvider('fences')]
    public function testCanonicalFencesAndTerminalsClearAttentionWithoutReplayingCompletion(string $type, array $payload): void
    {
        [$descriptor, $store, $transfer] = $this->prepare();
        $resume = $store->acknowledge($descriptor);
        $this->append($descriptor->runId, [[$type, $payload]]);
        $events = iterator_to_array($transfer->catchUp($descriptor, $resume));
        $ready = $events[array_key_last($events)];
        $this->assertSame(['current_foreground_response_seq' => null, 'pending_foreground_requests' => []], $ready->payload['foreground']);
        if ('agent_end' !== $type) {
            $this->assertCount(1, $events, 'Dropped canonical history fences still update the ready baseline.');
        }
        // Same token, newer cut: this is the controller drain-race continuation,
        // not a second acknowledgement or another archive-prefix read.
        $cursor = new SessionBootstrapDescriptorDTO($descriptor->runId, $descriptor->bootstrapId, $descriptor->viewEpoch,
            $ready->payload['canonical_seq'], $ready->payload['end_offset'], $descriptor->selectedAnchor,
            $descriptor->records, $descriptor->bytes, $descriptor->checksum);
        $this->append($descriptor->runId, [['turn_advanced', ['turn_no' => 1]]]);
        $next = iterator_to_array($transfer->catchUp($cursor, $resume));
        $nextReady = $next[array_key_last($next)];
        $this->assertSame($next[0]->seq, $nextReady->payload['foreground']['current_foreground_response_seq']);
        $this->assertSame($descriptor->bootstrapId, $nextReady->payload['bootstrap_id']);
        $this->assertGreaterThan($ready->payload['canonical_seq'], $nextReady->payload['canonical_seq']);
    }

    public static function fences(): iterable
    {
        yield 'selection' => ['history_position_set', ['reason' => 'history_select', 'selected_prompt_turn_no' => 0]];
        yield 'tail discard' => ['history_tail_discarded', ['after_turn_no' => 0]];
        yield 'terminal' => ['agent_end', ['reason' => 'completed']];
    }

    public function testCommitDuringSuffixYieldIsFoldedBeforeReady(): void
    {
        [$descriptor, $store, $transfer, $logger] = $this->prepare();
        $resume = $store->acknowledge($descriptor);
        $this->append($descriptor->runId, [['turn_advanced', ['turn_no' => 2]]]);
        $stream = $transfer->catchUp($descriptor, $resume);
        $this->assertSame(RuntimeEventTypeEnum::TurnStarted->value, $stream->current()->type);
        $this->append($descriptor->runId, [['history_tail_discarded', ['after_turn_no' => 0]]]);
        $stream->next();
        $this->assertSame(RuntimeEventTypeEnum::SessionReady->value, $stream->current()->type);
        $this->assertNull($stream->current()->payload['foreground']['current_foreground_response_seq']);
        $this->assertSame([], $stream->current()->payload['foreground']['pending_foreground_requests']);
        $this->assertCount(2, array_filter($logger->records, static fn (array $record): bool => 'session.bootstrap.suffix_read' === $record['message']));
    }

    public function testRebootstrapOfTheSameSelectionSealsTheNewCutWithoutHistoricalAttention(): void
    {
        [$original, $store] = $this->prepare();
        $store->acknowledge($original);
        $previous = $original;
        for ($pass = 0; $pass < 2; ++$pass) {
            $this->append($original->runId, [['history_position_set', ['reason' => 'history_select', 'selected_prompt_turn_no' => 0]]]);
            static::getContainer()->get(ActiveRunContextInterface::class)->release($original->runId);
            $producer = static::getContainer()->get(SessionBootstrapProducer::class);
            $producer->prepare($original->runId);
            $current = $producer->seal();
            $this->assertGreaterThan($previous->canonicalSeq, $current->canonicalSeq);
            $this->assertGreaterThan($previous->viewEpoch, $current->viewEpoch);
            $this->assertSame(['current_foreground_response_seq' => null, 'pending_foreground_requests' => []], $store->acknowledge($current)->foreground());
            $previous = $current;
        }
    }

    /** @return array{SessionBootstrapDescriptorDTO, SessionBootstrapSpoolStore, SessionBootstrapTransfer, TestLogger} */
    private function prepare(): array
    {
        $container = static::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('foreground transfer');
        $this->append($run, [
            ['run_started', []],
            ['turn_advanced', ['turn_no' => 1]],
            ['waiting_human', ['question_id' => 'question', 'prompt' => 'SECRET prompt']],
            ['waiting_human', ['question_id' => 'approval', 'approval_context' => ['arguments' => 'SECRET arguments']]],
        ]);
        $producer = $container->get(SessionBootstrapProducer::class);
        $producer->prepare($run);
        $descriptor = $producer->seal();
        $store = $container->get(SessionBootstrapSpoolStore::class);
        $logger = new TestLogger();
        $transfer = new SessionBootstrapTransfer($store, $container->get(RunHistorySourceProviderInterface::class), $container->get(RuntimeEventMapper::class), $logger);

        return [$descriptor, $store, $transfer, $logger];
    }

    /** @param list<array{string, array<string, mixed>}> $records */
    private function append(string $run, array $records): void
    {
        PreparedEventStoreSeeder::appendMany(static::getContainer()->get(PreparedTransitionEventStoreInterface::class),
            array_map(static fn (array $record): RunEvent => RunEvent::forAppend($run, 1, $record[0], $record[1]), $records));
    }
}
