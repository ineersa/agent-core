<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionInterface;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCli;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextCompletedTurnHook;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextEligibilityJobHandler;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextReindexJobHandler;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Ineersa\HatfieldExt\Jbcontext\Tool\CodeSearchToolHandler;
use Ineersa\HatfieldExt\Jbcontext\Tui\JbcontextStatusPoller;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * JetBrains Context semantic-search extension.
 *
 * Registers:
 * - permanent code_search tool (TOON)
 * - background eligibility/retry job (no first-index)
 * - completed-turn incremental reindex dispatch
 * - TUI status poller
 */
final class JbcontextExtension implements HatfieldExtensionInterface, TuiExtensionInterface, LoggerAwareInterface
{
    public const int TOOL_TIMEOUT_SECONDS = 30;

    private LoggerInterface $logger;
    private ?JbcontextPaths $paths = null;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function register(ExtensionApiInterface $api): void
    {
        $paths = JbcontextPaths::fromProjectRoot($api->getCwd());
        $this->paths = $paths;
        $store = new JbcontextStatusStore($paths->statusPath);
        $packageRoot = \dirname(__DIR__);

        // Fresh session starts pending; worker will move to eligible/disabled.
        if (!is_file($paths->statusPath)) {
            $store->write(JbcontextSessionState::pending());
        }

        $api->registerExtensionAgentJobHandler(
            JbcontextEligibilityJobHandler::HANDLER_ID,
            new JbcontextEligibilityJobHandler($this->logger, $packageRoot),
        );
        $api->registerExtensionAgentJobHandler(
            JbcontextReindexJobHandler::HANDLER_ID,
            new JbcontextReindexJobHandler($this->logger),
        );

        $api->registerAfterTurnCommitHook(
            new JbcontextCompletedTurnHook($api, $paths, $this->logger),
        );

        $api->registerTool(new ToolRegistrationDTO(
            name: 'code_search',
            description: 'Semantic code search via jbcontext for unfamiliar behavior or location. '
                .'Use one focused natural-language query; optionally narrow once with path_filter; then read promising files. '
                .'Prefer direct reads or IDE definition/references for known files or symbols. '
                .'Do not use for builds, tests, Git, or diff review.',
            parametersJsonSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['text'],
                'properties' => [
                    'text' => [
                        'type' => 'string',
                        'description' => 'Focused natural-language semantic query or representative code snippet.',
                    ],
                    'path_filter' => [
                        'type' => 'string',
                        'description' => 'Optional project-relative path prefix to narrow search after an initial broad hit.',
                    ],
                ],
            ],
            handler: new CodeSearchToolHandler(
                $store,
                new JbcontextCli($api->exec(), $paths->projectRoot),
                $this->logger,
            ),
            promptSummary: 'Use code_search for meaning-based discovery when the relevant file or subsystem is unknown; then read local files.',
            promptGuidelines: [
                'Use one focused semantic query for unfamiliar behavior or location.',
                'Optionally narrow once with path_filter using a project-relative directory from the best first hit.',
                'After search hits, read promising files and nearby code before another semantic query.',
                'Prefer direct reads or IDE definition/references when you already know the file, class, or symbol.',
                'Do not use code_search for builds, tests, Git operations, or reviewing an existing diff.',
            ],
            timeoutSeconds: self::TOOL_TIMEOUT_SECONDS,
        ));

        try {
            $api->dispatchExtensionAgentJob(new ExtensionAgentJobRequestDTO(
                handlerId: JbcontextEligibilityJobHandler::HANDLER_ID,
                payload: ['attempt' => 1],
                jobId: 'jbcontext.eligibility.attempt.1',
                correlationId: 'startup',
            ));
        } catch (\Throwable $e) {
            $this->logger->error('jbcontext.eligibility.startup_dispatch_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.eligibility.startup_dispatch_failed',
                'exception_class' => $e::class,
            ]);
            $store->write(JbcontextSessionState::pending()->with(
                mode: State\JbcontextSessionModeEnum::Disabled,
                reason: 'jbcontext disabled: could not start background eligibility check.',
                statusText: 'jbcontext disabled: could not start background eligibility check.',
            ));
        }

        $this->logger->info('jbcontext.extension.registered', [
            'component' => 'jbcontext',
            'event_type' => 'jbcontext.extension.registered',
        ]);
    }

    public function registerTui(TuiExtensionContextInterface $context): void
    {
        $paths = $this->paths;
        if (null === $paths) {
            return;
        }

        $poller = new JbcontextStatusPoller(
            $context,
            new JbcontextStatusStore($paths->statusPath),
            $this->logger,
        );
        $context->onTick(static function () use ($poller): void {
            $poller->tick();
        });
    }
}
