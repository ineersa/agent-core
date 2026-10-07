<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\ControlMessageOutboxInterface;
use Ineersa\AgentCore\Domain\Coordination\PendingControlMessageDTO;

final class InMemoryControlMessageOutbox implements ControlMessageOutboxInterface
{
    /** @var array<string, array{destination: string, payload: object}> */
    private array $rows = [];

    public function enqueue(string $runId, string $identity, string $destination, object $payload): void
    {
        $key = $runId.'|'.$identity;
        if (isset($this->rows[$key])) {
            if ($this->rows[$key]['destination'] !== $destination || serialize($this->rows[$key]['payload']) !== serialize($payload)) {
                throw new \RuntimeException('Control outbox identity collides with a different obligation.');
            }

            return;
        }
        $this->rows[$key] = ['destination' => $destination, 'payload' => $payload];
    }

    public function pendingForRun(string $runId): array
    {
        $pending = [];
        foreach ($this->rows as $key => $row) {
            if (!str_starts_with($key, $runId.'|')) {
                continue;
            }
            $pending[] = new PendingControlMessageDTO(
                $runId,
                substr($key, \strlen($runId) + 1),
                $row['destination'],
                $row['payload'],
            );
        }

        return $pending;
    }

    public function acknowledge(string $runId, string $identity): void
    {
        unset($this->rows[$runId.'|'.$identity]);
    }
}
