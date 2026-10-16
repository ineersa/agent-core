<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

/**
 * Stable process-role labels for resource attribution.
 *
 * Values are written into ambient RunLogContext and Process Check tags.
 * Keep names short, lowercase, and stable for the life of a command.
 */
enum ProcessRoleEnum: string
{
    case Screen = 'screen';
    case Controller = 'controller';
    case Headless = 'headless';
    case RunControl = 'run_control';
    case Llm = 'llm';
    case Tool = 'tool';
    case AgentWorker = 'agent_worker';
    case Mcp = 'mcp';
    case ExtensionAgent = 'extension_agent';
    case Scheduler = 'scheduler';
    case Console = 'console';

    /**
     * OS process title matched by Datadog Process Check after attribution.
     *
     * cli_set_process_title() replaces the visible cmdline, so Process Check
     * patterns search this stable title rather than raw argv text.
     */
    public function processTitle(): string
    {
        return 'hatfield.role.'.$this->value;
    }

    public static function forAgentCommand(bool $controller, bool $headless): self
    {
        if ($controller) {
            return self::Controller;
        }

        if ($headless) {
            return self::Headless;
        }

        return self::Screen;
    }

    /**
     * Map one Symfony Messenger receiver/transport name to a process role.
     *
     * Multi-receiver workers stay unattributed rather than inventing a combined label.
     */
    public static function forMessengerTransport(string $transportName): ?self
    {
        return match ($transportName) {
            'run_control' => self::RunControl,
            'llm' => self::Llm,
            'tool' => self::Tool,
            'agent' => self::AgentWorker,
            'mcp' => self::Mcp,
            'extension_agent' => self::ExtensionAgent,
            'scheduler_default' => self::Scheduler,
            default => null,
        };
    }
}
