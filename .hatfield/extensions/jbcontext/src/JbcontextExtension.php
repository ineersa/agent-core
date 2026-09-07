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
use Ineersa\HatfieldExt\Jbcontext\Tui\JbcontextWarningPoller;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * JetBrains Context semantic-search extension.
 *
 * Registers handlers/tools during register(). Interactive eligibility starts
 * from the controller session-start hook; the TUI poller only publishes startup warnings.
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
            description: 'Semantic code search via jbcontext. Use to discover unfamiliar behavior or code locations. Prefer direct reads or IDE navigation for known files and symbols.',
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
            promptSummary: 'Use code_search to discover unfamiliar behavior or code locations; prefer direct reads or IDE navigation for known files and symbols.',
            promptGuidelines: [
                'Read promising local files and nearby code before another semantic query.',
                'Per discovery question, optionally make one narrowed follow-up with path_filter set to a useful directory from the first hits.',
                'Verify local source; returned snippets can be incomplete.',
                'Treat similarity as ranking, not confidence. Empty results are not absence of the behavior.',
                'When unavailable, use other tools or the reported guidance. Do not repeat the same failing call.',
                'Do not use code_search to review an existing diff.',
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
        $poller = new JbcontextWarningPoller($context, $paths, $this->sessions, $this->logger);
        $context->onTick(static function () use ($poller): void {
            $poller->tick();
        });
    }
}
