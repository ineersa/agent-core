<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Handler\ObserveBoundaryHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Message\ObserveBoundaryMessage;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabase;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmSchemaMigrator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Thesis: redelivering the same ObserveBoundaryMessage is a compatible no-op
 * including the zero-observation coverage path.
 */
final class ObservationRepositoryIdempotencyTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/om-idem-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0750, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->tmpDir);
    }

    public function testZeroObservationCoverageIsIdempotentOnRedelivery(): void
    {
        $database = OmDatabase::connect($this->tmpDir.'/om.sqlite');
        (new OmSchemaMigrator($database->connection(), new NullLogger()))->migrate();

        $handler = new ObserveBoundaryHandler(
            new ObservationRepository($database->connection()),
            new NullLogger(),
        );

        $message = new ObserveBoundaryMessage(
            runId: 'run-1',
            boundaryKey: 'boundary-a',
            sourceStartSeq: 1,
            sourceEndSeq: 10,
            sourceDigest: 'digest-a',
            rendererVersion: 'r1',
            observerSchemaVersion: 'o1',
            observerModel: 'provider/model',
            observations: [],
        );

        $handler($message);
        $handler($message);

        $count = (int) $database->connection()->fetchOne('SELECT COUNT(*) FROM om_coverage');
        $this->assertSame(1, $count);
        $observationCount = (int) $database->connection()->fetchOne('SELECT observation_count FROM om_coverage');
        $this->assertSame(0, $observationCount);
    }

    public function testObservationsPersistOnceOnRedelivery(): void
    {
        $database = OmDatabase::connect($this->tmpDir.'/om.sqlite');
        (new OmSchemaMigrator($database->connection(), new NullLogger()))->migrate();

        $handler = new ObserveBoundaryHandler(
            new ObservationRepository($database->connection()),
            new NullLogger(),
        );

        $message = new ObserveBoundaryMessage(
            runId: 'run-2',
            boundaryKey: 'boundary-b',
            sourceStartSeq: 1,
            sourceEndSeq: 5,
            sourceDigest: 'digest-b',
            rendererVersion: 'r1',
            observerSchemaVersion: 'o1',
            observerModel: 'provider/model',
            observations: [
                [
                    'content' => 'User prefers linear history.',
                    'relevance' => 80,
                    'token_count' => 6,
                ],
            ],
        );

        $handler($message);
        $handler($message);

        $this->assertSame(1, (int) $database->connection()->fetchOne('SELECT COUNT(*) FROM om_observation'));
        $this->assertSame(1, (int) $database->connection()->fetchOne('SELECT COUNT(*) FROM om_coverage'));
        $this->assertSame(1, (int) $database->connection()->fetchOne('SELECT observation_count FROM om_coverage'));
    }
}
