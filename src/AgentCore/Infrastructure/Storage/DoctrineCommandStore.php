<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Infrastructure\Doctrine\CommandRecord;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/** Pending commands survive worker restarts; completed commands leave no rows. */
final readonly class DoctrineCommandStore implements CommandStoreInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'messenger.transport.native_php_serializer')]
        private SerializerInterface $serializer,
        private LockFactory $lockFactory,
    ) {
    }

    public function enqueue(PendingCommand $command): bool
    {
        return ($this->prepareEnqueue($command))();
    }

    public function prepareEnqueue(PendingCommand $command): \Closure
    {
        // Preserve native PHP DTO semantics without serializing under the DB lock.
        $payload = $this->serializer->encode(new Envelope($command))['body'];
        $hash = hash('sha256', $payload);

        return fn (): bool => $this->withRunLock($command->runId, function () use ($command, $payload, $hash): bool {
            if ($this->has($command->runId, $command->idempotencyKey)) {
                return false;
            }
            $record = new CommandRecord();
            $record->runId = $command->runId;
            $record->idempotencyKey = $command->idempotencyKey;
            $record->payload = $payload;
            $record->payloadHash = $hash;
            $this->insert($record);

            return true;
        });
    }

    public function has(string $runId, string $idempotencyKey): bool
    {
        return [] !== $this->records($runId)->select('c.id')->andWhere('c.idempotencyKey = :key')
            ->setParameter('key', $idempotencyKey)->setMaxResults(1)->getQuery()->getScalarResult();
    }

    public function pending(string $runId): array
    {
        $query = $this->records($runId)->select('c.idempotencyKey, c.payload, c.payloadHash')
            ->orderBy('c.id', 'ASC')->getQuery();
        $pending = [];
        // Scalar streaming avoids registering commands in Doctrine's identity
        // map. Only the requested pending DTOs survive.
        foreach ($query->toIterable([], AbstractQuery::HYDRATE_SCALAR) as $row) {
            $payload = $row['payload'];
            $hash = $row['payloadHash'];
            if (!\is_string($payload) || !\is_string($hash) || !hash_equals($hash, hash('sha256', $payload))) {
                throw new \RuntimeException('Pending command payload is missing or corrupt.');
            }
            $command = $this->serializer->decode(['body' => $payload])->getMessage();
            if (!$command instanceof PendingCommand || $command->runId !== $runId || $command->idempotencyKey !== $row['idempotencyKey']) {
                throw new \RuntimeException('Pending command payload differs from its durable identity.');
            }
            $pending[] = $command;
        }

        return $pending;
    }

    public function countPending(string $runId): int
    {
        return (int) $this->records($runId)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
    }

    public function markApplied(string $runId, string $idempotencyKey): void
    {
        $this->removePending($runId, $idempotencyKey);
    }

    public function markRejected(string $runId, string $idempotencyKey, string $reason): void
    {
        $this->removePending($runId, $idempotencyKey);
    }

    private function removePending(string $runId, string $idempotencyKey): void
    {
        $this->withRunLock($runId, function () use ($runId, $idempotencyKey): void {
            // Recovery can repeat finalization or finalize an immediate command
            // that was never queued. Neither case creates an identity marker.
            $this->records($runId)->delete(CommandRecord::class, 'c')
                ->andWhere('c.idempotencyKey = :key')->setParameter('key', $idempotencyKey)
                ->getQuery()->execute();
        });
    }

    private function records(string $runId): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->from(CommandRecord::class, 'c')
            ->where('c.runId = :run')->setParameter('run', $runId);
    }

    private function insert(CommandRecord $record): void
    {
        try {
            $this->entityManager->persist($record);
            $this->entityManager->flush();
        } finally {
            $this->entityManager->detach($record);
        }
    }

    /** @template T
     * @param callable(): T $operation
     *
     * @return T
     */
    private function withRunLock(string $runId, callable $operation): mixed
    {
        $lock = $this->lockFactory->createLock('hatfield-command-'.$runId, ttl: null);
        if (!$lock->acquire(true)) {
            throw new \RuntimeException('Unable to acquire command storage ownership.');
        }
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
