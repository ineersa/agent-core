<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeCommandReader;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\Contract\RunSequenceAllocatorInterface;
use Ineersa\CodingAgent\Session\EventLogMaxSeqBootstrapReader;
use Ineersa\CodingAgent\Session\JsonlRunEventLog;
use Ineersa\CodingAgent\Session\RunHistorySourceDTO;
use Ineersa\CodingAgent\Tests\Support\HistoryEventStoreFactory;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

final class AgentResumeCommandReaderTest extends IsolatedKernelTestCase
{
    private string $path;
    private TestLogger $logger;
    private RunHistorySourceProviderInterface $sources;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->logger = new TestLogger();
        $log = new JsonlRunEventLog($container->get(EventPayloadNormalizer::class), $container->get(LockFactory::class), $container->get(RunSequenceAllocatorInterface::class), new EventLogMaxSeqBootstrapReader(), $this->logger, presentations: HistoryEventStoreFactory::presentationFactory());
        $this->path = getcwd().'/.hatfield/sessions/indexed-resume/events.jsonl';
        $container->get(Filesystem::class)->mkdir(\dirname($this->path));
        $this->sources = $this->createStub(RunHistorySourceProviderInterface::class);
        $this->sources->method('historySource')->willReturn(new RunHistorySourceDTO($log, $this->path));
    }

    public function testFreshResumeAbsenceReadsOneBoundaryRegardlessOfArchiveGrowth(): void
    {
        $events = $this->createMock(EventStoreInterface::class);
        $events->expects($this->never())->method('rangeFor');
        $events->expects($this->never())->method('reverseFor');
        $reader = new AgentResumeCommandReader($events, $this->sources);
        $this->append('agent_command_applied', ['kind' => 'follow_up', 'idempotency_key' => 'known']);
        foreach ([8, 1000] as $growth) {
            for ($i = 0; $i < $growth; ++$i) {
                $this->append('llm_step_completed', ['assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => str_repeat('x', 128)]]]]);
            }
            $this->assertNull($reader->find('indexed-resume', 'fresh'));
            $this->logger->records = [];
            $this->assertNull($reader->find('indexed-resume', 'fresh'));
            $counts = [];
            foreach ($this->logger->records as $record) {
                $reason = $record['context']['read_reason'] ?? null;
                if (\is_string($reason)) {
                    $counts[$reason] = ($counts[$reason] ?? 0) + ($record['context']['lines_yielded'] ?? 0);
                }
            }
            $this->assertSame(1, $counts['index_boundary_validation'] ?? 0);
            $this->assertSame(0, $counts['index_cold_rebuild'] ?? 0);
            $this->assertSame(0, $counts['sequence_range'] ?? 0);
        }
    }

    public static function evidenceCases(): iterable
    {
        yield 'queued' => ['agent_command_queued', 'follow_up', false];
        yield 'applied' => ['agent_command_applied', 'follow_up', false];
        yield 'rejected' => ['agent_command_rejected', 'follow_up', false];
        yield 'later kind collision' => ['agent_command_applied', 'append_message', true];
    }

    #[DataProvider('evidenceCases')]
    public function testIndexedHitValidatesCanonicalEvidence(string $type, string $kind, bool $fallback): void
    {
        $older = $this->append('agent_command_applied', ['kind' => 'follow_up', 'idempotency_key' => 'key']);
        $latest = $this->append($type, ['kind' => $kind, 'idempotency_key' => 'key']);
        $events = $this->createMock(EventStoreInterface::class);
        $events->expects($this->once())->method('rangeFor')->with('indexed-resume', 2, 2)->willReturn([$latest]);
        $events->expects($fallback ? $this->once() : $this->never())->method('reverseFor')->willReturn([$latest, $older]);
        $this->assertSame($fallback ? $older : $latest, new AgentResumeCommandReader($events, $this->sources)->find('indexed-resume', 'key'));
    }

    public function testMissingIndexedCanonicalEvidenceFailsClosed(): void
    {
        $this->append('agent_command_applied', ['kind' => 'follow_up', 'idempotency_key' => 'key']);
        $events = $this->createMock(EventStoreInterface::class);
        $events->method('rangeFor')->willReturn([]);
        $events->expects($this->never())->method('reverseFor');
        $this->expectException(\RuntimeException::class);
        new AgentResumeCommandReader($events, $this->sources)->find('indexed-resume', 'key');
    }

    /** @param array<string, mixed> $payload */
    private function append(string $type, array $payload): RunEvent
    {
        $event = new RunEvent('indexed-resume', ++$this->sequence, 1, $type, $payload);
        $record = self::getContainer()->get(EventPayloadNormalizer::class)->normalize($event->runId, $event->seq, $event->turnNo, $event->type, $event->payload);
        file_put_contents($this->path, json_encode($record, \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);

        return $event;
    }
}
