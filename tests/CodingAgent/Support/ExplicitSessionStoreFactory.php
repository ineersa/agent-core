<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** Store fixture for protocol tests that supply already allocated run IDs. */
final class ExplicitSessionStoreFactory
{
    public static function create(EntityManagerInterface $entityManager, string $cwd): HatfieldSessionStore
    {
        return new HatfieldSessionStore(
            new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: $cwd),
            $entityManager,
            new EventDispatcher(),
        );
    }
}
