<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Process;

/**
 * Classification for tool process records in the cross-process registry.
 */
enum ToolProcessKindEnum: string
{
    /** Subprocess running as a foreground tool (read, edit, bash, etc.). */
    case ForegroundTool = 'foreground_tool';

    /** Subprocess that has been detached to background management. */
    case BackgroundTool = 'background_tool';
}
