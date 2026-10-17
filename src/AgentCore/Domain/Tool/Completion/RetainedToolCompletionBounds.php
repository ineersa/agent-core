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

    public const int SCHEMA_VERSION = 1;

    public const string KIND_TOOL_COMPLETION = 'tool_completion';

    public const string MEDIA_TYPE_JSON = 'application/json';
}
