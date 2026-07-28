<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;

/**
 * Typed immutable visual node produced by {@see TranscriptVisualProjector}.
 *
 * Dirty detection uses immutable source object identity plus an explicit
 * presentation revision (preview expansion). Symfony owns render caching.
 */
final readonly class TranscriptVisualNode
{
    public const string KIND_WELCOME = 'welcome';
    public const string KIND_SEPARATOR = 'separator';
    public const string KIND_MARKDOWN = 'markdown';
    public const string KIND_TOOL_EXCHANGE = 'tool_exchange';
    public const string KIND_QUESTION = 'question';
    public const string KIND_SUBAGENT = 'subagent';
    public const string KIND_GENERIC = 'generic';

    public function __construct(
        public string $key,
        public string $kind,
        public ?TranscriptBlock $primary,
        public ?TranscriptBlock $secondary,
        public int $presentationRevision,
    ) {
    }

    /**
     * Whether this node depends on the same sources and presentation revision.
     */
    public function sameSources(self $other): bool
    {
        return $this->key === $other->key
            && $this->kind === $other->kind
            && $this->primary === $other->primary
            && $this->secondary === $other->secondary
            && $this->presentationRevision === $other->presentationRevision;
    }
}
