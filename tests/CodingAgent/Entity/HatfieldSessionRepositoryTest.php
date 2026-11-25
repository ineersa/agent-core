<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Entity;

use Ineersa\CodingAgent\Entity\HatfieldSessionRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

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
}
