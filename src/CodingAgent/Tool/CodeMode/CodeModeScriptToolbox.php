<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionHumanInputSuspension;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\RunCancellationToken;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Tool\CodeModeTool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class CodeModeScriptToolbox
{
    public function __construct(
        private DeferredToolCompletionRepositoryInterface $deferred,
        private CodeModeProcessOwner $processes,
        private RunOperationalStatusReaderInterface $status,
        private CodeModeNestedToolExecutor $executor,
        #[Autowire(service: 'agent.command.bus')] private MessageBusInterface $commands,
    ) {
    }

    public function open(string $runId, string $invocationId): ToolContext
    {
        $metadata = $this->deferred->findMetadataByDeferredId($invocationId);
        $process = $this->processes->invocation($runId, $invocationId);
        $call = $this->deferred->findByDeferredId($invocationId);
        if (null === $metadata?->readyAt || 'pending' !== $metadata->status || $runId !== $metadata->reference->runId
            || 'code_mode' !== $metadata->reference->toolName || null === $process || $process['fenced'] || null === $call) {
            throw new \RuntimeException('Code-mode invocation is not admitted.');
        }
        $explicit = $call->arguments['timeout_seconds'] ?? null;
        $token = new CodeModeCancellationToken(new RunCancellationToken($this->status, $runId), $this->deferred, $invocationId,
            fn (bool $backgrounded): bool => ($this->processes->deadline($runId, $invocationId, $backgrounded, \is_int($explicit) ? $explicit : null, $call->timeoutSeconds) ?? \PHP_INT_MAX) <= hrtime(true));
        $owner = new ToolContext($runId, $call->turnNo, $call->toolCallId, 'code_mode', $token, null, $call->orderIndex, stepId: $call->stepId, allowsHumanInput: false);
        $this->checkCancellation($owner);

        return $owner;
    }

    /** @param array<string, mixed> $arguments */
    public function tool(ToolContext $owner, string $name, array $arguments): mixed
    {
        $this->checkCancellation($owner);
        if ([] !== $arguments && array_is_list($arguments)) {
            throw new \RuntimeException('Invalid code-mode call request.');
        }
        if (\in_array($name, [CodeModeTool::NAME, 'agent_steer', 'notify_parent'], true) || CodeModeUnsupportedTools::contains($name)) {
            throw new \RuntimeException(CodeModeUnsupportedTools::rejectionMessage($name));
        }
        $value = $this->executor->execute('code-mode-'.bin2hex(random_bytes(16)), $name, $arguments, $owner, $owner->cancellationToken());
        if ($value instanceof DeferredToolCompletionOutcome || $value instanceof ToolExecutionHumanInputSuspension) {
            throw new \RuntimeException('Deferred or interactive results are not supported in nested calls.');
        }

        return CodeModeValueCodec::assertEncodable($value, 'Nested tool result');
    }

    /** @return array<string, string> */
    public function notice(ToolContext $owner, string $invocationId, string $message): array
    {
        $this->checkCancellation($owner);
        if ('' === trim($message) || \strlen($message) > 8192 || !mb_check_encoding($message, 'UTF-8')) {
            return ['status' => 'refused', 'reason' => 'invalid_message'];
        }
        $identity = 'code-mode-notice:'.$invocationId.':'.bin2hex(random_bytes(8));
        $this->commands->dispatch(new DeliverBackgroundCompletionDTO($owner->runId(), $identity,
            \sprintf("Intermediate code_mode notice (run_id: %s, tool_call_id: %s).\n\n%s", $owner->runId(), $owner->toolCallId(), $message),
            origin: ['tool_call_id' => $owner->toolCallId(), 'retire_on_owner_cancel' => true],
        ));

        return ['status' => 'submitted', 'notice_id' => $identity];
    }

    private function checkCancellation(ToolContext $owner): void
    {
        if ($owner->cancellationToken()->isCancellationRequested()) {
            throw new \RuntimeException('Code-mode invocation cancelled or expired.');
        }
    }
}
