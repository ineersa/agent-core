<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionStatusEnum;
use Ineersa\AgentCore\Contract\Tool\MalformedToolResultException;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\RunCancellationToken;
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
        private CodeModeReplyTransportFactory $replies,
        private RunOperationalStatusReaderInterface $status,
    ) {
    }

    public function __invoke(CodeModeTerminalReportDTO $report): void
    {
        $metadata = $this->deferred->findMetadataByDeferredId($report->invocationId);
        if (null !== $metadata && (DeferredToolCompletionStatusEnum::Pending !== $metadata->status || 'code_mode' !== $metadata->reference->toolName)) {
            return;
        }
        $runId = $metadata?->reference->runId ?? $report->runId;
        $call = null === $metadata ? null : $this->deferred->findByDeferredId($report->invocationId);
        $invocation = $this->processes->invocation($runId, $report->invocationId);
        if (null === $invocation || $runId !== $report->runId || (!$invocation['fenced'] && !$this->processes->hasExited($runId, $report->invocationId)) || (null === $call && (null === $invocation['standaloneTurn'] || ($invocation['fenced'] && null === $report->failure)))) {
            return;
        }
        $identity = CodeModeTerminalIdentity::forInvocation($runId, $report->invocationId);
        $payload = $this->payloads->findFirstResult($identity);
        $result = null === $payload ? null : $this->payloads->read($payload);
        $reply = $result instanceof ToolCallResult ? $result->result : null;
        $interruption = $metadata?->interruptionReason?->value;
        if (null !== $invocation['standaloneTurn'] && (new RunCancellationToken($this->status, $runId))->isCancellationRequested()) {
            $interruption = 'cancelled';
        }
        $failure = $report->failure ?? ($this->processes->observeTerminal($runId, $report->invocationId,
            $this->processes->deadline($runId, $report->invocationId, null !== $metadata?->backgroundedAt),
            $interruption, static fn (): ?array => null)['error'] ?? null);
        if (null !== $failure) {
            $reply = ['ok' => false, 'error' => $failure];
        } elseif (!\is_array($reply) || !\is_bool($reply['ok'] ?? null)) {
            $reply = ['ok' => false, 'error' => 'Code-mode subprocess exited without returning a value.'];
        }
        $this->processes->retireReplies($runId, $report->invocationId);
        if (null !== $metadata?->interruptionReason) {
            $reply = ['ok' => false, 'error' => 'Code-mode invocation interrupted: '.$metadata->interruptionReason->value.'.'];
        }
        if (null !== $call) {
            $this->complete($call, $invocation, $reply);

            return;
        }
        $address = $invocation['reply'] ?? null;
        $id = $report->invocationId;
        if (null === $address) {
            $this->processes->retireStandalone($runId, $id);

            return;
        }
        $tool = new ToolCall($id, 'code_mode', [], 0, $runId);
        $result = $this->process($tool, $invocation, $reply);
        $body = 'code-mode-'.bin2hex(random_bytes(32));
        $reference = $this->payloads->publish(new ToolCallResult($runId, $identity->turnNo, $identity->stepId, $identity->attempt,
            $body, $body, $identity->orderIndex, ['content' => $result->content, 'details' => $result->details], $result->isError));
        $this->replies->sendStandalone($address, $id, $reference);
        $this->processes->retireStandalone($runId, $id);
    }

    /**
     * @param array{workspace: string, startedAtNs: int, fenced: bool, standaloneTurn: ?int, reply?: array{queue: string, incarnation: string}|null, policy: array{explicitTimeout: ?int, callerTimeout: ?int, retainOutput: bool, attachmentOwner: string}} $invocation
     * @param array{ok: bool, result?: mixed, error?: string}                                                                                                                                                                                                $reply
     */
    private function complete(DeferredToolCompletionCorrelation $call, array $invocation, array $reply): void
    {
        $tool = new ToolCall($call->toolCallId, $call->toolName, $call->arguments, $call->orderIndex, $call->runId);
        $result = $this->process($tool, $invocation, $reply);
        // The usual deferred transport persists the canonical result and the
        // run-control admission path settles duplicate sends by correlation.
        $this->commandBus->dispatch(ToolCallResultFactory::fromDeferredCorrelationAndCompletion($call, $result->content, $result->details, $result->isError));
    }

    /**
     * @param array{workspace: string, startedAtNs: int, fenced: bool, standaloneTurn: ?int, reply?: array{queue: string, incarnation: string}|null, policy: array{explicitTimeout: ?int, callerTimeout: ?int, retainOutput: bool, attachmentOwner: string}} $invocation
     * @param array{ok: bool, result?: mixed, error?: string}                                                                                                                                                                                                $reply
     */
    private function process(ToolCall $tool, array $invocation, array $reply): ToolResult
    {
        $runId = $tool->runId ?? throw new \LogicException('Code-mode tool result requires a run ID.');
        $policy = $invocation['policy'];
        $tool = new ToolCall($tool->toolCallId, $tool->toolName, $tool->arguments, $tool->orderIndex, $runId, context: [
            'retain_output' => $policy['retainOutput'],
            'attachment_owner_tool_call_id' => $policy['attachmentOwner'],
        ]);
        $capture = new CodeModeDiagnosticCapture($this->outputCap, $runId, $invocation['workspace'],
            fn (callable $write): string => $this->payloads->saveAttachment($runId, $policy['attachmentOwner'], $write));
        try {
            $diagnostics = is_file($capture->stdoutPath) && is_file($capture->stderrPath) ? $capture->prepare(3) : []; // Lines before user code in the materialized script.
        } catch (MalformedToolResultException $error) {
            $reply = ['ok' => false, 'error' => $error->getMessage()];
            $diagnostics = [];
        }
        $result = $reply['ok']
            ? $this->diagnostics->process(new ToolResult($tool->toolCallId, $tool->toolName, [], ['raw_result' => [
                'code_mode_value' => $reply['result'] ?? null,
                'code_mode_diagnostics' => $diagnostics,
            ]]), $tool)
            : new ToolResult($tool->toolCallId, $tool->toolName, [['type' => 'text', 'text' => CodeModeDiagnostics::appendToMessage($reply['error'] ?? 'Code-mode failed.', $diagnostics)]], isError: true);

        return $this->capResults->process($result, $tool);
    }
}
