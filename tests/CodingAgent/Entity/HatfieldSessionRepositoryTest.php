<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Entity;

use Ineersa\CodingAgent\Entity\HatfieldSessionRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

final class HatfieldSessionRepositoryTest extends IsolatedKernelTestCase
{
    public function testCatalogPageLimitsRowsInNativeOrder(): void
    {
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $repository = self::getContainer()->get(HatfieldSessionRepository::class);
        $store->createSession('one');
        $store->createSession('two');
        $store->createSession('three');

        $all = $repository->findForCatalog();
        $this->assertCount(3, $all);
        $this->assertSame([$all[1]], $repository->findForCatalog(limit: 1, offset: 1));
    }

    public function testFractionalBoundsCompareAgainstWholeSecondActivity(): void
    {
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $repository = self::getContainer()->get(HatfieldSessionRepository::class);
        $at = new \DateTimeImmutable('2026-06-10 12:00:00');
        $originalClock = Clock::get();
        try {
            Clock::set(new MockClock($at));
            $id = $store->createSession('fractional bounds');
        } finally {
            Clock::set($originalClock);
        }

        $fractional = $at->modify('+1 microsecond');
        $this->assertSame([], $repository->findForCatalog(since: $fractional));
        $this->assertSame([(int) $id], array_column($repository->findForCatalog(until: $fractional), 'id'));
    }
}
