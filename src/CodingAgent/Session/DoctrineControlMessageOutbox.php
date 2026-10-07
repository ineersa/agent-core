<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Contract\ControlMessageOutboxInterface;
use Ineersa\AgentCore\Domain\Coordination\ControlOutboxDestination;
use Ineersa\AgentCore\Domain\Coordination\PendingControlMessageDTO;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/** Durable small control obligations. Never stores invocation request/result bodies. */
final readonly class DoctrineControlMessageOutbox implements ControlMessageOutboxInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function enqueue(string $runId, string $identity, string $destination, object $payload): void
    {
        $this->sanitize($runId, $identity, $destination);
        $existing = $this->connection->fetchAssociative(
            'SELECT destination, payload_json FROM control_message_outbox WHERE run_id = ? AND identity = ?',
            [$runId, $identity],
        );
        $encoded = json_encode((new PhpSerializer())->encode(new Envelope($payload)), \JSON_THROW_ON_ERROR);
        if (false !== $existing) {
            if ($existing['destination'] !== $destination || $existing['payload_json'] !== $encoded) {
                throw new \RuntimeException('Control outbox identity collides with a different obligation.');
            }

            return;
        }
        $this->connection->insert('control_message_outbox', [
            'run_id' => $runId,
            'identity' => $identity,
            'destination' => $destination,
            'payload_json' => $encoded,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function pendingForRun(string $runId): array
    {
        $this->sanitizeRun($runId);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT identity, destination, payload_json FROM control_message_outbox WHERE run_id = ? ORDER BY identity',
            [$runId],
        );
        $pending = [];
        foreach ($rows as $row) {
            $encoded = json_decode($row['payload_json'], true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($encoded)) {
                throw new \RuntimeException('Control outbox payload is corrupt.');
            }
            $pending[] = new PendingControlMessageDTO(
                $runId,
                $row['identity'],
                $row['destination'],
                (new PhpSerializer())->decode($encoded)->getMessage(),
            );
        }

        return $pending;
    }

    public function acknowledge(string $runId, string $identity): void
    {
        $this->sanitize($runId, $identity, ControlOutboxDestination::COMMAND);
        $this->connection->delete('control_message_outbox', ['run_id' => $runId, 'identity' => $identity]);
    }

    private function sanitize(string $runId, string $identity, string $destination): void
    {
        $this->sanitizeRun($runId);
        if ('' === $identity || \strlen($identity) > 128 || str_contains($identity, "\0")) {
            throw new \InvalidArgumentException('Invalid control outbox identity.');
        }
        if (ControlOutboxDestination::COMMAND !== $destination && ControlOutboxDestination::EXECUTION !== $destination) {
            throw new \InvalidArgumentException('Invalid control outbox destination.');
        }
    }

    private function sanitizeRun(string $runId): void
    {
        if ('' === $runId || str_contains($runId, '/') || str_contains($runId, '\\') || str_contains($runId, "\0")) {
            throw new \InvalidArgumentException('Invalid control outbox run identity.');
        }
    }
}
