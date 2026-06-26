<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\CodingAgent\Compaction\BeforeCompactionHookInterface;
use Ineersa\CodingAgent\Compaction\CompactionHookContextDTO;
use Ineersa\CodingAgent\Compaction\CompactionHookDispatcher;
use Ineersa\CodingAgent\Compaction\CompactionHookResultDTO;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Contract tests for {@see CompactionHookDispatcher} aggregation semantics.
 *
 * Theses:
 *  - No hooks → identity result (continue, no cancel/replacement/instructions).
 *  - Cancel from the first hook wins; later hooks are skipped entirely.
 *  - First non-empty replacement summary wins; later replacement summaries ignored.
 *  - Additional instructions from each hook are appended in registration order.
 *  - Metadata is shallow-merged across hooks; later keys overwrite earlier.
 *  - A hook that throws is logged as a warning and does NOT stop later hooks.
 *  - Replacement summary that is whitespace-only or empty is treated as no replacement.
 */
final class CompactionHookDispatcherTest extends TestCase
{
    public function testNoHooksReturnsIdentityResult(): void
    {
        $dispatcher = new CompactionHookDispatcher([]);
        $result = $dispatcher->dispatch($this->createContext());

        $this->assertNull($result->cancelReason);
        $this->assertNull($result->replacementSummary);
        $this->assertNull($result->additionalInstructions);
        $this->assertSame([], $result->metadata);
        $this->assertFalse($result->cancels());
        $this->assertFalse($result->hasReplacementSummary());
        $this->assertFalse($result->hasAdditionalInstructions());
    }

    public function testCancelShortCircuitsAndSkipsLaterHooks(): void
    {
        $cancelHook = new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return CompactionHookResultDTO::cancel('test reason');
            }
        };

        $lateHook = new class implements BeforeCompactionHookInterface {
            public bool $called = false;

            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                $this->called = true;

                return CompactionHookResultDTO::continue();
            }
        };

        $dispatcher = new CompactionHookDispatcher([$cancelHook, $lateHook]);
        $result = $dispatcher->dispatch($this->createContext());

        $this->assertTrue($result->cancels());
        $this->assertSame('test reason', $result->cancelReason);
        $this->assertFalse($lateHook->called, 'Later hook must not be called after cancel.');
    }

    public function testFirstNonEmptyReplacementSummaryWins(): void
    {
        $firstReplacement = new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return CompactionHookResultDTO::replaceSummary('First replacement summary.');
            }
        };

        $secondReplacement = new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return CompactionHookResultDTO::replaceSummary('Second replacement — should be ignored.');
            }
        };

        $dispatcher = new CompactionHookDispatcher([$firstReplacement, $secondReplacement]);
        $result = $dispatcher->dispatch($this->createContext());

        $this->assertTrue($result->hasReplacementSummary());
        $this->assertSame('First replacement summary.', $result->replacementSummary);
        $this->assertFalse($result->cancels());
    }

    public function testAdditionalInstructionsAppendInOrder(): void
    {
        $hookA = new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return new CompactionHookResultDTO(additionalInstructions: 'Instruction from A.');
            }
        };

        $hookB = new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return new CompactionHookResultDTO(additionalInstructions: 'Instruction from B.');
            }
        };

        $dispatcher = new CompactionHookDispatcher([$hookA, $hookB]);
        $result = $dispatcher->dispatch($this->createContext());

        $this->assertTrue($result->hasAdditionalInstructions());
        $this->assertStringContainsString('Instruction from A.', $result->additionalInstructions);
        $this->assertStringContainsString('Instruction from B.', $result->additionalInstructions);
        // A before B.
        $this->assertLessThan(
            strpos($result->additionalInstructions, 'Instruction from B.'),
            strpos($result->additionalInstructions, 'Instruction from A.'),
        );
    }

    public function testMetadataMergesAcrossHooks(): void
    {
        $hookA = new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return new CompactionHookResultDTO(metadata: ['hook_a' => 1, 'shared' => 'from-a']);
            }
        };

        $hookB = new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return new CompactionHookResultDTO(metadata: ['hook_b' => 2, 'shared' => 'from-b']);
            }
        };

        $dispatcher = new CompactionHookDispatcher([$hookA, $hookB]);
        $result = $dispatcher->dispatch($this->createContext());

        $this->assertSame(1, $result->metadata['hook_a'] ?? null);
        $this->assertSame(2, $result->metadata['hook_b'] ?? null);
        $this->assertSame('from-b', $result->metadata['shared'] ?? null, 'Later hook metadata overwrites earlier for same key.');
    }

    public function testThrownHookIsLoggedAndLaterHooksStillRun(): void
    {
        $throwingHook = new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                throw new \RuntimeException('Boom from hook.');
            }
        };

        $goodHook = new class implements BeforeCompactionHookInterface {
            public bool $called = false;

            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                $this->called = true;

                return new CompactionHookResultDTO(additionalInstructions: 'After the boom.');
            }
        };

        $dispatcher = new CompactionHookDispatcher([$throwingHook, $goodHook]);
        $result = $dispatcher->dispatch($this->createContext());

        $this->assertTrue($goodHook->called, 'Non-throwing hook must still run after a throwing hook.');
        $this->assertStringContainsString('After the boom.', $result->additionalInstructions);
        $this->assertFalse($result->cancels());
    }

    /**
     * Thesis: a replacement summary that is empty or whitespace-only
     * must NOT be treated as a valid replacement (the dispatcher ignores it).
     *
     * This prevents a hook from accidentally providing an empty string
     * that would then produce a context_compacted event with no actual summary.
     */
    public function testEmptyReplacementSummaryIsNotAccepted(): void
    {
        $emptyReplaceHook = new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return new CompactionHookResultDTO(replacementSummary: '   ');
            }
        };

        $dispatcher = new CompactionHookDispatcher([$emptyReplaceHook]);
        $result = $dispatcher->dispatch($this->createContext());

        $this->assertFalse($result->hasReplacementSummary());
        $this->assertNull($result->replacementSummary, 'Whitespace-only replacement must be treated as no replacement.');
    }

    /**
     * Thesis: a hook can cancel AND provide metadata that reaches the
     * failure event payload.
     */
    public function testCancelPreservesMetadata(): void
    {
        $cancelHook = new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return new CompactionHookResultDTO(
                    cancelReason: 'blocked-user',
                    metadata: ['blocked' => true, 'block_reason' => 'rate_limit'],
                );
            }
        };

        $dispatcher = new CompactionHookDispatcher([$cancelHook]);
        $result = $dispatcher->dispatch($this->createContext());

        $this->assertTrue($result->cancels());
        $this->assertSame('blocked-user', $result->cancelReason);
        $this->assertTrue($result->metadata['blocked'] ?? false);
        $this->assertSame('rate_limit', $result->metadata['block_reason'] ?? null);
    }

    private function createContext(): CompactionHookContextDTO
    {
        return new CompactionHookContextDTO(
            runId: 'run-1',
            turnNo: 5,
            trigger: 'manual',
            tokenEstimateBefore: 10000,
            messagesCompacted: 5,
            messagesRetained: 3,
            firstRetainedIndex: 0,
            priorSummaryPresent: false,
            customInstructions: null,
            resolvedModel: 'openai/gpt-4',
            thinkingLevel: null,
        );
    }

    /**
     * Thesis: {@see CompactionHookDispatcher::sanitiseMetadata()} drops
     * objects, resources, and closures while preserving null, scalars, and
     * nested arrays.  This prevents non-serialisable hook metadata from
     * breaking event persistence.
     */
    public function testSanitiseMetadataDropsUnsafeValues(): void
    {
        $dispatcher = new CompactionHookDispatcher([]);

        $fh = \fopen('php://memory', 'r');
        \assert(false !== $fh);

        try {
            $raw = [
                'scalar_int' => 42,
                'scalar_string' => 'hello',
                'scalar_bool' => true,
                'null_val' => null,
                'list' => [1, 2, 3],
                'map' => ['nested' => 'ok', 'deep' => [true, false]],
                'object' => new stdClass(),
                'resource' => $fh,
            ];

            $safe = $dispatcher->sanitiseMetadata($raw);

            $this->assertSame(42, $safe['scalar_int']);
            $this->assertSame('hello', $safe['scalar_string']);
            $this->assertTrue($safe['scalar_bool']);
            $this->assertNull($safe['null_val']);
            $this->assertSame([1, 2, 3], $safe['list']);
            $this->assertSame(['nested' => 'ok', 'deep' => [true, false]], $safe['map']);
            $this->assertArrayNotHasKey('object', $safe, 'stdClass must be dropped.');
            $this->assertArrayNotHasKey('resource', $safe, 'resource must be dropped.');
        } finally {
            \fclose($fh);
        }
    }

    /**
     * Thesis: {@see CompactionHookDispatcher::sanitiseMetadata()} preserves
     * non-string keys in nested arrays (lists) but drops non-string top-level
     * keys so the output is always a string-keyed map.
     */
    public function testSanitiseMetadataDropsNonStringTopLevelKeys(): void
    {
        $dispatcher = new CompactionHookDispatcher([]);

        $safe = $dispatcher->sanitiseMetadata([
            0 => 'zero',
            'key' => 'value',
            1 => 'one',
        ]);

        // Non-string top-level keys are skipped; only string keys survive.
        $this->assertArrayNotHasKey(0, $safe);
        $this->assertArrayNotHasKey(1, $safe);
        $this->assertSame('value', $safe['key']);
    }
}
