<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionToolBatchStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SessionToolBatchStoreTest extends TestCase
{
    private string $projectDir = '';
    private SessionToolBatchStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = TestDirectoryIsolation::createOsTempDir('session-tool-batch');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $hatfieldSessionStore = new HatfieldSessionStore($appConfig, $entityManager);

        $this->store = new SessionToolBatchStore(
            $hatfieldSessionStore,
            new LockFactory(new FlockStore()),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testSaveLoadMutateDeleteIsolatesCompositeKeys(): void
    {
        $batchA = ['expected_order' => ['c1' => 0], 'call_data' => [], 'pending_queue' => ['c1'], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 2];
        $batchB = ['expected_order' => ['c2' => 0], 'call_data' => [], 'pending_queue' => ['c2'], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 4];

        $this->store->save('run-1', 1, 'step-a', $batchA);
        $this->store->save('run-1', 1, 'step-b', $batchB);

        $this->assertSame($batchA, $this->store->load('run-1', 1, 'step-a'));
        $this->assertSame($batchB, $this->store->load('run-1', 1, 'step-b'));

        $mutated = $this->store->mutate('run-1', 1, 'step-a', static function (?array $current): ToolBatchStoreMutation {
            $next = $current;
            $next['finalized'] = true;

            return new ToolBatchStoreMutation('ok', $next);
        });
        $this->assertSame('ok', $mutated);
        $loaded = $this->store->load('run-1', 1, 'step-a');
        $this->assertTrue($loaded['finalized']);

        $this->store->delete('run-1', 1, 'step-a');
        $this->assertNull($this->store->load('run-1', 1, 'step-a'));
        $this->assertNotNull($this->store->load('run-1', 1, 'step-b'));
    }

    public function testDeleteAllForRunRemovesOnlyThatRun(): void
    {
        $state = ['expected_order' => [], 'call_data' => [], 'pending_queue' => [], 'in_flight' => [], 'result_data' => [], 'finalized' => false, 'max_parallelism' => 1];
        $this->store->save('run-1', 1, 's1', $state);
        $this->store->save('run-2', 1, 's1', $state);

        $this->store->deleteAllForRun('run-1');

        $this->assertNull($this->store->load('run-1', 1, 's1'));
        $this->assertNotNull($this->store->load('run-2', 1, 's1'));
    }
}
