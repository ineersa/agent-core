<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\CodingAgent\CLI\CompletionFileIndexRefreshCommand;
use Ineersa\CodingAgent\CLI\FileMentionIndexBuilder;
use Ineersa\CodingAgent\CLI\FileMentionIndexLockHeldException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[CoversClass(CompletionFileIndexRefreshCommand::class)]
final class CompletionFileIndexRefreshCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/editor09-cmd-'.getmypid().'-'.hrtime(true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function createLockFactory(): LockFactory
    {
        return new LockFactory(new FlockStore($this->tmpDir));
    }

    private function createLogger(): LoggerInterface
    {
        return $this->createStub(LoggerInterface::class);
    }

    private function createCommand(string $cwd, string $indexPath, ?LockFactory $lockFactory = null): Command
    {
        $builder = new FileMentionIndexBuilder(
            $cwd,
            $indexPath,
            logger: $this->createLogger(),
            lockFactory: $lockFactory ?? $this->createLockFactory(),
        );

        return new CompletionFileIndexRefreshCommand($builder, $this->createLogger());
    }

    #[Test]
    public function successWritesNoOutputAndReturnsSuccess(): void
    {
        // Create a minimal directory so the builder has something to scan.
        touch($this->tmpDir.'/some-file.php');

        $indexPath = $this->tmpDir.'/index.jsonl';
        $command = $this->createCommand($this->tmpDir, $indexPath);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertEmpty($tester->getDisplay(), 'Should write no stdout output.');
    }

    #[Test]
    public function lockHeldWritesNoOutputAndReturnsSuccess(): void
    {
        touch($this->tmpDir.'/some-file.php');

        $indexPath = $this->tmpDir.'/index.jsonl';
        $lockFactory = $this->createLockFactory();

        // Pre-acquire the lock so the command's builder hits contention.
        $lock = $lockFactory->createLock(
            'file_mention_index.'.hash('xxh32', $indexPath),
            ttl: 300.0,
        );
        $this->assertTrue($lock->acquire(false), 'Should pre-acquire lock.');

        $command = $this->createCommand($this->tmpDir, $indexPath, $lockFactory);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertEmpty($tester->getDisplay(), 'Should write no stdout output on lock-held skip.');
    }

    #[Test]
    public function failureWritesNoOutputAndReturnsFailure(): void
    {
        $indexPath = $this->tmpDir.'/index.jsonl';
        $lockFactory = $this->createLockFactory();

        // Point the builder at a non-existent directory to trigger a
        // scan exception, exercising the RuntimeException catch branch.
        $command = $this->createCommand($this->tmpDir.'/nonexistent', $indexPath, $lockFactory);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertEmpty($tester->getDisplay(), 'Should write no stdout output on failure.');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $fileinfo) {
            $path = $fileinfo->getRealPath();
            if (false === $path) {
                continue;
            }
            if ($fileinfo->isDir()) {
                @chmod($path, 0700);
                @rmdir($path);
            } else {
                @chmod($path, 0600);
                @unlink($path);
            }
        }
        @chmod($dir, 0700);
        @rmdir($dir);
    }
}
