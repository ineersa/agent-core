<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Store fixture for protocol tests that supply already allocated run IDs. */
final class ExplicitSessionStoreFactory
{
    public static function create(EntityManagerInterface $entityManager, string $cwd): HatfieldSessionStore
    {
        return new HatfieldSessionStore(
            new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: $cwd),
            $entityManager,
            new EventDispatcher(), storageLocks: new LockFactory(new FlockStore()));
    }
}
