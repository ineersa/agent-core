<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionStatusEnum;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
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
        private ToolSetResolverInterface $toolSets,
        #[Autowire(service: 'agent.command.bus')] private MessageBusInterface $commands,
        private CodeModeReplyTransportFactory $replies,
    ) {
    }

    public function __invoke(CodeModeCallRequestDTO $request): void
    {
        $rootId = $request->rootId ?? $request->invocationId;
        $metadata = $this->deferred->findMetadataByDeferredId($rootId);
        $runId = $metadata?->reference->runId ?? $request->runId;
        $process = null === $runId ? null : $this->processes->invocation($runId, $request->invocationId);
        if (null === $process || null === $runId || $rootId !== $request->invocationId) {
            throw new UnrecoverableMessageHandlingException('Code-mode invocation is not ready.');
        }
        $standaloneTurn = $process['standaloneTurn'];
        $policy = $process['policy'];
        $call = null;
        if (null === $metadata && null !== $standaloneTurn) {
            $ref = CodeModeTerminalIdentity::forInvocation($runId, $rootId);
        } else {
            if (null === $metadata || DeferredToolCompletionStatusEnum::Pending !== $metadata->status || null === $metadata->readyAt || CodeModeTool::NAME !== $metadata->reference->toolName) {
                throw new UnrecoverableMessageHandlingException('Code-mode invocation is not ready.');
            }
            $ref = $metadata->reference;
            $call = $this->batches->loadCall($ref->runId, $ref->turnNo, $ref->stepId, $ref->toolCallId);
            if (null === $call || CodeModeTool::NAME !== $call->toolName || $ref->attempt !== $call->attempt() || $ref->idempotencyKey !== $call->idempotencyKey() || $ref->orderIndex !== $call->orderIndex) {
                throw new UnrecoverableMessageHandlingException('Code-mode invocation evidence does not match the canonical call.');
            }
        }
        $turn = $call?->turnNo() ?? $standaloneTurn ?? 0;
        $ownerId = $call->toolCallId ?? $rootId;
        $respond = function (array $reply) use ($runId, $request, $ref): void {
            $body = $this->processes->withReplyWorkspace($runId, $request->invocationId,
                fn (string $workspace) => $this->replies->publish($workspace, $ref, $reply));
            if (null !== $body) {
                $this->replies->send($request->invocationId, $request->callId, $body);
            }
        };
        if (0 !== $this->validator->validate($request)->count()
            || ([] !== $request->arguments && array_is_list($request->arguments))) {
            $respond(['ok' => false, 'error' => 'Invalid code-mode call request.']);

            return;
        }
        if ('admit' === $request->operation) {
            if ($process['fenced'] || isset($process['interruption']) || null !== $metadata?->interruptionReason) {
                $reply = ['ok' => false, 'error' => 'Code-mode launch is no longer admitted.'];
            } else {
                $reply = ['ok' => true];
            }
            $respond($reply);

            return;
        }
        $execute = function () use ($request, $call, $rootId, $policy, $runId, $turn, $ownerId): array {
            try {
                $parent = new RunCancellationToken($this->status, $runId);
                $unavailable = fn (bool $backgrounded): bool => $this->processes->hasExited($runId, $rootId)
                    || isset($this->processes->invocation($runId, $rootId)['interruption'])
                    || ($this->processes->deadline($runId, $rootId, $backgrounded) ?? \PHP_INT_MAX) <= hrtime(true);
                $token = null !== $call ? new CodeModeCancellationToken($parent, $this->deferred, $rootId, $unavailable)
                    : new class($parent, $unavailable) implements CancellationTokenInterface {
                        public function __construct(private RunCancellationToken $parent, private \Closure $unavailable)
                        {
                        }

                        public function isCancellationRequested(): bool
                        {
                            return $this->parent->isCancellationRequested() || ($this->unavailable)(false);
                        }
                    };
                if ($token->isCancellationRequested()) {
                    return ['ok' => false, 'error' => 'Code-mode invocation cancelled or expired.'];
                }
                if ('notice' === $request->operation) {
                    $message = $request->arguments['message'] ?? null;
                    if (!\is_string($message) || '' === trim($message) || \strlen($message) > 8192 || !mb_check_encoding($message, 'UTF-8')) {
                        return ['ok' => true, 'result' => ['status' => 'refused', 'reason' => 'invalid_message']];
                    }
                    $identity = 'code-mode-notice:'.$request->invocationId.':'.$request->callId;
                    $this->commands->dispatch(new DeliverBackgroundCompletionDTO($runId, $identity,
                        \sprintf("Intermediate code_mode notice (run_id: %s, tool_call_id: %s).\n\n%s", $runId, $ownerId, $message),
                        origin: ['tool_call_id' => $ownerId, 'retire_on_owner_cancel' => true],
                    ));

                    return ['ok' => true, 'result' => ['status' => 'submitted', 'notice_id' => $identity]];
                }
                if (CodeModeTool::NAME === $request->name || CodeModeUnsupportedTools::contains($request->name)) {
                    return ['ok' => false, 'error' => CodeModeUnsupportedTools::rejectionMessage($request->name)];
                }
                $active = $this->toolSets->resolve($call->toolsRef ?? 'registered', $turn, $runId);
                if (!\in_array($request->name, $active->allowListNames, true)) {
                    return ['ok' => false, 'error' => \sprintf('Tool "%s" is not in the active execution allowlist.', $request->name)];
                }
                $owner = new ToolContext($runId, $turn, $ownerId, 'code_mode', $token, null, $call->orderIndex ?? 0, stepId: $call?->stepId(), parentModel: $call?->parentModel, retainOutput: $policy['retainOutput'], attachmentOwnerToolCallId: $policy['attachmentOwner']);
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
        $respond($execute());
    }
}
