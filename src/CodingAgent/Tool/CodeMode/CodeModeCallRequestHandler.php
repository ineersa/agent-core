<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionHumanInputSuspension;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\RunCancellationToken;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO;
use Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeCallRequestDTO;
use Ineersa\SqliteQueue\Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler(fromTransport: 'code_mode_requests')]
final readonly class CodeModeCallRequestHandler
{
    public function __construct(
        private ValidatorInterface $validator,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private ToolBatchStoreInterface $batches,
        private CodeModeProcessOwner $processes,
        private RunOperationalStatusReaderInterface $status,
        private StackToolExecutionContextAccessor $contexts,
        private CodeModeNestedToolExecutor $executor,
        private CodeModeCallJournal $journal,
        #[Autowire(service: 'agent.command.bus')] private MessageBusInterface $commands,
        #[Autowire('%env(HATFIELD_SQLITE_QUEUE_ENDPOINT)%')] private string $endpoint,
    ) {
    }

    public function __invoke(CodeModeCallRequestDTO $request): void
    {
        if (0 !== $this->validator->validate($request)->count()
            || ([] !== $request->arguments && array_is_list($request->arguments))) {
            throw new UnrecoverableMessageHandlingException('Invalid code-mode call request.');
        }
        $rootId = $request->rootId ?? $request->invocationId;
        $metadata = $this->deferred->findMetadataByDeferredId($rootId);
        if (null === $metadata || 'pending' !== $metadata->status || null === $metadata->readyAt || 'code_mode' !== $metadata->reference->toolName) {
            throw new UnrecoverableMessageHandlingException('Code-mode invocation is not ready.');
        }
        $ref = $metadata->reference;
        $call = $this->batches->loadCall($ref->runId, $ref->turnNo, $ref->stepId, $ref->toolCallId);
        $process = $this->processes->invocation($ref->runId, $request->invocationId);
        if (null === $process || ($rootId !== $request->invocationId && ($process['parent']['rootId'] ?? null) !== $rootId) || null === $call || 'code_mode' !== $call->toolName
            || $ref->attempt !== $call->attempt() || $ref->idempotencyKey !== $call->idempotencyKey() || $ref->orderIndex !== $call->orderIndex) {
            throw new UnrecoverableMessageHandlingException('Code-mode invocation evidence does not match the canonical call.');
        }
        $wire = ['operation' => $request->operation, 'name' => $request->name, 'arguments' => $request->arguments];
        CodeModeIpc::encode($wire);
        $reply = $this->journal->settle($process['workspace'], $request->callId, $wire, function () use ($request, $call, $process, $rootId): array {
            try {
                $explicit = $call->args['timeout_seconds'] ?? null;
                $token = new CodeModeCancellationToken(new RunCancellationToken($this->status, $call->runId()), $this->deferred, $rootId, \is_int($explicit) ? $explicit : null, $request->invocationId, $request->callId, $rootId);
                if ($token->isCancellationRequested()) {
                    return ['ok' => false, 'error' => 'Code-mode invocation cancelled.'];
                }
                $foreground = \is_int($explicit) ? $explicit : CodeModeArgumentsDTO::DEFAULT_TIMEOUT_SECONDS;
                if (null !== $call->timeoutSeconds && $call->timeoutSeconds > 0) {
                    $foreground = min($foreground, $call->timeoutSeconds);
                }
                $limit = $process['parent']['limit'] ?? $token->wallLimit($foreground);
                $remaining = null === $limit ? null : $limit - intdiv(hrtime(true) - $process['startedAtNs'], 1_000_000_000);
                if (null !== $remaining && $remaining <= 0) {
                    return ['ok' => false, 'error' => 'Code-mode invocation timed out.'];
                }
                if ('notice' === $request->operation) {
                    $message = $request->arguments['message'] ?? null;
                    if (!\is_string($message) || '' === trim($message) || \strlen($message) > 8192 || !mb_check_encoding($message, 'UTF-8')) {
                        return ['ok' => true, 'result' => ['status' => 'refused', 'reason' => 'invalid_message']];
                    }
                    $identity = 'code-mode-notice:'.$request->invocationId.':'.$request->callId;
                    $this->commands->dispatch(new DeliverBackgroundCompletionDTO($call->runId(), $identity,
                        \sprintf("Intermediate code_mode notice (run_id: %s, tool_call_id: %s).\n\n%s", $call->runId(), $call->toolCallId, $message),
                        origin: ['tool_call_id' => $call->toolCallId, 'retire_on_owner_cancel' => true],
                    ));

                    return ['ok' => true, 'result' => ['status' => 'submitted', 'notice_id' => $identity]];
                }
                if (CodeModeUnsupportedTools::contains($request->name)) {
                    return ['ok' => false, 'error' => CodeModeUnsupportedTools::rejectionMessage($request->name)];
                }
                $owner = new ToolContext($call->runId(), $call->turnNo(), $call->toolCallId, 'code_mode', $token, $remaining, $call->orderIndex, stepId: $call->stepId(), parentModel: $call->parentModel);
                $id = 'code-mode-'.hash('sha256', $request->invocationId.':'.$request->callId);
                $value = $this->contexts->with($owner, fn (): mixed => $this->executor->execute($id, $request->name, $request->arguments, $owner, $token, $remaining));
                if ($value instanceof DeferredToolCompletionOutcome) {
                    return ['child' => $value->deferredId];
                }
                if ($value instanceof ToolExecutionHumanInputSuspension) {
                    return ['ok' => false, 'error' => 'Deferred or interactive results are not supported in nested calls.'];
                }
                if ('code_mode' === $request->name && \is_array($value) && \array_key_exists('code_mode_value', $value)) {
                    $value = $value['code_mode_value'];
                }

                return ['ok' => true, 'result' => CodeModeValueCodec::assertEncodable($value, 'Nested tool result')];
            } catch (\Throwable $error) {
                $message = $error->getMessage();

                return ['ok' => false, 'error' => mb_check_encoding($message, 'UTF-8') ? $message : CodeModeValueCodec::MALFORMED_MESSAGE];
            }
        });
        if (isset($reply['child'])) {
            return;
        }
        $client = Client::connect($this->endpoint);
        try {
            $client->send('code_mode_reply.'.$request->invocationId, json_encode(['id' => $request->callId, ...$reply], \JSON_THROW_ON_ERROR));
        } finally {
            $client->close();
        }
    }
}
