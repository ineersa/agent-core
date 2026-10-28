<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationCodec;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\RunCancellationToken;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeUnsupportedTools;
use Ineersa\Hatfield\ExtensionApi\Tool\OutputReferenceDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\RegisteredToolInvokerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationResultDTO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/** Standalone invocations retain native policy, cancellation and result processing. */
final readonly class ExtensionToolInvoker implements RegisteredToolInvokerInterface
{
    public function __construct(
        #[AutowireServiceClosure(ToolExecutorInterface::class)] private \Closure $executor,
        private StackToolExecutionContextAccessor $context,
        private RunOperationalStatusReaderInterface $status,
        private EventStoreInterface $events,
        private ToolExecutionResultStore $results,
        private ToolPayloadStoreInterface $payloads,
        private DenormalizerInterface $serializer,
        #[Autowire('%env(HATFIELD_SESSION_ID)%')] private string $controllerId,
    ) {
    }

    public function assertOwner(string $runId): void
    {
        $owner = $this->context->current()?->runId() ?? $this->controllerId;
        if ('' === $owner || $runId !== $owner || null === $this->status->findOperationalStatus($runId)) {
            throw new \InvalidArgumentException('Extension invocation requires the current owner session.');
        }
    }

    public function invoke(ToolInvocationRequestDTO $request): ToolInvocationResultDTO
    {
        $this->assertOwner($request->runId);
        if (RunStatus::Failed === $this->status->findOperationalStatus($request->runId)?->status) {
            throw new \RuntimeException('Standalone invocation requires an owner that has not failed.');
        }
        if ('' === trim($request->invocationId) || true === ($request->arguments['backgrounded'] ?? false)) {
            throw new \InvalidArgumentException('Standalone invocation requires an identity and cannot detach.');
        }
        if (CodeModeUnsupportedTools::contains($request->toolName)) {
            $text = 'Standalone invocation cannot launch deferred work or request human input.';

            return new ToolInvocationResultDTO($request->invocationId, $request->toolName, true, false, [['type' => 'text', 'text' => $text]], $text);
        }
        $turn = $this->context->current()?->turnNo() ?? 0;
        if (0 === $turn) {
            foreach ($this->events->reverseFor($request->runId) as $event) {
                $turn = $event->turnNo;
                break;
            }
        }
        $token = new RunCancellationToken($this->status, $request->runId);
        // A standalone call has no canonical caller batch. Nested restrictions
        // prevent launching deferred child work that would require that batch.
        $result = $this->context->with(new ToolContext($request->runId, $turn, $request->invocationId, $request->toolName, $token, null, allowsHumanInput: false, retainOutput: $request->retainOutput), fn () => ($this->executor)()->execute(new ToolCall(
            $request->invocationId, $request->toolName, $request->arguments, 0, $request->runId,
            context: ['turn_no' => $turn, 'tools_ref' => 'registered', 'allows_human_input' => false,
                'retain_output' => $request->retainOutput, 'cancel_token' => $token],
        )));
        $details = \is_array($result->details) ? $result->details : [];
        $notifications = ModelNotificationCodec::denormalizeFromDetails($this->serializer, $details);
        $envelope = new ToolCallResult($request->runId, $turn, $request->invocationId, 1, $request->invocationId, $request->invocationId, 0,
            ['tool_name' => $result->toolName, 'content' => $result->content, 'details' => $details], $result->isError);
        $text = (new AgentMessageNormalizer())->toolMessage($envelope, $notifications)->content[0]['text'];
        $outputs = [];
        foreach ($request->retainOutput ? $this->payloads->attachmentReferences($request->runId, $request->invocationId) : [] as $reference) {
            $outputs[] = new OutputReferenceDTO($reference['path'], $reference['byte_count'], $reference['label'] ?? 'output');
        }
        $cap = $details['output_cap'] ?? null;
        if (\is_array($cap) && \is_string($cap['saved_path'] ?? null) && !array_any($outputs, static fn (OutputReferenceDTO $reference): bool => $reference->path === $cap['saved_path'])) {
            $outputs[] = new OutputReferenceDTO($cap['saved_path'], $cap['byte_count'] ?? filesize($cap['saved_path']));
        }
        $this->results->releaseCompleted($request->runId, $request->invocationId, $request->toolName, null);

        return new ToolInvocationResultDTO($request->invocationId, $request->toolName, $result->isError,
            true === ($details['cancelled'] ?? false) || true === ($details['stale_due_to_cancel'] ?? false), $result->content, $text, $outputs);
    }
}
