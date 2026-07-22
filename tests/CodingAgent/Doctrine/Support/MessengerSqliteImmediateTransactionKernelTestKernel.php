<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Doctrine\Support;

use Ineersa\CodingAgent\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Minimal KernelTestCase subclass so test subprocesses can boot the container.
 *
 * @internal
 */
final class MessengerSqliteImmediateTransactionKernelTestKernel extends KernelTestCase
{
    public static function bootForSqliteWorker(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => false]);
    }

    public static function getContainerForSqliteWorker(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        return self::getContainer();
    }

    protected static function createKernel(array $options = []): Kernel
    {
        $env = $options['environment'] ?? 'test';
        $debug = (bool) ($options['debug'] ?? false);

        return new Kernel($env, $debug);
    }
}
