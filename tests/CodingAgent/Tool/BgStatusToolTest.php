<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Tool\BgStatusTool;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Tool\BgStatusTool
 * @covers \Ineersa\CodingAgent\Tool\BackgroundProcessManager
 * @covers \Ineersa\CodingAgent\Config\BackgroundProcessConfig
 *
 * @requires extension pdo_sqlite
 * @requires OS Linux
 */
final class BgStatusToolTest extends TestCase
{
    private Connection $connection;
    private BackgroundProcessManager $manager;
    private BackgroundProcessConfig $config;
    private BgStatusTool $tool;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->tmpDir = sys_get_temp_dir().'/hatfield_bgtool_test_'.\bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, recursive: true);

        $this->config = new BackgroundProcessConfig(
            storageDir: $this->tmpDir,
            stopGraceSeconds: 1,
            logTailChars: 5000,
        );
        $this->manager = new BackgroundProcessManager($this->connection, $this->config);
        $this->tool = new BgStatusTool($this->manager, $this->config);
    }

    protected function tearDown(): void
    {
        try {
            $this->manager->shutdownCleanup();
        } catch (\RuntimeException) {
            // Best-effort
        }
        $this->rmDir($this->tmpDir);
    }

    /* ── definition() tests ── */

    public function testDefinitionNameIsBgStatus(): void
    {
        $definition = $this->tool->definition();
        self::assertSame('bg_status', $definition->name);
    }

    public function testDefinitionHasDescription(): void
    {
        $definition = $this->tool->definition();
        self::assertNotEmpty($definition->description);
    }

    public function testDefinitionHandlerIsInvokable(): void
    {
        $definition = $this->tool->definition();
        self::assertTrue(method_exists($definition->handler, '__invoke'));
    }

    public function testDefinitionHasPromptLine(): void
    {
        $definition = $this->tool->definition();
        self::assertNotEmpty($definition->promptLine);
        self::assertStringContainsString('bg_status', $definition->promptLine);
    }

    public function testDefinitionHasGuidelines(): void
    {
        $definition = $this->tool->definition();
        self::assertNotEmpty($definition->promptGuidelines);
    }

    public function testDefinitionImplementsHatfieldToolProviderInterface(): void
    {
        self::assertTrue(method_exists($this->tool, 'definition'));
    }

    public function testDefinitionJsonSchemaHasAction(): void
    {
        $definition = $this->tool->definition();
        $schema = $definition->parametersJsonSchema;

        self::assertArrayHasKey('type', $schema);
        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('properties', $schema);
        self::assertArrayHasKey('action', $schema['properties']);
        self::assertArrayHasKey('enum', $schema['properties']['action']);
        self::assertContains('list', $schema['properties']['action']['enum']);
        self::assertContains('log', $schema['properties']['action']['enum']);
        self::assertContains('stop', $schema['properties']['action']['enum']);
        self::assertArrayHasKey('required', $schema);
        self::assertContains('action', $schema['required']);
        self::assertArrayHasKey('additionalProperties', $schema);
        self::assertFalse($schema['additionalProperties']);
    }

    /* ── ToolRegistry integration test ── */

    public function testRegistryExposesBgStatusTool(): void
    {
        $registry = new ToolRegistry([$this->tool]);
        $toolbox = new RegistryBackedToolbox($registry);
        $tools = $toolbox->getTools();

        $toolNames = array_map(fn ($t) => $t->getName(), $tools);

        self::assertContains('bg_status', $toolNames);
    }

    /* ── __invoke() — list action ── */

    public function testListWithNoProcesses(): void
    {
        $result = ($this->tool)(['action' => 'list']);

        self::assertStringContainsString('No background processes tracked', $result);
    }

    public function testListWithRunningProcess(): void
    {
        $this->manager->start('sleep 5');

        $result = ($this->tool)(['action' => 'list']);

        self::assertStringContainsString('sleep', $result);
        self::assertStringContainsString('running', $result);
        self::assertStringContainsString('Total:', $result);

        $this->manager->shutdownCleanup();
    }

    public function testListWithMultipleProcesses(): void
    {
        $this->manager->start('echo "proc a"');
        $this->manager->start('echo "proc b"');

        $result = ($this->tool)(['action' => 'list']);

        self::assertStringContainsString('proc a', $result);
        self::assertStringContainsString('proc b', $result);
        self::assertStringContainsString('Total: 2', $result);

        $this->manager->shutdownCleanup();
    }

    /* ── __invoke() — log action ── */

    public function testLogWithExistingProcess(): void
    {
        $started = $this->manager->start('echo "log test content"');
        \usleep(500000); // wait for write

        $result = ($this->tool)(['action' => 'log', 'pid' => $started['pid']]);

        self::assertStringContainsString('log test content', $result);
        self::assertStringContainsString('PID ' . $started['pid'], $result);
        self::assertStringContainsString('BEGIN LOG', $result);
        self::assertStringContainsString('END LOG', $result);

        $this->manager->shutdownCleanup();
    }

    public function testLogWithoutPidThrows(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"pid" argument is required');

        ($this->tool)(['action' => 'log']);
    }

    public function testLogWithInvalidPidThrows(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"pid" argument is required');

        ($this->tool)(['action' => 'log', 'pid' => 0]);
    }

    public function testLogWithUnknownPidThrows(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No background process found');

        ($this->tool)(['action' => 'log', 'pid' => 999999]);
    }

    /* ── __invoke() — stop action ── */

    public function testStopWithRunningProcess(): void
    {
        $started = $this->manager->start('sleep 30');

        $result = ($this->tool)(['action' => 'stop', 'pid' => $started['pid']]);

        self::assertStringContainsString('stopped', $result);
        self::assertStringContainsString((string) $started['pid'], $result);
    }

    public function testStopWithoutPidThrows(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"pid" argument is required');

        ($this->tool)(['action' => 'stop']);
    }

    public function testStopWithInvalidPidThrows(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"pid" argument is required');

        ($this->tool)(['action' => 'stop', 'pid' => -1]);
    }

    public function testStopWithUnknownPidThrows(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No background process found');

        ($this->tool)(['action' => 'stop', 'pid' => 999999]);
    }

    /* ── __invoke() — argument validation ── */

    public function testMissingActionThrows(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"action" argument is required');

        ($this->tool)([]);
    }

    public function testEmptyActionThrows(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"action" argument is required');

        ($this->tool)(['action' => '']);
    }

    public function testInvalidActionThrows(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid action');

        ($this->tool)(['action' => 'invalid_action']);
    }

    /* ── ToolCallException structured error tests ── */

    public function testMissingActionExceptionHasHint(): void
    {
        try {
            ($this->tool)([]);
        } catch (ToolCallException $e) {
            self::assertFalse($e->retryable());
            self::assertNotNull($e->hint());
            self::assertStringContainsString('list, log, stop', $e->hint());
        }
    }

    public function testMissingPidExceptionHasHint(): void
    {
        try {
            ($this->tool)(['action' => 'stop']);
        } catch (ToolCallException $e) {
            self::assertFalse($e->retryable());
            self::assertNotNull($e->hint());
            self::assertStringContainsString('PID', $e->hint());
        }
    }

    /* ── Helpers ── */

    private function rmDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir((string) $item);
            } else {
                @unlink((string) $item);
            }
        }

        @rmdir($path);
    }
}
