<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Projection;

/**
 * A single block in the transcript projection.
 *
 * Transcript blocks are the stable presentation-safe view of the agent
 * conversation. They are produced by the TranscriptProjector from raw
 * RuntimeEvents and consumed by TUI rendering widgets.
 *
 * Blocks are immutable value objects. Serialization for transcript.jsonl
 * persistence is handled via Symfony Serializer (ObjectNormalizer + BackedEnumNormalizer).
 */
final readonly class TranscriptBlock
{
    /**
     * @param string                  $id        Stable block identifier (message_id, tool_call_id, block UUID, etc.)
     * @param TranscriptBlockKindEnum $kind      The kind of block this represents
     * @param string                  $runId     The run this block belongs to
     * @param int                     $seq       Monotonic sequence number (for deterministic ordering)
     * @param string                  $text      Visible text content of the block
     * @param array<string, mixed>    $meta      Additional block metadata (tool name, model, stop reason, etc.)
     * @param bool                    $streaming Whether this block is still receiving deltas
     * @param bool                    $collapsed Whether this block should be collapsed in the UI by default
     */
    public function __construct(
        public string $id,
        public TranscriptBlockKindEnum $kind,
        public string $runId,
        public int $seq,
        public string $text = '',
        public array $meta = [],
        public bool $streaming = false,
        public bool $collapsed = false,
    ) {
    }

    /**
     * Return a copy with the given properties changed.
     *
     * @param array<string, mixed>|null $meta
     *
     * This is the primary mutation API for the projector: it creates a new
     * block derived from the current one, which is safe for in-memory
     * accumulation during streaming
     */
    public function with(
        ?string $text = null,
        ?bool $streaming = null,
        ?bool $collapsed = null,
        ?array $meta = null,
    ): self {
        return new self(
            id: $this->id,
            kind: $this->kind,
            runId: $this->runId,
            seq: $this->seq,
            text: $text ?? $this->text,
            meta: $meta ?? $this->meta,
            streaming: $streaming ?? $this->streaming,
            collapsed: $collapsed ?? $this->collapsed,
        );
    }

    /**
     * Append text to the current block text and return a new block.
     *
     * Convenience method for delta accumulation during streaming.
     */
    public function appendText(string $delta): self
    {
        if ('' === $delta) {
            return $this;
        }

        return $this->with(text: $this->text.$delta);
    }

    /**
     * Finalize this block (mark as no longer streaming).
     */
    public function finalize(): self
    {
        return $this->with(streaming: false);
    }
}
