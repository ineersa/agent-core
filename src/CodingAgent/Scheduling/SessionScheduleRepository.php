<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Scheduling;

use Ineersa\CodingAgent\Scheduling\DTO\SessionScheduleDTO;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Uid\Uuid;

/** Shared live definitions, not a durable schedule-restoration contract. */
final readonly class SessionScheduleRepository
{
    public function __construct(private FilesystemAdapter $cache, private LockFactory $locks)
    {
    }

    /** @return array<string, SessionScheduleDTO> */
    public function all(string $runId): array
    {
        $value = $this->cache->getItem(hash('sha256', $runId))->get();

        /** @var array<string, SessionScheduleDTO> $definitions */
        $definitions = \is_array($value) ? $value : [];

        return array_filter($definitions, fn (SessionScheduleDTO $definition): bool => $definition->controllerId === $this->controllerId($definition->controllerRunId));
    }

    public function controllerId(string $runId): ?string
    {
        $value = $this->cache->getItem('controller-'.hash('sha256', $runId))->get();

        return \is_string($value) ? $value : null;
    }

    public function activate(string $runId): void
    {
        $item = $this->cache->getItem('controller-'.hash('sha256', $runId));
        $item->set(Uuid::v7()->toRfc4122());
        if (!$this->cache->save($item)) {
            throw new \RuntimeException('Cannot activate session scheduling storage.');
        }
    }

    public function deactivate(string $runId): void
    {
        $this->cache->deleteItem('controller-'.hash('sha256', $runId));
    }

    public function save(SessionScheduleDTO $definition): void
    {
        $this->change($definition->runId, $definition->id, $definition);
    }

    public function cancel(string $runId, string $id): void
    {
        $this->change($runId, $id, null);
    }

    public function clear(string $runId): void
    {
        $this->cache->deleteItem(hash('sha256', $runId));
    }

    public function recurring(SessionScheduleDTO $definition): RecurringMessage
    {
        return RecurringMessage::every($definition->every, new SessionScheduleMessageProvider($definition->runId, $definition->id), $definition->createdAt, $definition->expiresAt ?? new \DateTimeImmutable('3000-01-01'));
    }

    private function change(string $runId, string $id, ?SessionScheduleDTO $definition): void
    {
        $lock = $this->locks->createLock('session-schedule-'.hash('sha256', $runId));
        $lock->acquire(true);
        try {
            $definitions = $this->all($runId);
            unset($definitions[$id]);
            if (null !== $definition) {
                $definitions[$id] = $definition;
            }
            $item = $this->cache->getItem(hash('sha256', $runId));
            $item->set($definitions);
            if (!$this->cache->save($item)) {
                throw new \RuntimeException('Cannot save session schedules.');
            }
        } finally {
            $lock->release();
        }
    }
}
