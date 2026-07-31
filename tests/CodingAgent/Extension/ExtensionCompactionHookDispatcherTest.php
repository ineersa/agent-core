<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Compaction\CompactionHookContextDTO;
use Ineersa\CodingAgent\Compaction\CompactionHookDispatcher;
use Ineersa\CodingAgent\Extension\ExtensionCompactionHookDispatcher;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookResultDTO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Thesis: CompactRun public extension hooks receive 1..lastSeq watermark, can replace/cancel,
 * and exceptions fail closed (no silent summary fallback).
 */
final class ExtensionCompactionHookDispatcherTest extends TestCase
{
    public function testPublicHookReceivesRequiredWatermarkAndCanReplace(): void
    {
        $registry = new ExtensionHookRegistry();
        $captured = null;
        $registry->addBeforeCompactionHook(new class($captured) implements BeforeCompactionHookInterface {
            public function __construct(private mixed &$captured)
            {
            }

            public function beforeCompaction(BeforeCompactionHookContextDTO $context): BeforeCompactionHookResultDTO
            {
                $this->captured = $context;

                return BeforeCompactionHookResultDTO::replaceSummary('OM summary');
            }
        });

        $dispatcher = new ExtensionCompactionHookDispatcher(
            $registry,
            new CompactionHookDispatcher([]),
            new NullLogger(),
        );

        $result = $dispatcher->dispatchForCompactRun(
            internalContext: $this->context(),
            requiredStartSeq: 1,
            requiredEndSeq: 42,
        );

        $this->assertTrue($result->hasReplacementSummary());
        $this->assertSame('OM summary', $result->replacementSummary);
        $this->assertInstanceOf(BeforeCompactionHookContextDTO::class, $captured);
        $this->assertSame(1, $captured->requiredStartSeq);
        $this->assertSame(42, $captured->requiredEndSeq);
    }

    public function testPublicHookExceptionFailsClosedAsCancel(): void
    {
        $registry = new ExtensionHookRegistry();
        $registry->addBeforeCompactionHook(new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(BeforeCompactionHookContextDTO $context): BeforeCompactionHookResultDTO
            {
                throw new \RuntimeException('boom');
            }
        });

        $dispatcher = new ExtensionCompactionHookDispatcher(
            $registry,
            new CompactionHookDispatcher([]),
            new NullLogger(),
        );

        $result = $dispatcher->dispatchForCompactRun(
            internalContext: $this->context(),
            requiredStartSeq: 1,
            requiredEndSeq: 7,
        );

        $this->assertTrue($result->cancels());
        $this->assertStringContainsString('extension_hook_failed', (string) $result->cancelReason);
        $this->assertFalse($result->hasReplacementSummary());
    }

    public function testSnapshotDispatchUsesNullWatermarkOnSamePublicHookSet(): void
    {
        $registry = new ExtensionHookRegistry();
        $captured = null;
        $seen = [];
        $registry->addBeforeCompactionHook(new class($captured, $seen) implements BeforeCompactionHookInterface {
            /**
             * @param list<bool> $seen
             */
            public function __construct(private mixed &$captured, private array &$seen)
            {
            }

            public function beforeCompaction(BeforeCompactionHookContextDTO $context): BeforeCompactionHookResultDTO
            {
                $this->seen[] = $context->hasCoverageWatermark();
                $this->captured = $context;

                return BeforeCompactionHookResultDTO::replaceSummary('snapshot OM summary');
            }
        });

        $dispatcher = new ExtensionCompactionHookDispatcher(
            $registry,
            new CompactionHookDispatcher([]),
            new NullLogger(),
        );

        $result = $dispatcher->dispatchForSnapshot($this->context(trigger: 'fork'));

        $this->assertSame([false], $seen);
        $this->assertTrue($result->hasReplacementSummary());
        $this->assertSame('snapshot OM summary', $result->replacementSummary);
        $this->assertInstanceOf(BeforeCompactionHookContextDTO::class, $captured);
        $this->assertSame('fork', $captured->trigger);
        $this->assertSame('run-1', $captured->runId);
        $this->assertNull($captured->requiredStartSeq);
        $this->assertNull($captured->requiredEndSeq);
        $this->assertFalse($captured->hasCoverageWatermark());
    }

    public function testSnapshotHookExceptionFailsClosedAsCancel(): void
    {
        $registry = new ExtensionHookRegistry();
        $registry->addBeforeCompactionHook(new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(BeforeCompactionHookContextDTO $context): BeforeCompactionHookResultDTO
            {
                throw new \RuntimeException('boom');
            }
        });

        $dispatcher = new ExtensionCompactionHookDispatcher(
            $registry,
            new CompactionHookDispatcher([]),
            new NullLogger(),
        );

        $result = $dispatcher->dispatchForSnapshot($this->context(trigger: 'fork'));

        $this->assertTrue($result->cancels());
        $this->assertStringContainsString('extension_hook_failed', (string) $result->cancelReason);
        $this->assertFalse($result->hasReplacementSummary());
    }

    private function context(string $trigger = 'manual'): CompactionHookContextDTO
    {
        return new CompactionHookContextDTO(
            runId: 'run-1',
            turnNo: 3,
            trigger: $trigger,
            tokenEstimateBefore: 1000,
            messagesCompacted: 5,
            messagesRetained: 2,
            firstRetainedIndex: 5,
            priorSummaryPresent: false,
            customInstructions: null,
            resolvedModel: 'provider/model',
            thinkingLevel: null,
        );
    }
}
