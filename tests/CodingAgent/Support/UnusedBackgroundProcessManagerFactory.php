<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcessRepository;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Psr\Log\NullLogger;

/** Constructor dependency for protocol tests that never query native processes. */
final class UnusedBackgroundProcessManagerFactory
{
    public static function create(EntityManagerInterface $entityManager, ManagerRegistry $registry): BackgroundProcessManager
    {
        $config = new BackgroundProcessConfig();
        $logger = new NullLogger();

        return new BackgroundProcessManager(
            new ProcessStore($entityManager, new BackgroundProcessRepository($registry), $logger),
            new ProcessLifecycle($config, $logger),
            $config,
            $logger,
        );
    }
}
