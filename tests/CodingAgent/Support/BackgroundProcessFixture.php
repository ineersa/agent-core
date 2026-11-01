<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Psr\Container\ContainerInterface;

/** Mutable fixture setup is not part of the runtime snapshot API. */
final class BackgroundProcessFixture
{
    public static function finish(BackgroundProcess $record, ?int $exitCode, \DateTimeImmutable $finishedAt): void
    {
        $record->exitCode = $exitCode;
        $record->finishedAt = $finishedAt;
        $record->status = BackgroundProcessStatusEnum::Stopped === $record->status ? $record->status : BackgroundProcessStatusEnum::Finished;
    }

    public static function record(ContainerInterface $container, int $id): ?BackgroundProcess
    {
        $manager = $container->get(EntityManagerInterface::class);
        if (false === $manager->getConnection()->fetchOne('SELECT id FROM background_process WHERE id = ?', [$id])) {
            return null;
        }
        $record = $manager->find(BackgroundProcess::class, $id);
        if (null !== $record) {
            $manager->refresh($record);
        }

        return $record;
    }

    public static function delete(ContainerInterface $container, int $id): bool
    {
        return 1 === $container->get(EntityManagerInterface::class)->getConnection()->delete('background_process', ['id' => $id]);
    }
}
