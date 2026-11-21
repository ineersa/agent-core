<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\MalformedToolResultException;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticCapture;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnostics;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticsToolResultProcessor;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeProcessOwner;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\OutputCapToolResultProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class CodeModeTerminalObserver
{
    public function __construct(
        private CodeModeProcessOwner $processes,
        private DeferredToolCompletionRepositoryInterface $deferred,
        #[Autowire(service: 'agent.command.bus')] private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
        private OutputCap $outputCap,
        private ToolPayloadStoreInterface $payloads,
        private CodeModeDiagnosticsToolResultProcessor $diagnostics,
        private OutputCapToolResultProcessor $capResults,
    ) {
    }

    public function observe(string $sessionId, string $runId): void
    {
        foreach ($this->processes->pendingControllerInvocationIds($runId) as $id) {
            try {
                $this->settle($runId, $id);
            } catch (\Throwable $error) {
                $this->logger->warning('code_mode.terminal_observation_failed', [
                    'run_id' => $runId, 'session_id' => $sessionId, 'component' => 'tool.code_mode',
                    'event_type' => 'code_mode.terminal_observation_failed', 'error_type' => $error::class,
                ]);
            }
        }
    }

    private function settle(string $runId, string $id): void
    {
        $invocation = $this->processes->invocation($runId, $id);
        $metadata = $this->deferred->findMetadataByDeferredId($id);
        if (null === $metadata || 'pending' !== $metadata->status || $runId !== $metadata->reference->runId || 'code_mode' !== $metadata->reference->toolName) {
            return;
        }
        $call = $this->deferred->findByDeferredId($id);
        if (null === $call) {
            return;
        }
        $explicit = $call->arguments['timeout_seconds'] ?? null;
        $explicit = \is_int($explicit) ? $explicit : null;
        $deadline = $this->processes->deadline($runId, $id, null !== $metadata->backgroundedAt, $explicit, $call->timeoutSeconds);
        $reply = $this->processes->observeTerminal($runId, $id, $deadline, $metadata->interruptionReason);
        if (null === $reply) {
            return;
        }
        $tool = new ToolCall($call->toolCallId, $call->toolName, $call->arguments, $call->orderIndex, $runId);
        $capture = new CodeModeDiagnosticCapture($this->outputCap, $runId, $invocation['workspace'],
            fn (callable $write): string => $this->payloads->saveAttachment($runId, $call->toolCallId, $write));
        try {
            $diagnostics = is_file($capture->stdoutPath) && is_file($capture->stderrPath) ? $capture->prepare(3) : []; // Lines before user code in the materialized script.
        } catch (MalformedToolResultException $error) {
            $reply = ['ok' => false, 'error' => $error->getMessage()];
            $diagnostics = [];
        }
        $result = $reply['ok']
            ? $this->diagnostics->process(new ToolResult($call->toolCallId, $call->toolName, [], ['raw_result' => [
                'code_mode_value' => $reply['result'] ?? null,
                'code_mode_diagnostics' => $diagnostics,
            ]]), $tool)
            : new ToolResult($call->toolCallId, $call->toolName, [['type' => 'text', 'text' => CodeModeDiagnostics::appendToMessage($reply['error'] ?? 'Code-mode failed.', $diagnostics)]], isError: true);
        $result = $this->capResults->process($result, $tool);
        // The usual deferred transport persists the canonical result and the
        // run-control admission path settles duplicate sends by correlation.
        $this->commandBus->dispatch(ToolCallResultFactory::fromDeferredCorrelationAndCompletion($call, $result->content, $result->details, $result->isError));
    }
}
