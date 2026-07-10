<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionToolBatchStore;
use Ineersa\CodingAgent\Tests\Session\Support\ParentSessionToolBatchRunStoragePaths;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ValidatorBuilder;

final class SessionToolBatchStoreConcurrencyTest extends TestCase
{
    private string $projectDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('session-tool-batch-concurrency');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testParallelWorkerProcessesMutateWithoutLosingResults(): void
    {
        $store = $this->createStore();
        $store->save('run-par', 1, 'step-1', [
            'expected_order' => ['c1' => 0, 'c2' => 1],
            'call_data' => [],
            'pending_queue' => ['c1', 'c2'],
            'in_flight' => [],
            'result_data' => [],
            'finalized' => false,
            'max_parallelism' => 2,
        ]);

        $autoload = \dirname(__DIR__, 3).'/vendor/autoload.php';
        $script = \dirname(__DIR__, 3).'/tests/CodingAgent/Session/Support/session_tool_batch_mutate_worker.php';
        $this->assertFileExists($script);

        $env = ['HATFIELD_SESSIONS_BASE' => $this->projectDir.'/.hatfield/sessions'];

        $p1 = new Process(['php', $script, $autoload, 'c1'], env: $env);
        $p2 = new Process(['php', $script, $autoload, 'c2'], env: $env);
        $p1->setTimeout(10);
        $p2->setTimeout(10);
        $p1->run();
        $p2->run();

        $this->assertTrue($p1->isSuccessful(), $p1->getErrorOutput().$p1->getOutput());
        $this->assertTrue($p2->isSuccessful(), $p2->getErrorOutput().$p2->getOutput());

        $final = $store->load('run-par', 1, 'step-1');
        $this->assertNotNull($final);
        $this->assertTrue($final['finalized']);
        $this->assertArrayHasKey('c1', $final['result_data']);
        $this->assertArrayHasKey('c2', $final['result_data']);
    }

    private function createStore(): SessionToolBatchStore
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $hatfield = new HatfieldSessionStore($appConfig, $entityManager);
        $pathResolver = new AgentArtifactPathResolver($hatfield);

        $serializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer(nameConverter: new CamelCaseToSnakeCaseNameConverter())],
            [new JsonEncoder()],
        );
        $validator = (new ValidatorBuilder())->enableAttributeMapping()->getValidator();

        return new SessionToolBatchStore(
            new ParentSessionToolBatchRunStoragePaths($hatfield),
            new LockFactory(new FlockStore()),
            new NullLogger(),
        );
    }
}
