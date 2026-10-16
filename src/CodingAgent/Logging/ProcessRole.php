<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

/**
 * Stable process-role labels for resource attribution.
 *
 * Values are written into ambient RunLogContext and Process Check tags.
 * Keep names short, lowercase, and stable for the life of a command.
 */
final class ProcessRole
{
    public const SCREEN = 'screen';

    public const CONTROLLER = 'controller';

    public const HEADLESS = 'headless';

    public const RUN_CONTROL = 'run_control';

    public const LLM = 'llm';

    public const TOOL = 'tool';

    public const AGENT_WORKER = 'agent_worker';

    public const MCP = 'mcp';

    public const EXTENSION_AGENT = 'extension_agent';

    public const CONSOLE = 'console';

    /**
     * Known Messenger transport names launched by the controller.
     *
     * @var list<string>
     */
    public const WORKER_TRANSPORTS = [
        self::RUN_CONTROL,
        self::LLM,
        self::TOOL,
        'agent',
        self::MCP,
        self::EXTENSION_AGENT,
    ];

    public static function forAgentCommand(bool $controller, bool $headless): string
    {
        if ($controller) {
            return self::CONTROLLER;
        }

        if ($headless) {
            return self::HEADLESS;
        }

        return self::SCREEN;
    }

    /**
     * Map one Symfony Messenger receiver/transport name to a process role.
     *
     * Multi-receiver workers stay unattributed rather than inventing a combined label.
     */
    public static function forMessengerTransport(string $transportName): ?string
    {
        return match ($transportName) {
            self::RUN_CONTROL => self::RUN_CONTROL,
            self::LLM => self::LLM,
            self::TOOL => self::TOOL,
            'agent' => self::AGENT_WORKER,
            self::MCP => self::MCP,
            self::EXTENSION_AGENT => self::EXTENSION_AGENT,
            default => null,
        };
    }
}
