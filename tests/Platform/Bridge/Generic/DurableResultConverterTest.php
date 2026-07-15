<?php

declare(strict_types=1);

namespace Ineersa\Platform\Tests\Bridge\Generic;

use Ineersa\Platform\Bridge\Generic\DurableResultConverter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException as PlatformRuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\FinishReason\FinishReasonCase;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\Stream\Delta\MetadataDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests for {@see DurableResultConverter} — durable streaming tool-call conversion
 * using dual-map (stream index + tool-call id) tracking.
 */
#[CoversClass(DurableResultConverter::class)]
#[AllowMockObjectsWithoutExpectations]
final class DurableResultConverterTest extends TestCase
{
    private DurableResultConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DurableResultConverter();
    }

    // ── Single valid tool call ────────────────────────────────────────────────

    #[Test]
    public function streamOptionRoutesToStreamPath(): void
    {
        $result = $this->streamResult([
            $this->chunk(['choices' => [['finish_reason' => 'stop']]]),
        ]);

        $options = ['stream' => true];
        // Verify the option is set correctly
        $this->assertTrue($options['stream'] ?? false);

        $converted = $this->converter->convert($result, $options);
        $this->assertInstanceOf(StreamResult::class, $converted, 'Should return StreamResult for stream=true');
    }

    #[Test]
    public function convertsSingleValidToolCall(): void
    {
        $deltas = $this->collectStream($this->streamResult([
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'id' => 'call_abc',
                    'function' => ['name' => 'bash'],
                ]]],
            ]]]),
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'function' => ['arguments' => '{"command":"ls"}'],
                ]]],
            ]]]),
            $this->chunk(['choices' => [['finish_reason' => 'tool_calls']]]),
        ]));

        $this->assertInstanceOf(ToolCallStart::class, $deltas[0]);
        $this->assertSame('call_abc', $deltas[0]->getId());
        $this->assertSame('bash', $deltas[0]->getName());

        $this->assertInstanceOf(ToolInputDelta::class, $deltas[1]);
        $this->assertSame('call_abc', $deltas[1]->getId());
        $this->assertSame('{"command":"ls"}', $deltas[1]->getPartialJson());

        $this->assertInstanceOf(ToolCallComplete::class, $deltas[2]);
        $toolCalls = $deltas[2]->getToolCalls();
        $this->assertCount(1, $toolCalls);
        $this->assertSame('call_abc', $toolCalls[0]->getId());
        $this->assertSame('bash', $toolCalls[0]->getName());
        $this->assertSame(['command' => 'ls'], $toolCalls[0]->getArguments());
    }

    // ── Parallel tool calls (interleaved by index) ────────────────────────────

    #[Test]
    public function convertsInterleavedParallelToolCalls(): void
    {
        $deltas = $this->collectStream($this->streamResult([
            // Tool 0: ID chunk
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'id' => 'call_aaa',
                    'function' => ['name' => 'bash'],
                ]]],
            ]]]),
            // Tool 1: ID chunk
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 1,
                    'id' => 'call_bbb',
                    'function' => ['name' => 'read'],
                ]]],
            ]]]),
            // Tool 1: args
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 1,
                    'function' => ['arguments' => '{"path":"/tmp"}'],
                ]]],
            ]]]),
            // Tool 0: args
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'function' => ['arguments' => '{"command":"ls"}'],
                ]]],
            ]]]),
            $this->chunk(['choices' => [['finish_reason' => 'tool_calls']]]),
        ]));

        // Collect the ToolCallComplete
        $complete = null;
        foreach ($deltas as $delta) {
            if ($delta instanceof ToolCallComplete) {
                $complete = $delta;
            }
        }

        $this->assertNotNull($complete, 'Expected ToolCallComplete in stream');

        $toolCalls = $complete->getToolCalls();
        $this->assertCount(2, $toolCalls);

        // Find by ID
        $byId = [];
        foreach ($toolCalls as $tc) {
            $byId[$tc->getId()] = $tc;
        }

        $this->assertArrayHasKey('call_aaa', $byId);
        $this->assertArrayHasKey('call_bbb', $byId);
        $this->assertSame('bash', $byId['call_aaa']->getName());
        $this->assertSame(['command' => 'ls'], $byId['call_aaa']->getArguments());
        $this->assertSame('read', $byId['call_bbb']->getName());
        $this->assertSame(['path' => '/tmp'], $byId['call_bbb']->getArguments());
    }

    // ── Empty-ID chunks are suppressed ────────────────────────────────────────

    #[Test]
    public function suppressesEmptyIdToolCallStart(): void
    {
        $deltas = $this->collectStream($this->streamResult([
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'id' => '',
                    'function' => ['name' => 'phantom'],
                ]]],
            ]]]),
            $this->chunk(['choices' => [['finish_reason' => 'stop']]]),
        ]));

        // No ToolCallStart or ToolCallComplete should appear for empty-ID starts
        $hasToolCallStart = false;
        $hasToolCallComplete = false;
        foreach ($deltas as $delta) {
            if ($delta instanceof ToolCallStart) {
                $hasToolCallStart = true;
            }
            if ($delta instanceof ToolCallComplete) {
                $hasToolCallComplete = true;
            }
        }

        $this->assertFalse($hasToolCallStart, 'Empty-ID ToolCallStart should be suppressed');
        $this->assertFalse($hasToolCallComplete, 'No ToolCallComplete for empty-ID-only blocks');
    }

    #[Test]
    public function suppressesEmptyIdToolInputDelta(): void
    {
        $deltas = $this->collectStream($this->streamResult([
            // Argument chunk at index 0 without any prior ID chunk
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'function' => ['arguments' => '{"orphan":true}'],
                ]]],
            ]]]),
            $this->chunk(['choices' => [['finish_reason' => 'stop']]]),
        ]));

        $toolInputDeltas = [];
        foreach ($deltas as $delta) {
            if ($delta instanceof ToolInputDelta) {
                $toolInputDeltas[] = $delta;
            }
        }

        $this->assertCount(0, $toolInputDeltas, 'Orphan argument deltas without ID should be suppressed');
    }

    // ── Phantom started-but-never-completed ───────────────────────────────────

    #[Test]
    public function excludesPhantomStartedButNeverCompleted(): void
    {
        $deltas = $this->collectStream($this->streamResult([
            // Phantom tool call with empty ID at index 0 (the actual #124 scenario)
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'id' => '',
                    'function' => ['name' => 'read'],
                ]]],
            ]]]),
            // Real tool call at index 1
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 1,
                    'id' => 'call_real',
                    'function' => ['name' => 'bash'],
                ]]],
            ]]]),
            // Real tool args
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 1,
                    'function' => ['arguments' => '{"command":"ls"}'],
                ]]],
            ]]]),
            $this->chunk(['choices' => [['finish_reason' => 'tool_calls']]]),
        ]));

        $complete = null;
        foreach ($deltas as $delta) {
            if ($delta instanceof ToolCallComplete) {
                $complete = $delta;
            }
        }

        $this->assertNotNull($complete);

        $ids = array_map(static fn (ToolCall $tc): string => $tc->getId(), $complete->getToolCalls());

        // Only the real call should appear; the empty-id phantom was never completed
        $this->assertContains('call_real', $ids);
        $this->assertNotContains('call_phantom', $ids);
        $this->assertCount(1, $ids, 'Only the call with a non-empty id should appear in ToolCallComplete');
    }

    // ── Arguments before ID (buffered and replayed) ───────────────────────────

    #[Test]
    public function replaysBufferedArgumentsWhenIdArrivesLater(): void
    {
        $deltas = $this->collectStream($this->streamResult([
            // Arguments arrive first (no ID yet)
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'function' => ['arguments' => '{"comm'],
                ]]],
            ]]]),
            // More arguments
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'function' => ['arguments' => 'and":"ls"}'],
                ]]],
            ]]]),
            // ID arrives later
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'id' => 'call_late',
                    'function' => ['name' => 'bash'],
                ]]],
            ]]]),
            $this->chunk(['choices' => [['finish_reason' => 'tool_calls']]]),
        ]));

        // The first ToolCallStart should appear when the ID arrives
        $starts = array_filter($deltas, static fn ($d) => $d instanceof ToolCallStart);
        $this->assertCount(1, $starts);
        $start = reset($starts);
        $this->assertSame('call_late', $start->getId());
        $this->assertSame('bash', $start->getName());

        // The complete should have the accumulated arguments
        $complete = null;
        foreach ($deltas as $delta) {
            if ($delta instanceof ToolCallComplete) {
                $complete = $delta;
            }
        }
        $this->assertNotNull($complete);

        $toolCalls = $complete->getToolCalls();
        $this->assertCount(1, $toolCalls);
        $this->assertSame('call_late', $toolCalls[0]->getId());
        $this->assertSame(['command' => 'ls'], $toolCalls[0]->getArguments());
    }

    // ── Text deltas pass through unchanged ────────────────────────────────────

    #[Test]
    public function textDeltasPassThroughUnchanged(): void
    {
        $deltas = $this->collectStream($this->streamResult([
            $this->chunk(['choices' => [[
                'delta' => ['content' => 'Hello'],
            ]]]),
            $this->chunk(['choices' => [[
                'delta' => ['content' => ' World'],
            ]]]),
            $this->chunk(['choices' => [['finish_reason' => 'stop']]]),
        ]));

        $textDeltas = array_filter($deltas, static fn ($d) => $d instanceof TextDelta);
        $this->assertCount(2, $textDeltas);

        $texts = array_map(static fn (TextDelta $td): string => $td->getText(), array_values($textDeltas));
        $this->assertSame(['Hello', ' World'], $texts);
    }

    // ── Empty-argument ToolCallComplete suppressed ─────────────────────────────

    #[Test]
    public function toolCallCompleteExcludesEmptyIdAndNameBlocks(): void
    {
        $deltas = $this->collectStream($this->streamResult([
            // Anonymous block (no ID)
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'function' => ['arguments' => '{}'],
                ]]],
            ]]]),
            // Valid block
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 1,
                    'id' => 'call_ok',
                    'function' => ['name' => 'bash', 'arguments' => '{"command":"ls"}'],
                ]]],
            ]]]),
            $this->chunk(['choices' => [['finish_reason' => 'tool_calls']]]),
        ]));

        $complete = null;
        foreach ($deltas as $delta) {
            if ($delta instanceof ToolCallComplete) {
                $complete = $delta;
            }
        }
        $this->assertNotNull($complete);

        $toolCalls = $complete->getToolCalls();
        $this->assertCount(1, $toolCalls, 'Anonymous block without ID+name should be excluded');
        $this->assertSame('call_ok', $toolCalls[0]->getId());
    }

    // ── Cross-index ID re-association ─────────────────────────────────────────

    #[Test]
    public function reassociatesByIdWhenIndexChanges(): void
    {
        $deltas = $this->collectStream($this->streamResult([
            // Tool starts at index 0
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'id' => 'call_xyz',
                    'function' => ['name' => 'bash'],
                ]]],
            ]]]),
            // Arguments arrive at index 1 (different index, same id in a different chunk)
            // Real scenario: LLM might change index ordering in streaming
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 1,
                    'id' => 'call_xyz',
                    'function' => ['arguments' => '{"command":"re"}'],
                ]]],
            ]]]),
            $this->chunk(['choices' => [['finish_reason' => 'tool_calls']]]),
        ]));

        $complete = null;
        foreach ($deltas as $delta) {
            if ($delta instanceof ToolCallComplete) {
                $complete = $delta;
            }
        }
        $this->assertNotNull($complete);

        $toolCalls = $complete->getToolCalls();
        $this->assertCount(1, $toolCalls);
        $this->assertSame('call_xyz', $toolCalls[0]->getId());
        $this->assertSame('bash', $toolCalls[0]->getName());
        $this->assertSame(['command' => 're'], $toolCalls[0]->getArguments());
    }

    // ── Raw stream capture (optional onStreamEvent closure) ──────────────────

    #[Test]
    public function captureIsDisabledByDefault(): void
    {
        $converter = new DurableResultConverter();

        $result = $this->streamResult([
            $this->chunk(['choices' => [['finish_reason' => 'stop']]]),
        ]);

        // Finish-only chunk still yields normalized finish_reason metadata (Symfony AI v0.11).
        $deltas = $this->collectStreamWithConverter($converter, $result);
        $this->assertCount(1, $deltas);
        $this->assertInstanceOf(MetadataDelta::class, $deltas[0]);
        $this->assertSame('finish_reason', $deltas[0]->getKey());
    }

    #[Test]
    public function captureEnabledRecordsRawChunks(): void
    {
        $events = [];
        $collector = static function (string $event, int $ordinal, array $context) use (&$events): void {
            $events[] = ['event' => $event, 'ordinal' => $ordinal] + $context;
        };
        $converter = new DurableResultConverter($collector);

        $deltas = $this->collectStreamWithConverter($converter, $this->streamResult([
            $this->chunk(['choices' => [[
                'delta' => ['content' => 'Hello'],
            ]]]),
            $this->chunk(['choices' => [[
                'delta' => ['content' => ' World'],
            ]]]),
            $this->chunk(['choices' => [['finish_reason' => 'stop']]]),
        ]));

        // Should have captured raw chunks
        $rawChunks = array_values(array_filter(
            $events,
            static fn (array $e): bool => 'raw_chunk' === $e['event'],
        ));

        $this->assertCount(3, $rawChunks, 'Should record a raw_chunk for each SSE chunk');
        $this->assertSame(0, $rawChunks[0]['ordinal']);
        $this->assertSame(1, $rawChunks[1]['ordinal']);
        $this->assertSame(2, $rawChunks[2]['ordinal']);
        $this->assertArrayHasKey('data', $rawChunks[0]);
        $this->assertSame('Hello', $rawChunks[0]['data']['choices'][0]['delta']['content']);

        // Should have start and end markers
        $starts = array_filter($events, static fn (array $e): bool => 'capture_start' === $e['event']);
        $ends = array_filter($events, static fn (array $e): bool => 'capture_end' === $e['event']);
        $this->assertCount(1, $starts);
        $this->assertCount(1, $ends);

        $this->assertCount(3, $deltas);
        $this->assertInstanceOf(TextDelta::class, $deltas[0]);
        $this->assertSame('Hello', $deltas[0]->getText());
        $this->assertInstanceOf(TextDelta::class, $deltas[1]);
        $this->assertInstanceOf(MetadataDelta::class, $deltas[2]);
    }

    #[Test]
    public function captureEnabledRecordsConvertedDeltas(): void
    {
        $events = [];
        $collector = static function (string $event, int $ordinal, array $context) use (&$events): void {
            $events[] = ['event' => $event, 'ordinal' => $ordinal] + $context;
        };
        $converter = new DurableResultConverter($collector);

        $deltas = $this->collectStreamWithConverter($converter, $this->streamResult([
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'id' => 'call_1',
                    'function' => ['name' => 'read'],
                ]]],
            ]]]),
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'function' => ['arguments' => '{"path":"./file.txt"}'],
                ]]],
            ]]]),
            $this->chunk(['choices' => [['finish_reason' => 'tool_calls']]]),
        ]));

        // Extract converted_delta events
        $convDeltas = array_values(array_filter(
            $events,
            static fn (array $e): bool => 'converted_delta' === $e['event'],
        ));

        // Chunk 0: ToolCallStart + ToolInputDelta (buffered args replayed)
        // Chunk 1: (tool calls accumulated, no new yields from yieldDurableToolCallDeltas)
        //   Actually chunk 1 has no ID, and the block already has an ID, so it yields ToolInputDelta
        // Chunk 2: ToolCallComplete
        $this->assertGreaterThanOrEqual(2, \count($convDeltas), 'Should record converted deltas');

        // Find the ToolCallStart
        $starts = array_values(array_filter(
            $convDeltas,
            static fn (array $e): bool => 'ToolCallStart' === ($e['type'] ?? ''),
        ));
        $this->assertCount(1, $starts);
        $this->assertSame('call_1', $starts[0]['id']);
        $this->assertSame('read', $starts[0]['name']);

        // Deltas should be unchanged
        $this->assertInstanceOf(ToolCallStart::class, $deltas[0]);
        $this->assertSame('call_1', $deltas[0]->getId());
    }

    #[Test]
    public function emittedDeltasUnchangedWithCapture(): void
    {
        // Compare deltas from converters with and without capture closure
        $events = [];
        $collector = static function (string $event, int $ordinal, array $context) use (&$events): void {
            $events[] = $event;
        };
        $captureConverter = new DurableResultConverter($collector);
        $defaultConverter = new DurableResultConverter();

        $chunks = [
            $this->chunk(['choices' => [[
                'delta' => ['content' => 'Hello'],
            ]]]),
            $this->chunk(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'id' => 'call_x',
                    'function' => ['name' => 'bash', 'arguments' => '{}'],
                ]]],
            ]]]),
            $this->chunk(['choices' => [['finish_reason' => 'tool_calls']]]),
        ];

        $captureResult = $this->streamResult($chunks);
        $defaultResult = $this->streamResult($chunks);

        $captureDeltas = $this->collectStreamWithConverter($captureConverter, $captureResult);
        $defaultDeltas = $this->collectStreamWithConverter($defaultConverter, $defaultResult);

        // Same number of deltas
        $this->assertCount(\count($defaultDeltas), $captureDeltas);

        // Same types and key properties
        foreach ($defaultDeltas as $i => $expected) {
            $actual = $captureDeltas[$i];
            $this->assertInstanceOf($expected::class, $actual);
        }

        // Capture did fire events
        $this->assertGreaterThan(0, \count($events));
    }

    // ── Stream ended without finish reason ────────────────────────────────────

    #[Test]
    public function throwsExceptionWhenStreamEndsWithoutFinishReason(): void
    {
        $result = $this->streamResult([
            // Send a text chunk (sets sawChunk = true) but no finish_reason.
            $this->chunk(['choices' => [[
                'delta' => ['content' => 'partial text'],
            ]]]),
        ]);

        $this->expectException(IncompleteStreamException::class);
        $this->expectExceptionMessage('Completions stream ended before a finish reason was received.');

        $this->collectStream($result);
    }

    // ── Token usage with cache fields ─────────────────────────────────────────

    #[Test]
    public function extractsOpenAiPromptTokensDetailsCachedTokens(): void
    {
        // OpenAI / z.ai Chat Completions format:
        // usage.prompt_tokens_details.cached_tokens
        $deltas = $this->collectStream($this->streamResult([
            $this->chunk(['choices' => [[
                'delta' => ['content' => 'Hello'],
            ]]]),
            $this->chunk([
                'choices' => [['finish_reason' => 'stop']],
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 50,
                    'prompt_tokens_details' => [
                        'cached_tokens' => 78,
                    ],
                    'total_tokens' => 150,
                ],
            ]),
        ]));

        // Last delta should be a TokenUsage with the cache fields populated.
        $last = $this->lastTokenUsageDelta($deltas);
        $this->assertSame(100, $last->getPromptTokens());
        $this->assertSame(50, $last->getCompletionTokens());
        $this->assertSame(78, $last->getCachedTokens());
        $this->assertSame(78, $last->getCacheReadTokens(), 'cache_read_tokens must match prompt_tokens_details.cached_tokens');
        $this->assertNull($last->getCacheCreationTokens(), 'cache_creation_tokens must not be inferred');
        $this->assertSame(150, $last->getTotalTokens());
    }

    #[Test]
    public function extractsDeepSeekPromptCacheHitTokens(): void
    {
        // DeepSeek format:
        // usage.prompt_cache_hit_tokens (top-level)
        $deltas = $this->collectStream($this->streamResult([
            $this->chunk(['choices' => [[
                'delta' => ['content' => 'Hello'],
            ]]]),
            $this->chunk([
                'choices' => [['finish_reason' => 'stop']],
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 50,
                    'prompt_cache_hit_tokens' => 60,
                    'total_tokens' => 150,
                ],
            ]),
        ]));

        $last = $this->lastTokenUsageDelta($deltas);
        $this->assertSame(100, $last->getPromptTokens());
        $this->assertSame(50, $last->getCompletionTokens());
        $this->assertSame(60, $last->getCacheReadTokens(), 'cache_read_tokens must match prompt_cache_hit_tokens');
        // DeepSeek: prompt_cache_hit_tokens also populates cachedTokens
        // (aggregate) so cost calculation and footer fallbacks work.
        $this->assertSame(60, $last->getCachedTokens(), 'cached_tokens falls back to cache_read when no separate aggregate field');
    }

    #[Test]
    public function extractsLegacyNumCachedTokens(): void
    {
        // Existing generic format: usage.num_cached_tokens
        $deltas = $this->collectStream($this->streamResult([
            $this->chunk(['choices' => [[
                'delta' => ['content' => 'Hello'],
            ]]]),
            $this->chunk([
                'choices' => [['finish_reason' => 'stop']],
                'usage' => [
                    'prompt_tokens' => 50,
                    'completion_tokens' => 25,
                    'num_cached_tokens' => 30,
                    'total_tokens' => 75,
                ],
            ]),
        ]));

        $last = $this->lastTokenUsageDelta($deltas);
        $this->assertSame(50, $last->getPromptTokens());
        $this->assertSame(25, $last->getCompletionTokens());
        $this->assertSame(30, $last->getCachedTokens());
        $this->assertNull($last->getCacheReadTokens(), 'upstream extractor maps num_cached_tokens to cachedTokens only');
    }

    #[Test]
    public function extractsThinkingTokensFromCompletionTokensDetails(): void
    {
        $deltas = $this->collectStream($this->streamResult([
            $this->chunk(['choices' => [[
                'delta' => ['content' => 'Hello'],
            ]]]),
            $this->chunk([
                'choices' => [['finish_reason' => 'stop']],
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 50,
                    'completion_tokens_details' => [
                        'reasoning_tokens' => 20,
                    ],
                    'total_tokens' => 150,
                ],
            ]),
        ]));

        $last = $this->lastTokenUsageDelta($deltas);
        $this->assertSame(20, $last->getThinkingTokens());
    }

    #[Test]
    public function yieldsFinishReasonMetadataDelta(): void
    {
        $deltas = $this->collectStream($this->streamResult([
            $this->chunk(['choices' => [[
                'delta' => ['content' => 'Hello'],
            ]]]),
            $this->chunk(['choices' => [[
                'finish_reason' => 'stop',
            ]]]),
        ]));

        $last = end($deltas);
        $this->assertInstanceOf(MetadataDelta::class, $last);
        $this->assertSame('finish_reason', $last->getKey());
        $value = $last->getValue();
        $this->assertInstanceOf(FinishReason::class, $value);
        $this->assertSame(FinishReasonCase::STOP, $value->getCase());
        $this->assertSame('stop', $value->getRaw());
    }

    // ── HTTP status conversion (streaming path) ─────────────────────────────

    #[Test]
    public function streamingConvertThrowsAuthenticationExceptionOn401(): void
    {
        $result = $this->streamResultWithStatus(401, '{"error":{"message":"Invalid API key"}}', []);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid API key');

        $this->converter->convert($result, ['stream' => true]);
    }

    #[Test]
    public function streamingConvertThrowsRateLimitExceededExceptionOn429(): void
    {
        $result = $this->streamResultWithStatus(429, '{"error":{"message":"rate limited"}}', []);

        $this->expectException(RateLimitExceededException::class);

        $this->converter->convert($result, ['stream' => true]);
    }

    #[Test]
    public function streamingConvertThrowsServerExceptionOn500(): void
    {
        $result = $this->streamResultWithStatus(500, '{"error":{"message":"upstream down"}}', []);

        $this->expectException(ServerException::class);

        $this->converter->convert($result, ['stream' => true]);
    }

    #[Test]
    public function streamingConvertThrowsRuntimeExceptionOnGeneric4xx(): void
    {
        $result = $this->streamResultWithStatus(403, '{"error":{"message":"forbidden"}}', []);

        $this->expectException(PlatformRuntimeException::class);
        $this->expectExceptionMessage('Unexpected response code 403');

        $this->converter->convert($result, ['stream' => true]);
    }

    #[Test]
    public function streamingConvertThrowsBadRequestExceptionOn400(): void
    {
        $result = $this->streamResultWithStatus(400, '{"error":{"message":"Bad Request: invalid"}}', []);

        $this->expectException(BadRequestException::class);

        $this->converter->convert($result, ['stream' => true]);
    }

    // ── Stream payload error classification ───────────────────────────────────

    #[Test]
    public function streamErrorChunkRateLimitRaisesRateLimitExceededException(): void
    {
        $this->expectException(RateLimitExceededException::class);

        $this->collectStream($this->streamResult([
            $this->chunk(['error' => ['message' => 'Too many requests', 'code' => 'rate_limit_exceeded']]),
        ]));
    }

    #[Test]
    public function streamErrorChunkServerErrorRaisesServerException(): void
    {
        $this->expectException(ServerException::class);

        $this->collectStream($this->streamResult([
            $this->chunk(['error' => ['message' => 'Internal error', 'type' => 'server_error']]),
        ]));
    }

    #[Test]
    public function streamErrorChunkGenericRaisesRuntimeException(): void
    {
        $this->expectException(PlatformRuntimeException::class);
        $this->expectExceptionMessage('Stream error');

        $this->collectStream($this->streamResult([
            $this->chunk(['error' => ['message' => 'Something broke']]),
        ]));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $chunks
     */
    private function streamResultWithStatus(int $statusCode, string $body, array $chunks): RawHttpResult
    {
        $response = new class($statusCode, $body) implements ResponseInterface {
            public function __construct(private int $statusCode, private string $body)
            {
            }

            public function getStatusCode(): int
            {
                return $this->statusCode;
            }

            public function getHeaders(bool $throw = true): array
            {
                return [];
            }

            public function getContent(bool $throw = true): string
            {
                return $this->body;
            }

            public function toArray(bool $throw = true): array
            {
                $decoded = json_decode($this->body, true);

                return \is_array($decoded) ? $decoded : [];
            }

            public function cancel(): void
            {
            }

            public function getInfo(?string $type = null): mixed
            {
                return null;
            }
        };

        return new RawHttpResult(
            $response,
            new class($chunks) implements \Symfony\AI\Platform\Result\Stream\HttpStreamInterface {
                /** @param list<array<string, mixed>> $chunks */
                public function __construct(private readonly array $chunks)
                {
                }

                public function stream(ResponseInterface $response): iterable
                {
                    foreach ($this->chunks as $chunk) {
                        yield $chunk;
                    }
                }
            },
        );
    }

    /**
     * Create a RawHttpResult configured for streaming mode, with a mock HTTP 200 response.
     *
     * @param list<array<string, mixed>> $chunks Raw SSE data chunks representing
     *                                           individual SSE "data:" payloads
     */
    private function streamResult(array $chunks): RawHttpResult
    {
        // Use a hand-crafted anonymous ResponseInterface because PHPUnit
        // mocks for ResponseInterface add internal state that interferes
        // with the converter's error-handling path when status code is 200.
        $response = new class implements ResponseInterface {
            public function getStatusCode(): int
            {
                return 200;
            }

            public function getHeaders(bool $throw = true): array
            {
                return [];
            }

            public function getContent(bool $throw = true): string
            {
                return '';
            }

            public function toArray(bool $throw = true): array
            {
                return ['choices' => []];
            }

            public function cancel(): void
            {
            }

            public function getInfo(?string $type = null): mixed
            {
                return null;
            }
        };

        // Return chunked data simulating SSE -> getDataStream().
        return new RawHttpResult(
            $response,
            new class($chunks) implements \Symfony\AI\Platform\Result\Stream\HttpStreamInterface {
                /** @param list<array<string, mixed>> $chunks */
                public function __construct(private readonly array $chunks)
                {
                }

                public function stream(ResponseInterface $response): iterable
                {
                    foreach ($this->chunks as $chunk) {
                        yield $chunk;
                    }
                }
            },
        );
    }

    /**
     * Wrapper for a raw SSE data chunk array.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function chunk(array $data): array
    {
        return $data;
    }

    /**
     * @param list<object> $deltas
     */
    private function lastTokenUsageDelta(array $deltas): \Symfony\AI\Platform\TokenUsage\TokenUsage
    {
        for ($i = \count($deltas) - 1; $i >= 0; --$i) {
            if ($deltas[$i] instanceof \Symfony\AI\Platform\TokenUsage\TokenUsage) {
                return $deltas[$i];
            }
        }

        $this->fail('Expected a TokenUsage delta in stream output');
    }

    /**
     * Drain a stream through the given converter and return yielded deltas.
     *
     * @return list<object>
     */
    private function collectStreamWithConverter(DurableResultConverter $converter, RawHttpResult $rawResult): array
    {
        $result = $converter->convert($rawResult, ['stream' => true]);
        $this->assertInstanceOf(StreamResult::class, $result);

        return iterator_to_array($result->getContent(), false);
    }

    /**
     * Collect all deltas from a stream result.
     *
     * @return list<object>
     */
    private function collectStream(RawHttpResult $rawResult): array
    {
        return $this->collectStreamWithConverter($this->converter, $rawResult);
    }
}
