<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionInterface;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCli;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextCompletedTurnHook;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextEligibilityJobHandler;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextReindexJobHandler;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextSessionStartHook;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionLocator;
use Ineersa\HatfieldExt\Jbcontext\Tool\CodeSearchToolHandler;
use Ineersa\HatfieldExt\Jbcontext\Tui\JbcontextStatusPoller;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * JetBrains Context semantic-search extension.
 *
 * Registers handlers/tools during register(). Interactive eligibility starts
 * from the controller session-start hook; the TUI poller only publishes status.
 */
final class JbcontextExtension implements HatfieldExtensionInterface, TuiExtensionInterface, LoggerAwareInterface
{
    public const int TOOL_TIMEOUT_SECONDS = 30;

    private LoggerInterface $logger;
    private JbcontextSessionLocator $sessions;
    private ?JbcontextPaths $paths = null;

    public function __construct()
    {
        $this->logger = new NullLogger();
        $this->sessions = new JbcontextSessionLocator();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function register(ExtensionApiInterface $api): void
    {
        $paths = JbcontextPaths::fromProjectRoot($api->getCwd());
        $this->paths = $paths;
        $packageRoot = \dirname(__DIR__);

        $api->registerExtensionAgentJobHandler(
            JbcontextEligibilityJobHandler::HANDLER_ID,
            new JbcontextEligibilityJobHandler($this->logger, $packageRoot),
        );
        $api->registerExtensionAgentJobHandler(
            JbcontextReindexJobHandler::HANDLER_ID,
            new JbcontextReindexJobHandler($this->logger),
        );

        $api->registerSessionStartHook(
            new JbcontextSessionStartHook($api, $paths, $this->logger),
        );
        $api->registerAfterTurnCommitHook(
            new JbcontextCompletedTurnHook($api, $paths, $this->sessions, $this->logger),
        );

        $api->registerTool(new ToolRegistrationDTO(
            name: 'code_search',
            description: 'Semantic code search via jbcontext when the relevant file or subsystem is unknown. '
                .'text is a focused non-whitespace question or code snippet. '
                .'Optional path_filter is a project-relative directory or file such as src/ or '
                .'src/CodingAgent/Runtime/Controller (no absolute paths or ..). '
                .'Read the skill guidelines before calling. After hits, verify local source; similarity is ranking, not probability.',
            parametersJsonSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['text'],
                'properties' => [
                    'text' => [
                        'type' => 'string',
                        'description' => 'Focused non-whitespace natural-language question or representative code snippet.',
                    ],
                    'path_filter' => [
                        'type' => 'string',
                        'description' => 'Optional project-relative directory or file filter, e.g. src/ or src/CodingAgent/Runtime/Controller. Absolute paths and .. are rejected.',
                    ],
                ],
            ],
            handler: new CodeSearchToolHandler(
                $paths,
                $this->sessions,
                new JbcontextCli($api->exec(), $paths->projectRoot),
                $this->logger,
            ),
            promptSummary: 'Use code_search for meaning-based discovery when the relevant file or subsystem is unknown; then read local files. Follow the skill guidelines.',
            promptGuidelines: [
                'Read the jbcontext-semantic-search skill guidelines before the first code_search call for a discovery question.',
            ],
            timeoutSeconds: self::TOOL_TIMEOUT_SECONDS,
        ));

        $this->logger->info('jbcontext.extension.registered', [
            'component' => 'jbcontext',
            'event_type' => 'jbcontext.extension.registered',
        ]);
    }

    public function registerTui(TuiExtensionContextInterface $context): void
    {
        $paths = $this->paths;
        if (null === $paths) {
            // register() always runs before registerTui when the extension is enabled.
            return;
        }

        $this->sessions->bindTui($context);
        $poller = new JbcontextStatusPoller($context, $paths, $this->sessions, $this->logger);
        $context->onTick(static function () use ($poller): void {
            $poller->tick();
        });
    }
}
