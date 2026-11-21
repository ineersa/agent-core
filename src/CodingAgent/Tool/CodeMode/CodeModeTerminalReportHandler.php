<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\MalformedToolResultException;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeTerminalReportDTO;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\OutputCapToolResultProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(fromTransport: 'code_mode_requests')]
final readonly class CodeModeTerminalReportHandler
{
    public function __construct(
        private CodeModeProcessOwner $processes,
        private DeferredToolCompletionRepositoryInterface $deferred,
        #[Autowire(service: 'agent.command.bus')] private MessageBusInterface $commandBus,
        private OutputCap $outputCap,
        private ToolPayloadStoreInterface $payloads,
        private CodeModeDiagnosticsToolResultProcessor $diagnostics,
        private OutputCapToolResultProcessor $capResults,
    ) {
    }

    public function __invoke(CodeModeTerminalReportDTO $report): void
    {
        $metadata = $this->deferred->findMetadataByDeferredId($report->invocationId);
        if (null === $metadata || 'pending' !== $metadata->status || null !== $metadata->acceptedResultReference || 'code_mode' !== $metadata->reference->toolName) {
            return;
        }
        $runId = $metadata->reference->runId;
        $call = $this->deferred->findByDeferredId($report->invocationId);
        $invocation = $this->processes->invocation($runId, $report->invocationId);
        if (null === $call || null === $invocation || $invocation['fenced']) {
            return;
        }
        $identity = CodeModeTerminalIdentity::forInvocation($runId, $report->invocationId);
        $payload = $report->payload;
        if ('result' !== $payload->kind || $payload->runId !== $runId || $payload->toolCallId !== $identity->toolCallId
            || $payload->stepId !== $identity->stepId || $payload->idempotencyKey !== $identity->idempotencyKey
            || 0 !== $payload->turnNo || 0 !== $payload->attempt || 0 !== $payload->orderIndex) {
            throw new \Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException('Invalid code-mode terminal correlation.');
        }
        $result = $this->payloads->read($payload);
        $reply = $result instanceof ToolCallResult ? $result->result : null;
        if (!\is_array($reply) || !\is_bool($reply['ok'] ?? null)) {
            $reply = ['ok' => false, 'error' => 'Invalid code-mode terminal report.'];
        }
        if (null !== $metadata->interruptionReason) {
            $reply = ['ok' => false, 'error' => 'Code-mode invocation interrupted: '.$metadata->interruptionReason.'.'];
        }
        $this->complete($call, $invocation['workspace'], $reply);
    }

    /** @param array{ok: bool, result?: mixed, error?: string} $reply */
    public function complete(DeferredToolCompletionCorrelation $call, string $workspace, array $reply): void
    {
        $runId = $call->runId;
        $tool = new ToolCall($call->toolCallId, $call->toolName, $call->arguments, $call->orderIndex, $runId);
        $capture = new CodeModeDiagnosticCapture($this->outputCap, $runId, $workspace,
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
