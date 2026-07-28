<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Command\OmStatusCommandHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmSessionContext;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Thesis: session id is resolved lazily from the live TUI context at command
 * invocation; registration-time string caching is forbidden. Command failures
 * surface a fixed safe message without exception text.
 */
final class OmSessionContextCommandTest extends TestCase
{
    #[Test]
    public function requireSessionIdReadsLiveContextAfterRegistrationBind(): void
    {
        $sessionContext = new OmSessionContext();
        $tui = $this->mutableTui('session-at-register');
        $sessionContext->bindTui($tui);

        $tui->sessionId = 'session-after-register';
        $this->assertSame('session-after-register', $sessionContext->requireSessionId());

        $tui->sessionId = 'session-later-again';
        $this->assertSame('session-later-again', $sessionContext->requireSessionId());
    }

    #[Test]
    public function statusCommandUsesSessionIdResolvedAtInvocation(): void
    {
        $sessionContext = new OmSessionContext();
        $tui = $this->mutableTui('session-at-register');
        $sessionContext->bindTui($tui);
        $tui->sessionId = 'session-after-register';

        $seenRunId = null;
        $handler = new class($sessionContext, $seenRunId) implements ExtensionCommandHandlerInterface {
            public function __construct(
                private OmSessionContext $sessionContext,
                private ?string &$seenRunId,
            ) {
            }

            public function handle(string $args, CommandContextInterface $context): void
            {
                $this->seenRunId = $this->sessionContext->requireSessionId();
                $context->notify('ok:'.$this->seenRunId, 'info');
            }
        };

        $messages = [];
        $handler->handle('', $this->collectingContext($messages));

        $this->assertSame('session-after-register', $seenRunId);
        $this->assertSame(['info:ok:session-after-register'], $messages);
    }

    #[Test]
    public function statusCommandEmitsFixedErrorWithoutExceptionTextWhenSessionMissing(): void
    {
        $sessionContext = new OmSessionContext();
        $handler = new OmStatusCommandHandler(
            new OmQueryService($this->unusedApi(), OmSettings::fromArray([
                'observer' => ['model' => 'llama_cpp_test/test'],
                'reflector' => ['model' => 'llama_cpp_test/test'],
            ])),
            $sessionContext,
        );

        $messages = [];
        $handler->handle('', $this->collectingContext($messages));

        $this->assertSame(['error:OM status unavailable.'], $messages);
        $this->assertStringNotContainsString('RuntimeException', implode("\n", $messages));
        $this->assertStringNotContainsString('No active TUI session', implode("\n", $messages));
    }

    private function mutableTui(string $sessionId): object
    {
        return new class($sessionId) implements TuiExtensionContextInterface {
            public function __construct(public string $sessionId)
            {
            }

            public function getSessionId(): string
            {
                return $this->sessionId;
            }

            public function requestRender(bool $force = false): void
            {
            }

            public function setStatus(string $key, ?string $text): void
            {
            }

            public function insertOverlayAfterEditor(AbstractWidget $widget): void
            {
            }

            public function removeOverlay(AbstractWidget $widget): void
            {
            }

            public function setFocus(AbstractWidget $widget): void
            {
            }

            public function formatMuted(string $text): string
            {
                return $text;
            }

            public function formatRolePrefix(string $displayRole): string
            {
                return $displayRole.':';
            }

            public function turnRowsInDisplayOrder(string $sessionId): array
            {
                return [];
            }
        };
    }

    /**
     * @param list<string> $messages
     */
    private function collectingContext(array &$messages): CommandContextInterface
    {
        return new class($messages) implements CommandContextInterface {
            /** @param list<string> $messages */
            public function __construct(private array &$messages)
            {
            }

            public function notify(string $message, string $level = 'info'): void
            {
                $this->messages[] = $level.':'.$message;
            }
        };
    }

    private function unusedApi(): ExtensionApiInterface
    {
        return new class implements ExtensionApiInterface {
            public function getCwd(): string
            {
                return sys_get_temp_dir();
            }

            public function getSettings(string $key): array
            {
                return [];
            }

            public function registerTool(ToolRegistrationDTO $tool): void
            {
            }

            public function registerToolCallHook(ToolCallHookInterface $hook): void
            {
            }

            public function registerToolResultHook(ToolResultHookInterface $hook): void
            {
            }

            public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
            {
            }

            public function registerPromptContributor(PromptContributorInterface $contributor): void
            {
            }

            public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
            {
            }

            public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
            {
            }

            public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
            {
            }

            public function registerExtensionAgentJobHandler(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
            {
            }

            public function dispatchExtensionAgentJob(ExtensionAgentJobRequestDTO $request): void
            {
            }

            public function agent(): AgentRunnerInterface
            {
                throw new \LogicException('unused');
            }

            public function sessionEvents(): SessionEventReaderInterface
            {
                throw new \LogicException('unused');
            }

            public function exec(): ExecInterface
            {
                throw new \LogicException('unused');
            }
        };
    }
}
