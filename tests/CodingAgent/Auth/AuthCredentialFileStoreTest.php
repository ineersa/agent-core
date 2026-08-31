<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use Ineersa\CodingAgent\Auth\AuthCredentialFileStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\FlockStore;

final class AuthCredentialFileStoreTest extends TestCase
{
    private string $tmpDir;
    private string $authPath;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield-auth-file-store');
        @mkdir($this->tmpDir.'/.hatfield', 0755, true);
        $this->authPath = $this->tmpDir.'/.hatfield/auth.json';
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testAtomicWriteLeaves0600File(): void
    {
        $store = $this->store();

        $store->withLock(static function () use ($store): void {
            $store->set('openai-codex', ['access' => 'a', 'refresh' => 'r', 'expires' => time() + 60]);
        });

        $this->assertFileExists($this->authPath);
        $this->assertSame(0600, fileperms($this->authPath) & 0777);
        $this->assertSame([], glob($this->tmpDir.'/.hatfield/*.tmp.*') ?: []);
    }

    public function testLockKeyIsFileScopedNotProviderScoped(): void
    {
        $seen = new \stdClass();
        $seen->keys = [];
        $inner = new LockFactory(new FlockStore($this->tmpDir));
        $spyFactory = new class($inner, $seen, $this->tmpDir) extends LockFactory {
            public function __construct(
                private LockFactory $inner,
                private \stdClass $seen,
                string $lockDir,
            ) {
                // LockFactory requires a store; unused — we delegate to $inner.
                parent::__construct(new FlockStore($lockDir));
            }

            public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
            {
                $this->seen->keys[] = $resource;

                return $this->inner->createLock($resource, $ttl, $autoRelease);
            }
        };

        $store = new AuthCredentialFileStore($this->authPath, $spyFactory);

        $store->withLock(static fn (): null => null);
        $store->withLock(static fn (): null => null);

        $this->assertSame(
            [AuthCredentialFileStore::LOCK_KEY, AuthCredentialFileStore::LOCK_KEY],
            $seen->keys,
            'Every withLock must use the single file-scoped key, never a provider-derived key',
        );
        $this->assertSame('auth.json', AuthCredentialFileStore::LOCK_KEY);
    }

    /**
     * Documents the race the shared lock closes: unlocked interleaved RMW
     * (read → mutate → write from two providers) drops the first writer's entry.
     */
    public function testUnlockedInterleavedRmwLosesSiblingEntry(): void
    {
        $codex = $this->store();
        $grok = $this->store();

        // Seed both keys under lock so the file starts correct.
        $codex->withLock(static function () use ($codex): void {
            $codex->set('openai-codex', ['access' => 'codex-seed']);
            $codex->set('grok-cli', ['access' => 'grok-seed']);
        });

        // Simulate the old race without the shared lock:
        // both read the full file, each mutates its own key, each writes.
        $codexSnapshot = $codex->readAll();
        $grokSnapshot = $grok->readAll();

        $codexSnapshot['openai-codex'] = ['access' => 'codex-new'];
        $grokSnapshot['grok-cli'] = ['access' => 'grok-new'];

        $codex->writeAll($codexSnapshot); // writes both keys (seed values for grok)
        $grok->writeAll($grokSnapshot);   // overwrites whole file — drops codex-new

        $final = $codex->readAll();
        $this->assertSame('codex-seed', $final['openai-codex']['access'], 'Last writer dropped the sibling update');
        $this->assertSame('grok-new', $final['grok-cli']['access']);
    }

    /**
     * Same interleaving intent, but each mutation runs under withLock —
     * both provider entries survive.
     */
    public function testWithLockSerializesTwoProvidersPreservingBoth(): void
    {
        $codex = $this->store();
        $grok = $this->store();

        $codex->withLock(static function () use ($codex): void {
            $codex->set('openai-codex', ['access' => 'codex-seed']);
            $codex->set('grok-cli', ['access' => 'grok-seed']);
        });

        // Two independent withLock RMWs — second acquires after first releases.
        $codex->withLock(static function () use ($codex): void {
            $data = $codex->readAll();
            $data['openai-codex'] = ['access' => 'codex-new'];
            $codex->writeAll($data);
        });

        $grok->withLock(static function () use ($grok): void {
            $data = $grok->readAll();
            $data['grok-cli'] = ['access' => 'grok-new'];
            $grok->writeAll($data);
        });

        $final = $codex->readAll();
        $this->assertSame('codex-new', $final['openai-codex']['access']);
        $this->assertSame('grok-new', $final['grok-cli']['access']);
    }

    private function store(): AuthCredentialFileStore
    {
        return new AuthCredentialFileStore(
            $this->authPath,
            new LockFactory(new FlockStore($this->tmpDir)),
        );
    }
}
