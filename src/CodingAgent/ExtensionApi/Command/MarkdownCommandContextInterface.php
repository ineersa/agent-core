<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Command;

/**
 * Optional command-context capability for Markdown-rendered transcript notifications.
 *
 * Hosts that implement this interface can render Markdown through the existing
 * in-memory transcript path. Older hosts only implement {@see CommandContextInterface}.
 */
interface MarkdownCommandContextInterface extends CommandContextInterface
{
    /**
     * Display a Markdown message to the user through the transcript.
     *
     * Warning/error notifications from {@see notify()} still take precedence over style.
     */
    public function notifyMarkdown(string $message): void;
}
