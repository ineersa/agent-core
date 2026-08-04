<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Thesis: HeadlessController single-owner locks use a dedicated FlockStore rooted
 * under the project %app.cwd% tree, not FrameworkBundle's kernel.project_dir
 * partitioned default flock. Source/worktree/PHAR controllers with the same
 * runtime project CWD must converge on the same OS lock directory.
 */
final class HeadlessControllerSessionOwnerLockFactoryTest extends IsolatedKernelTestCase
{
    public function testSessionOwnerLockFactoryUsesAppCwdFlockStoreDirectory(): void
    {
        $container = self::getContainer();

        $appCwd = (string) $container->getParameter('app.cwd');
        $expectedDir = $appCwd.'/.hatfield/tmp/controller-locks';
        $this->assertSame(
            $expectedDir,
            (string) $container->getParameter('hatfield.controller.session_owner_lock_dir'),
        );

        /** @var LockFactory $sessionOwnerFactory */
        $sessionOwnerFactory = $container->get('test.hatfield.controller.session_owner.lock_factory');
        $this->assertInstanceOf(LockFactory::class, $sessionOwnerFactory);

        /** @var LockFactory $defaultFactory */
        $defaultFactory = $container->get(LockFactory::class);
        $this->assertNotSame(
            $sessionOwnerFactory,
            $defaultFactory,
            'Controller owner locks must not reuse the global default LockFactory.',
        );

        // Prove the store path is the project-scoped directory by creating a lock file there.
        $resource = 'hatfield.controller.session.'.hash('sha256', $appCwd."\0".'wiring-proof');
        $lock = $sessionOwnerFactory->createLock($resource, ttl: null, autoRelease: true);
        $this->assertTrue($lock->acquire(blocking: false));

        $this->assertDirectoryExists($expectedDir);
        $files = glob($expectedDir.'/sf.*.lock') ?: [];
        $this->assertNotSame([], $files, 'FlockStore must create lock files under %app.cwd%/.hatfield/tmp/controller-locks.');

        // Default FrameworkBundle flock is under sys_get_temp_dir()/symfony-lock/<project_dir_hash>.
        $defaultStore = new FlockStore(implode('/', [
            sys_get_temp_dir(),
            'symfony-lock',
            hash('xxh64', (string) $container->getParameter('kernel.project_dir')),
        ]));
        $defaultLock = (new LockFactory($defaultStore))->createLock($resource, ttl: null, autoRelease: true);
        $this->assertTrue(
            $defaultLock->acquire(blocking: false),
            'Same resource must still be free on the project_dir-partitioned default store — proving stores diverge.',
        );
        $defaultLock->release();
        $lock->release();
    }
}
