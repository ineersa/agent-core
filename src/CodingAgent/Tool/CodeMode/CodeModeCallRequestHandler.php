<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionStatusEnum;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionHumanInputSuspension;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\RunCancellationToken;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeCallRequestDTO;
use Ineersa\CodingAgent\Tool\CodeModeTool;
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
        #[Autowire(service: 'agent.command.bus')] private MessageBusInterface $commands,
        private CodeModeReplyTransportFactory $replies,
    ) {
    }

    public function __invoke(CodeModeCallRequestDTO $request): void
    {
        $rootId = $request->rootId ?? $request->invocationId;
        $metadata = $this->deferred->findMetadataByDeferredId($rootId);
        if (null === $metadata || DeferredToolCompletionStatusEnum::Pending !== $metadata->status || null === $metadata->readyAt || 'code_mode' !== $metadata->reference->toolName) {
            throw new UnrecoverableMessageHandlingException('Code-mode invocation is not ready.');
        }
        $ref = $metadata->reference;
        $call = $this->batches->loadCall($ref->runId, $ref->turnNo, $ref->stepId, $ref->toolCallId);
        $process = $this->processes->invocation($ref->runId, $request->invocationId);
        if (null === $process || $rootId !== $request->invocationId || null === $call || 'code_mode' !== $call->toolName
            || $ref->attempt !== $call->attempt() || $ref->idempotencyKey !== $call->idempotencyKey() || $ref->orderIndex !== $call->orderIndex) {
            throw new UnrecoverableMessageHandlingException('Code-mode invocation evidence does not match the canonical call.');
        }
        $publish = fn (array $reply) => $this->replies->publish($request->invocationId, $request->callId, $ref, $reply);
        if (0 !== $this->validator->validate($request)->count()
            || ([] !== $request->arguments && array_is_list($request->arguments))) {
            $this->replies->send($request->invocationId, $request->callId, $publish(['ok' => false, 'error' => 'Invalid code-mode call request.']));

            return;
        }
        if ('admit' === $request->operation) {
            if ($process['fenced'] || null !== $metadata->interruptionReason) {
                $reply = ['ok' => false, 'error' => 'Code-mode launch is no longer admitted.'];
            } else {
                $reply = ['ok' => true];
            }
            $this->replies->send($request->invocationId, $request->callId, $publish($reply));

            return;
        }
        $execute = function () use ($request, $call, $rootId): array {
            try {
                $explicit = $call->args['timeout_seconds'] ?? null;
                $token = new CodeModeCancellationToken(new RunCancellationToken($this->status, $call->runId()), $this->deferred, $rootId,
                    fn (bool $backgrounded): bool => $this->processes->hasExited($call->runId(), $request->invocationId)
                        || ($this->processes->deadline($call->runId(), $request->invocationId, $backgrounded, \is_int($explicit) ? $explicit : null, $call->timeoutSeconds) ?? \PHP_INT_MAX) <= hrtime(true));
                if ($token->isCancellationRequested()) {
                    return ['ok' => false, 'error' => 'Code-mode invocation cancelled or expired.'];
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
                if (CodeModeTool::NAME === $request->name || CodeModeUnsupportedTools::contains($request->name)) {
                    return ['ok' => false, 'error' => CodeModeUnsupportedTools::rejectionMessage($request->name)];
                }
                $owner = new ToolContext($call->runId(), $call->turnNo(), $call->toolCallId, 'code_mode', $token, null, $call->orderIndex, stepId: $call->stepId(), parentModel: $call->parentModel);
                $id = 'code-mode-'.hash('sha256', $request->invocationId.':'.$request->callId);
                $value = $this->contexts->with($owner, fn (): mixed => $this->executor->execute($id, $request->name, $request->arguments, $owner, $token));
                if ($value instanceof DeferredToolCompletionOutcome || $value instanceof ToolExecutionHumanInputSuspension) {
                    return ['ok' => false, 'error' => 'Deferred or interactive results are not supported in nested calls.'];
                }

                return ['ok' => true, 'result' => CodeModeValueCodec::assertEncodable($value, 'Nested tool result')];
            } catch (\Throwable $error) {
                $message = $error->getMessage();

                return ['ok' => false, 'error' => mb_check_encoding($message, 'UTF-8') ? $message : CodeModeValueCodec::MALFORMED_MESSAGE];
            }
        };
        $this->replies->send($request->invocationId, $request->callId, $publish($execute()));
    }
}
