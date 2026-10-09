<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapDescriptorDTO;
use Ineersa\CodingAgent\Session\Replay\SessionResumeMetadataProjection;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;

#[AllowMockObjectsWithoutExpectations]
final class TuiBootstrapAssemblerTest extends IsolatedKernelTestCase
{
    private TestLogger $logger;

    public function testValidatedMountPrecedesExactAcknowledgementAndDurableSuffixReadiness(): void
    {
        [$state, $harness, $poller] = $this->scope();
        [$cut, $available, $frame, $end] = $this->transfer();
        $events = [$available, $frame];
        $client = $this->createMock(AgentSessionClient::class);
        $client->method('events')->willReturnCallback(static function () use (&$events): array { return $events; });
        $client->expects($this->once())->method('acknowledgeBootstrap')->with($cut->toArray())->willReturnCallback(static function () use ($harness, $state): void {
            self::assertStringContainsString('Owner-projected answer', $harness->plainScreenText());
            self::assertStringContainsString('Restored pending input', $harness->plainScreenText());
            self::assertSame('Restored pending input', $state->queuedUserMessages['17']);
            self::assertSame(13, $state->lastSeq);
            self::assertFalse($state->sessionReady);
        });
        $mount = static function () use ($harness, $state): void {
            $harness->screen()->setTranscriptBlocks($state->transcript);
            $harness->screen()->syncQueuedUserMessages($state->queuedUserMessages);
        };
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertSame(0, $state->lastSeq);
        $this->assertStringContainsString('Previous mounted view', $harness->plainScreenText());
        $this->assertStringNotContainsString('Owner-projected answer', $harness->plainScreenText());
        $events = [$end];
        $state->lastPoll = 0;
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertTrue($state->bootstrapMounted, ($this->logger->records[0]['context']['exception'] ?? null)?->getPrevious()?->getMessage() ?? $state->lastRuntimePollError);
        $this->assertFalse($state->sessionReady);
        $this->assertSame(RunActivityStateEnum::Completed, $state->activity);
        $this->assertSame(41, $state->usage->inputTokens);
        $events = [$available, $frame, $end];
        $state->lastPoll = 0;
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertNull($state->bootstrapError, 'A repeated completed transfer cannot remount or acknowledge twice.');
        $this->assertSame(13, $state->lastSeq);
        $events = [new RuntimeEvent('user.message_submitted', '42', 19, ['text' => 'Canonical suffix', 'idempotency_key' => 'suffix']),
            new RuntimeEvent('session.ready', '42', 0, ['bootstrap_id' => $cut->bootstrapId, 'view_epoch' => $cut->viewEpoch, 'canonical_seq' => 23, 'end_offset' => 999])];
        $state->lastPoll = 0;
        $changes = $poller->poll($state, $client, onBootstrapMounted: $mount);
        if (null !== $changes) {
            $harness->screen()->applyTranscriptChangeSet($changes);
        }
        $this->assertTrue($state->sessionReady);
        $this->assertSame(23, $state->lastSeq, 'Actual committed cut includes unmapped events and sequence holes.');
        $this->assertStringContainsString('Canonical suffix', $harness->plainScreenText());
    }

    #[DataProvider('invalidTransfers')]
    public function testInvalidOrDisconnectedTransferNeverMountsAndCancels(string $fault): void
    {
        [$state, $harness, $poller] = $this->scope();
        [$cut, $available, $frame, $end] = $this->transfer();
        if ('checksum' === $fault) {
            $frame = new RuntimeEvent($frame->type, '42', 0, $frame->payload + []);
            $frame = new RuntimeEvent($frame->type, '42', 0, array_replace($frame->payload, ['data' => base64_encode(str_replace('answer', 'ANSWER', base64_decode($frame->payload['data'], true)))]));
        } elseif ('order' === $fault) {
            $frame = new RuntimeEvent($frame->type, '42', 0, array_replace($frame->payload, ['index' => 1]));
        } elseif ('cut' === $fault) {
            $end = new RuntimeEvent($end->type, '42', 0, array_replace($end->payload, ['canonical_seq' => 14]));
        } elseif ('metadata' === $fault) {
            $lines = explode("\n", base64_decode($frame->payload['data'], true));
            $resume = json_decode($lines[0], true, 512, \JSON_THROW_ON_ERROR);
            $resume['data']['queued_messages'] = array_fill_keys(array_map(static fn (int $index): string => 'q'.$index, range(0, 2000)), 'Input');
            $bytes = json_encode($resume, \JSON_THROW_ON_ERROR)."\n".$lines[1]."\n";
            $this->assertLessThanOrEqual(\Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore::FRAME_BYTES, \strlen($bytes));
            $cut = SessionBootstrapDescriptorDTO::fromArray(array_replace($cut->toArray(), ['bytes' => \strlen($bytes), 'checksum' => hash('sha256', $bytes)]));
            $available = new RuntimeEvent($available->type, '42', 0, $cut->toArray() + ['command_id' => 'request']);
            $frame = new RuntimeEvent($frame->type, '42', 0, array_replace($frame->payload, ['data' => base64_encode($bytes)]));
            $end = new RuntimeEvent($end->type, '42', 0, $cut->toArray() + ['frames' => 1]);
        }
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('acknowledgeBootstrap');
        $client->expects($this->once())->method('cancelBootstrap')->with('42');
        if ('disconnect' === $fault) {
            $client->method('events')->willThrowException(new \Ineersa\CodingAgent\Runtime\Contract\RuntimeTransportException('Disconnected controller.'));
        } else {
            $client->method('events')->willReturn([$available, $frame, $end]);
        }
        $changes = $poller->poll($state, $client, onBootstrapMounted: static function (): void { self::fail('Invalid transfer mounted.'); });
        if (null !== $changes) {
            $harness->screen()->applyTranscriptChangeSet($changes);
        }
        $this->assertFalse($state->sessionReady);
        $this->assertFalse($state->bootstrapMounted);
        $this->assertSame(0, $state->lastSeq);
        $this->assertStringContainsString('Previous mounted view', $harness->plainScreenText());
        $this->assertNotNull($state->bootstrapError);
    }

    public static function invalidTransfers(): iterable
    {
        yield 'checksum' => ['checksum'];
        yield 'frame order' => ['order'];
        yield 'mismatching cut' => ['cut'];
        yield 'oversized metadata' => ['metadata'];
        yield 'disconnected' => ['disconnect'];
    }

    public function testSupersedingEpochReleasesPartialFramesAndIgnoresOldEnds(): void
    {
        [$state, $harness, $poller] = $this->scope();
        [$cut, $available, $frame, $end] = $this->transfer();
        $bytes = base64_decode($frame->payload['data'], true);
        $partial = new RuntimeEvent('bootstrap.frame', '42', 0, array_replace($frame->payload, ['data' => base64_encode(substr($bytes, 0, 27))]));
        $next = array_replace($cut->toArray(), ['bootstrap_id' => str_repeat('b', 32), 'view_epoch' => 4]);
        $events = [$available, $partial];
        $client = $this->createMock(AgentSessionClient::class);
        $client->method('events')->willReturnCallback(static function () use (&$events): array { return $events; });
        $client->expects($this->once())->method('acknowledgeBootstrap')->with($next);
        $mount = static function () use ($harness, $state): void { $harness->screen()->setTranscriptBlocks($state->transcript); };
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $events = [new RuntimeEvent('bootstrap.available', '42', 0, $next + ['command_id' => 'request']), $end, $frame];
        $state->lastPoll = 0;
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertSame(0, $state->lastSeq);
        $this->assertStringContainsString('Previous mounted view', $harness->plainScreenText());
        $events = [new RuntimeEvent('bootstrap.frame', '42', 0, ['bootstrap_id' => $next['bootstrap_id'], 'view_epoch' => 4, 'index' => 0, 'data' => base64_encode(substr($bytes, 0, 27))]),
            new RuntimeEvent('bootstrap.frame', '42', 0, ['bootstrap_id' => $next['bootstrap_id'], 'view_epoch' => 4, 'index' => 1, 'data' => base64_encode(substr($bytes, 27))]),
            new RuntimeEvent('bootstrap.end', '42', 0, $next + ['frames' => 2])];
        $state->lastPoll = 0;
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertTrue($state->bootstrapMounted);
        $this->assertStringContainsString('Owner-projected answer', $harness->plainScreenText());
        $events = [$available, $frame, $end];
        $state->lastPoll = 0;
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertSame(13, $state->lastSeq);
        $this->assertNull($state->bootstrapError);
    }

    public function testSameProcessSwitchDropsPartialAssemblyAndAcceptsTheNewRequestEpoch(): void
    {
        [$state, $harness, $poller] = $this->scope();
        [, $available, $frame, $end] = $this->transfer();
        $events = [$available, new RuntimeEvent('bootstrap.frame', '42', 0, array_replace($frame->payload, ['data' => base64_encode(substr(base64_decode($frame->payload['data'], true), 0, 27))]))];
        $client = $this->createMock(AgentSessionClient::class);
        $client->method('events')->willReturnCallback(static function () use (&$events): array { return $events; });
        [$next, $nextAvailable, $nextFrame, $nextEnd] = $this->transfer('43', 'next-request', 1);
        $client->expects($this->once())->method('acknowledgeBootstrap')->with($next->toArray());
        $mount = static function () use ($harness, $state): void { $harness->screen()->setTranscriptBlocks($state->transcript); };
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $state->sessionId = '43';
        $state->handle = new RunHandle('43', 'bootstrapping', 'next-request');
        $state->lastPoll = 0;
        $events = [$end, $frame, $nextAvailable, $nextFrame, $nextEnd];
        $poller->poll($state, $client, onBootstrapMounted: $mount);
        $this->assertTrue($state->bootstrapMounted);
        $this->assertNull($state->bootstrapError);
        $this->assertSame(13, $state->lastSeq);
        $this->assertStringContainsString('Owner-projected answer', $harness->plainScreenText());
    }

    public function testTimeoutReleasesTheTransferWithoutAUsableEmptySession(): void
    {
        [$state, $harness, $poller] = $this->scope();
        $state->bootstrapStartedAt = microtime(true) - 61;
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('events');
        $client->expects($this->once())->method('cancelBootstrap')->with('42');
        $poller->poll($state, $client);
        $this->assertFalse($state->sessionReady);
        $this->assertNotNull($state->bootstrapError);
        $this->assertSame(0, $state->lastSeq);
        $this->assertStringContainsString('Previous mounted view', $harness->plainScreenText());
    }

    public function testStaleRequestIdAndSupersededEpochDoNotChangeMountedView(): void
    {
        [$state, $harness, $poller] = $this->scope();
        [$cut, $available, $frame, $end] = $this->transfer();
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('acknowledgeBootstrap');
        $stale = new RuntimeEvent('bootstrap.available', '42', 0, array_replace($available->payload, ['command_id' => 'previous-request']));
        $client->method('events')->willReturn([$stale, $frame, $end]);
        $poller->poll($state, $client, onBootstrapMounted: static function (): void { self::fail('Stale view mounted.'); });
        $this->assertSame(0, $state->lastSeq);
        $this->assertFalse($state->bootstrapMounted);
        $this->assertStringContainsString('Previous mounted view', $harness->plainScreenText());
    }

    /** @return array{TuiSessionState, VirtualTuiHarness, RuntimeEventPoller} */
    private function scope(): array
    {
        $state = new TuiSessionState('42', true);
        $state->handle = new RunHandle('42', 'bootstrapping', 'request');
        $state->sessionReady = false;
        $harness = new VirtualTuiHarness(sessionId: '42');
        $state->replaceTranscript([new TranscriptBlock('previous', TranscriptBlockKindEnum::System, '42', 0, 'Previous mounted view')]);
        $harness->screen()->setTranscriptBlocks($state->transcript);
        /** @var TranscriptProjectorInterface $projector */
        $projector = static::getContainer()->get('tui.session.parent_transcript_projector');
        $logger = new TestLogger();
        $this->logger = $logger;
        $poller = new RuntimeEventPoller(new TuiRuntimeEventApplier($projector, static::getContainer()->get('serializer')), $logger,
            static::getContainer()->get(RuntimeExceptionBoundary::class), $this->createStub(SessionTranscriptProviderInterface::class));

        return [$state, $harness, $poller];
    }

    /** @return array{SessionBootstrapDescriptorDTO, RuntimeEvent, RuntimeEvent, RuntimeEvent} */
    private function transfer(string $runId = '42', string $request = 'request', int $epoch = 3): array
    {
        $resume = (new SessionResumeMetadataProjection())->toArray();
        $resume['usage']['inputTokens'] = 41;
        $resume['queued_messages'] = ['17' => 'Restored pending input'];
        $resume += ['status' => 'completed', 'model' => 'provider/model', 'turn_no' => 2];
        $block = new TranscriptBlock('answer', TranscriptBlockKindEnum::AssistantMessage, $runId, 101, 'Owner-projected answer');
        $serializer = static::getContainer()->get('serializer');
        $bytes = $serializer->serialize(['kind' => 'resume', 'data' => $resume], 'json')."\n";
        $bytes .= '{"kind":"block","data":'.$serializer->serialize($block, 'json')."}\n";
        $cut = new SessionBootstrapDescriptorDTO($runId, str_repeat('a', 32), $epoch, 13, 500, 7, 2, \strlen($bytes), hash('sha256', $bytes));

        return [$cut, new RuntimeEvent('bootstrap.available', $runId, 0, $cut->toArray() + ['request_id' => 'owner-request', 'command_id' => $request]),
            new RuntimeEvent('bootstrap.frame', $runId, 0, ['bootstrap_id' => $cut->bootstrapId, 'view_epoch' => $epoch, 'index' => 0, 'data' => base64_encode($bytes)]),
            new RuntimeEvent('bootstrap.end', $runId, 0, $cut->toArray() + ['frames' => 1])];
    }
}
