<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

/**
 * Central registry of transcript block glyph and prefix constants.
 *
 * All glyphs, prefixes, and the streaming suffix used in transcript block
 * rendering are defined here. Both {@see TranscriptBlockWidgetFactory} and
 * {@see SubagentResultRenderer} reference these constants, ensuring a single
 * source of truth for the transcript visual language.
 *
 * These constants are the public API for tests that assert glyph rendering.
 * Changing a constant here updates the rendered output everywhere.
 */
final class TranscriptGlyphs
{
    /** User message prefix glyph */
    public const string GLYPH_USER_MESSAGE = '  ❯';
    /** Assistant message prefix glyph */
    public const string GLYPH_ASSISTANT_MESSAGE = '  ◇';
    /** Assistant thinking prefix glyph */
    public const string GLYPH_ASSISTANT_THINKING = '  ⋯';
    /** Tool call/result prefix glyph */
    public const string GLYPH_TOOL = '  ●';
    /** Progress block prefix glyph */
    public const string GLYPH_PROGRESS = '  ⏳';
    /** Question block prefix glyph */
    public const string GLYPH_QUESTION = '  ?';
    /** Approval block prefix glyph */
    public const string GLYPH_APPROVAL = '  🔐';
    /** Cancelled block prefix glyph */
    public const string GLYPH_CANCELLED = '  ✕';
    /** Error block prefix glyph */
    public const string GLYPH_ERROR = '  ✕';
    /** System info severity prefix glyph */
    public const string GLYPH_SYSTEM_INFO = '  ℹ';
    /** System warning severity prefix glyph */
    public const string GLYPH_SYSTEM_WARNING = '  ⚠';
    /** System error severity prefix glyph */
    public const string GLYPH_SYSTEM_ERROR = '  ✘';
    /** System default severity prefix glyph */
    public const string GLYPH_SYSTEM_DEFAULT = '  ·';
    /** Streaming suffix appended to in-progress blocks */
    public const string STREAMING_SUFFIX = '...';

    /**
     * Private constructor — this class is not instantiable.
     */
    private function __construct()
    {
    }
}
