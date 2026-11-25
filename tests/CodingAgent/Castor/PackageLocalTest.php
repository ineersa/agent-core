<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PackageLocalTest extends TestCase
{
    public function testPublishesByRenameAndKeepsThePreviousBinary(): void
    {
        require_once ProjectDir::get().'/.castor/distribution.php';
        $work = TestDirectoryIsolation::createOsTempDir('package-local');
        $filesystem = new Filesystem();
        try {
            $artifact = $work.'/candidate';
            $destination = $work.'/bin/hatfield';
            $filesystem->dumpFile($artifact, 'first binary');
            $filesystem->chmod($artifact, 0755);
            $this->assertNull(distribution_install_local_binary($artifact, $destination));

            $previous = fopen($destination, 'rb');
            $this->assertIsResource($previous);
            try {
                $filesystem->dumpFile($artifact, 'second binary');
                $filesystem->chmod($artifact, 0755);
                $backup = distribution_install_local_binary($artifact, $destination);
                $this->assertNotNull($backup);
                $this->assertSame('first binary', file_get_contents($backup));
                $this->assertSame('first binary', stream_get_contents($previous));
                $this->assertSame('second binary', file_get_contents($destination));
                $this->assertTrue(is_executable($destination));
                $this->assertSame([], glob($work.'/bin/.hatfield-*'));
            } finally {
                fclose($previous);
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testRefusesDirectoryDestinationAndRemovesStaging(): void
    {
        require_once ProjectDir::get().'/.castor/distribution.php';
        $work = TestDirectoryIsolation::createOsTempDir('package-local');
        $filesystem = new Filesystem();
        try {
            $artifact = $work.'/candidate';
            $destination = $work.'/bin/hatfield';
            $filesystem->dumpFile($artifact, 'candidate');
            $filesystem->chmod($artifact, 0755);
            $filesystem->dumpFile($destination.'/keep', 'original');
            try {
                distribution_install_local_binary($artifact, $destination);
                $this->fail('Expected the directory destination to be rejected.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Cannot back up non-file install destination', $exception->getMessage());
            }
            $this->assertSame('original', file_get_contents($destination.'/keep'));
            $this->assertSame([], glob($work.'/bin/.hatfield-*'));
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }
}
