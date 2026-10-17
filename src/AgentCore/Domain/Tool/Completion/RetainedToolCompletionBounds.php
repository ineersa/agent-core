<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/**
 * Byte budgets for retained tool_completion envelopes (spec SC-06).
 *
 * These are internal protocol defaults, not user settings.
 */
final class RetainedToolCompletionBounds
{
    public const int SUBJECT_MAX_BYTES = 256;

    public const int SUMMARY_MAX_BYTES = 1024;

    public const int INLINE_BODY_MAX_BYTES = 8192;

    public const int ENVELOPE_MAX_BYTES = 16384;

    /** Maximum retained body bytes decoded into PHP for one inspection call. */
    public const int INSPECTION_BODY_MAX_BYTES = 262144;

    /** Maximum events scanned newest-first when locating one retained receipt. */
    public const int SOURCE_LOOKUP_MAX_EVENTS = 4096;

    /**
     * Maximum native handoff attachment entries kept inline on the envelope.
     * Larger batches seal the full generation list into an owned manifest body.
     */
    public const int INLINE_NATIVE_ATTACHMENT_MAX = 8;

    public const int SCHEMA_VERSION = 1;

    public const string KIND_TOOL_COMPLETION = 'tool_completion';

    public const string MEDIA_TYPE_JSON = 'application/json';
}
