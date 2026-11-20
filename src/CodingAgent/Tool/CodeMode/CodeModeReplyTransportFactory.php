<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
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

    public function send(string $invocationId, string $callId, ToolPayloadReferenceDTO $reference): void
    {
        $transport = $this->create($invocationId);
        try {
            $transport->send(new Envelope(new CodeModeReplyDTO($callId, $reference)));
        } finally {
            $transport->close();
        }
    }

    public function create(string $invocationId): Transport
    {
        return $this->factory->createTransport('sqlite-queue://code_mode_reply.'.$invocationId, ['endpoint' => $this->endpoint], $this->serializer);
    }
}
