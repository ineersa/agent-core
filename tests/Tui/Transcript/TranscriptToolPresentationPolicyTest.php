<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Transcript\SubagentResultRenderer;
use Ineersa\Tui\Transcript\TranscriptToolPresentationPolicy;
use Ineersa\Tui\Transcript\TranscriptToolResultFacts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behavior proof for {@see TranscriptToolPresentationPolicy}: exchange pairing
 * candidate scoring/selection, tool-name compatibility, and suppression rules.
 */
final class TranscriptToolPresentationPolicyTest extends TestCase
{
    private TranscriptToolPresentationPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new TranscriptToolPresentationPolicy(
            new SubagentResultRenderer(),
            new TranscriptToolResultFacts(),
        );
    }

    #[Test]
    public function fullRenderCandidateBeatsLongerNonErrorCandidate(): void
    {
        $call = $this->callBlock('c1', 'edit', seq: 1);
        $errorCandidate = $this->resultBlock('r-err', 'c1', seq: 2, meta: ['tool_name' => 'edit', 'is_error' => true, 'result' => 'boom']);
        $longCandidate = $this->resultBlock('r-long', 'c1', seq: 3, meta: ['tool_name' => 'edit', 'result' => str_repeat('x', 300)]);

        $selected = $this->policy->findCombinableToolResultForCall(
            $call,
            ['c1' => [$errorCandidate, $longCandidate]],
            [],
            [],
        );

        $this->assertSame('r-err', $selected?->id, 'is_error full-render bonus (+1000) must dominate body length');
    }

    #[Test]
    public function nonStreamingCandidateBeatsStreamingEqualCandidate(): void
    {
        $call = $this->callBlock('c1', 'edit', seq: 1);
        $streaming = $this->resultBlock('r-stream', 'c1', seq: 5, meta: ['tool_name' => 'edit', 'result' => 'same body'], streaming: true);
        $settled = $this->resultBlock('r-settled', 'c1', seq: 5, meta: ['tool_name' => 'edit', 'result' => 'same body']);

        $selected = $this->policy->findCombinableToolResultForCall(
            $call,
            ['c1' => [$streaming, $settled]],
            [],
            [],
        );

        $this->assertSame('r-settled', $selected?->id, 'streaming penalty (-50) must lose to an equal settled candidate');
    }

    #[Test]
    public function higherSeqBreaksTieBetweenOtherwiseEqualCandidates(): void
    {
        $call = $this->callBlock('c1', 'edit', seq: 1);
        $older = $this->resultBlock('r-old', 'c1', seq: 3, meta: ['tool_name' => 'edit', 'result' => 'same body']);
        $newer = $this->resultBlock('r-new', 'c1', seq: 7, meta: ['tool_name' => 'edit', 'result' => 'same body']);

        $selected = $this->policy->findCombinableToolResultForCall(
            $call,
            ['c1' => [$older, $newer]],
            [],
            [],
        );

        $this->assertSame('r-new', $selected?->id, 'later seq must win the tie');
    }

    #[Test]
    public function resultWithDifferentToolNameThanCallIsNotSelected(): void
    {
        $call = $this->callBlock('c1', 'edit', seq: 1);
        $result = $this->resultBlock('r-read', 'c1', seq: 2, meta: ['tool_name' => 'read', 'result' => 'file content']);

        $selected = $this->policy->findCombinableToolResultForCall(
            $call,
            ['c1' => [$result]],
            [],
            [],
        );

        $this->assertNull($selected, 'mismatched tool_name on the same tool_call_id must not pair');
    }

    #[Test]
    public function missingOrEmptyToolNamesFallThroughAsCompatible(): void
    {
        // Call has no tool_name; result has one → compatible.
        $call = $this->callBlock('c1', null, seq: 1);
        $result = $this->resultBlock('r-named', 'c1', seq: 2, meta: ['tool_name' => 'read', 'result' => 'file content']);

        $selected = $this->policy->findCombinableToolResultForCall(
            $call,
            ['c1' => [$result]],
            [],
            [],
        );
        $this->assertSame('r-named', $selected?->id, 'unnamed call must pair with a named result');

        // Call has a name; result has none → compatible.
        $call2 = $this->callBlock('c2', 'edit', seq: 1);
        $result2 = $this->resultBlock('r-unnamed', 'c2', seq: 2, meta: ['tool_call_id' => 'c2', 'result' => 'ok']);

        $selected2 = $this->policy->findCombinableToolResultForCall(
            $call2,
            ['c2' => [$result2]],
            [],
            [],
        );
        $this->assertSame('r-unnamed', $selected2?->id, 'unnamed result must pair with a named call');
    }

    #[Test]
    public function consumedToolResultIdIsExcludedFromCandidates(): void
    {
        $call = $this->callBlock('c1', 'edit', seq: 1);
        $consumed = $this->resultBlock('r-consumed', 'c1', seq: 2, meta: ['tool_name' => 'edit', 'result' => 'first']);
        $fresh = $this->resultBlock('r-fresh', 'c1', seq: 3, meta: ['tool_name' => 'edit', 'result' => 'second']);

        $selected = $this->policy->findCombinableToolResultForCall(
            $call,
            ['c1' => [$consumed, $fresh]],
            ['r-consumed' => true],
            [],
        );

        $this->assertSame('r-fresh', $selected?->id, 'consumed result ids must be skipped during selection');
    }

    #[Test]
    public function emptyAssistantPlaceholderIsSuppressedOnlyBeforeQuestion(): void
    {
        $empty = $this->block('a-empty', TranscriptBlockKindEnum::AssistantMessage, seq: 1, text: '');
        $question = $this->block('q-1', TranscriptBlockKindEnum::Question, seq: 2, text: 'Proceed?');
        $user = $this->block('u-1', TranscriptBlockKindEnum::UserMessage, seq: 2, text: 'hello');

        $this->assertTrue($this->policy->shouldSuppressEmptyAssistantPlaceholder($empty, $question));
        $this->assertFalse($this->policy->shouldSuppressEmptyAssistantPlaceholder($empty, $user));

        $nonEmpty = $this->block('a-text', TranscriptBlockKindEnum::AssistantMessage, seq: 1, text: 'ok');
        $this->assertFalse($this->policy->shouldSuppressEmptyAssistantPlaceholder($nonEmpty, $question));

        $toolResult = $this->block('t-1', TranscriptBlockKindEnum::ToolResult, seq: 1, text: '');
        $this->assertFalse($this->policy->shouldSuppressEmptyAssistantPlaceholder($toolResult, $question));
    }

    #[Test]
    public function askHumanResultIsSuppressedOnlyWhenNotFullRender(): void
    {
        $suppressed = $this->resultBlock('r-ask', 'ah1', seq: 2, meta: ['tool_name' => 'ask_human', 'result' => '{"kind":"interrupt"}']);
        $this->assertTrue($this->policy->isTranscriptWidgetSuppressed($suppressed), 'non-error ask_human result must be hidden (Question is authoritative)');

        $error = $this->resultBlock('r-ask-err', 'ah2', seq: 2, meta: ['tool_name' => 'ask_human', 'is_error' => true, 'result' => '{"error":"boom"}']);
        $this->assertFalse($this->policy->isTranscriptWidgetSuppressed($error), 'error ask_human result must stay visible for diagnostics');
    }

    #[Test]
    public function askHumanToolCallIsAlwaysSuppressed(): void
    {
        $call = $this->callBlock('c-ask', 'ask_human', seq: 1);
        $this->assertTrue($this->policy->isTranscriptWidgetSuppressed($call));
    }

    private function callBlock(string $id, ?string $toolName, int $seq): TranscriptBlock
    {
        $meta = ['tool_call_id' => $id];
        if (null !== $toolName) {
            $meta['tool_name'] = $toolName;
        }

        return $this->block($id, TranscriptBlockKindEnum::ToolCall, $seq, text: '', meta: $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resultBlock(string $id, string $callId, int $seq, array $meta, bool $streaming = false): TranscriptBlock
    {
        $meta['tool_call_id'] = $callId;

        return $this->block($id, TranscriptBlockKindEnum::ToolResult, $seq, text: '', meta: $meta, streaming: $streaming);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function block(string $id, TranscriptBlockKindEnum $kind, int $seq, string $text = '', array $meta = [], bool $streaming = false): TranscriptBlock
    {
        return new TranscriptBlock(
            id: $id,
            kind: $kind,
            runId: 'run1',
            seq: $seq,
            text: $text,
            meta: $meta,
            streaming: $streaming,
        );
    }
}
