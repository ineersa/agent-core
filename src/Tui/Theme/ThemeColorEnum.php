<?php

declare(strict_types=1);

namespace Ineersa\Tui\Theme;

/**
 * Semantic theme color tokens.
 *
 * Each case represents a semantic role in the TUI layout.
 * Theme implementations map these to concrete ANSI colors/styles.
 *
 * Users configure palettes via YAML theme files using these token names
 * (lowercased). Example:
 *
 * ```yaml
 * colors:
 *   accent: "#8abeb7"
 *   muted: "#6a6a7a"
 *   error: "#ff6b6b"
 * ```
 */
enum ThemeColorEnum: string
{
    /* ──────── Base text ──────── */
    case Text = 'text';
    case Muted = 'muted';
    case Dim = 'dim';

    /* ──────── Semantic ──────── */
    case Accent = 'accent';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';

    /* ──────── Layout chrome ──────── */
    case Header = 'header';
    case Footer = 'footer';
    case Separator = 'separator';
    case Border = 'border';
    case BorderAccent = 'border_accent';
    case BorderMuted = 'border_muted';
    case Prompt = 'prompt';

    /* ──────── Transcript / messages ──────── */
    case UserMessage = 'user_message';
    case AssistantMessage = 'assistant_message';
    case SystemMessage = 'system_message';
    case UserMessageBg = 'user_message_bg';

    /* ──────── Tool ──────── */
    case Tool = 'tool';
    case ToolTitle = 'tool_title';
    case ToolOutput = 'tool_output';
    case ToolPendingBg = 'tool_pending_bg';
    case ToolSuccessBg = 'tool_success_bg';
    case ToolErrorBg = 'tool_error_bg';
    case ToolArgumentKey = 'tool_argument_key';
    case ToolArgumentValue = 'tool_argument_value';

    /* ──────── Diff ──────── */
    case DiffAdded = 'diff_added';
    case DiffRemoved = 'diff_removed';
    case DiffContext = 'diff_context';

    /* ──────── Markdown / content ──────── */
    case MarkdownHeading = 'markdown_heading';
    case MarkdownLink = 'markdown_link';
    case MarkdownLinkUrl = 'markdown_link_url';
    case MarkdownCode = 'markdown_code';
    case MarkdownCodeBlock = 'markdown_code_block';
    case MarkdownCodeBlockBorder = 'markdown_code_block_border';
    case MarkdownQuote = 'markdown_quote';
    case MarkdownQuoteBorder = 'markdown_quote_border';
    case MarkdownHr = 'markdown_hr';
    case MarkdownListBullet = 'markdown_list_bullet';

    /* ──────── Syntax highlighting ──────── */
    case SyntaxComment = 'syntax_comment';
    case SyntaxKeyword = 'syntax_keyword';
    case SyntaxFunction = 'syntax_function';
    case SyntaxVariable = 'syntax_variable';
    case SyntaxString = 'syntax_string';
    case SyntaxNumber = 'syntax_number';
    case SyntaxType = 'syntax_type';
    case SyntaxOperator = 'syntax_operator';
    case SyntaxPunctuation = 'syntax_punctuation';

    /* ──────── Misc ──────── */
    case Working = 'working';
    case ThinkingText = 'thinking_text';
    case ThinkingOff = 'thinking_off';
    case ThinkingMinimal = 'thinking_minimal';
    case ThinkingLow = 'thinking_low';
    case ThinkingMedium = 'thinking_medium';
    case ThinkingHigh = 'thinking_high';
    case ThinkingXhigh = 'thinking_xhigh';
    case BashMode = 'bash_mode';

    /**
     * Map a reasoning level string to the corresponding thinking colour token.
     *
     * Shared between the footer segment provider and the editor border
     * colour so the same mapping is used everywhere.
     */
    public static function forReasoning(string $reasoning): self
    {
        return match ($reasoning) {
            'xhigh' => self::ThinkingXhigh,
            'high' => self::ThinkingHigh,
            'medium' => self::ThinkingMedium,
            'low' => self::ThinkingLow,
            'minimal' => self::ThinkingMinimal,
            'off' => self::ThinkingOff,
            default => self::ThinkingText,
        };
    }
}
