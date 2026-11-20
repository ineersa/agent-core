<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Entity\CodeModeCallRepository;
use Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeReplyDTO;
use Ineersa\SqliteQueue\Messenger\Transport;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final readonly class CodeModeReplyTransportFactory
{
    public function __construct(
        private TransportFactory $factory,
        private ToolPayloadStoreInterface $payloads,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private CodeModeProcessOwner $processes,
        private CodeModeCallRepository $calls,
        #[Autowire(service: 'messenger.transport.symfony_serializer')] private SerializerInterface $serializer,
        #[Autowire('%env(HATFIELD_SQLITE_QUEUE_ENDPOINT)%')] private string $endpoint,
    ) {
    }

    /** @param array<string, mixed> $reply */
    public function publish(string $invocationId, string $callId, ToolPayloadReferenceDTO $root, array $reply): ToolPayloadReferenceDTO
    {
        $id = 'code-mode-'.hash('sha256', $invocationId.':'.$callId);
        try {
            $encoded = json_encode(CodeModeValueCodec::assertEncodable($reply, 'Code-mode result'), \JSON_THROW_ON_ERROR);
            if (\strlen($encoded) > 8_388_608) {
                throw new \RuntimeException('Code-mode result exceeds 8388608 bytes.');
            }
        } catch (\RuntimeException|\JsonException $error) {
            $reply = ['ok' => false, 'error' => $error->getMessage()];
        }

        return $this->payloads->publish(new ToolCallResult(
            $root->runId, $root->turnNo, $root->stepId, $root->attempt, $id, $id, $root->orderIndex,
            $reply, true !== ($reply['ok'] ?? false),
        ));
    }

    /** @param array<string, mixed> $reply */
    public function publishReturn(string $invocationId, string $rootId, array $reply): ToolPayloadReferenceDTO
    {
        $root = $this->deferred->findMetadataByDeferredId($rootId)
            ?? throw new \RuntimeException('Missing code-mode return correlation.');

        return $this->publish($invocationId, 'return', $root->reference, $reply);
    }

    public function send(string $invocationId, string $callId, ToolPayloadReferenceDTO $reference): void
    {
        $transport = $this->create($invocationId);
        try {
            $transport->send(new Envelope(new CodeModeReplyDTO($callId, $reference)));
        } finally {
            $transport->close();
        }
    }

    public function isAdmitted(string $invocationId, string $rootId): bool
    {
        $root = $this->deferred->findMetadataByDeferredId($rootId);
        if (null === $root || 'pending' !== $root->status || null === $root->readyAt
            || null !== $root->interruptionReason || 'code_mode' !== $root->reference->toolName) {
            return false;
        }
        $process = $this->processes->invocation($root->reference->runId, $invocationId);
        if (null === $process) {
            return false;
        }
        $parent = $process['parent'];

        return $invocationId === $rootId ? null === $parent : null !== $parent && $rootId === $parent['rootId']
            && $invocationId === $this->calls->childId($parent['invocationId'], $parent['callId'])
            && null === $this->calls->reply($parent['invocationId'], $parent['callId']);
    }

    public function create(string $invocationId): Transport
    {
        return $this->factory->createTransport('sqlite-queue://code_mode_reply.'.$invocationId, ['endpoint' => $this->endpoint], $this->serializer);
    }
}
