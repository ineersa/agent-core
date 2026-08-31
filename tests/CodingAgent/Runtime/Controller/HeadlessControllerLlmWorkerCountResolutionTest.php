<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\RuntimeConfig;
use Ineersa\CodingAgent\Config\ToolSettings;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Controller\ConsumerSupervisor;
use Ineersa\CodingAgent\Runtime\Controller\HeadlessController;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Thesis: HeadlessController fails closed on invalid llmWorkerCount constructor
 * overrides (no silent clamp); valid construction accepts settings-backed defaults.
 */
#[CoversClass(HeadlessController::class)]
final class HeadlessControllerLlmWorkerCountResolutionTest extends TestCase
{
    public function testValidConstructionWithSettingsDefault(): void
    {
        $controller = $this->createController(llmOverride: 0, runtimeCount: 4);
        $this->assertInstanceOf(HeadlessController::class, $controller);
    }

    public function testValidConstructionWithInRangeOverride(): void
    {
        $controller = $this->createController(llmOverride: 2, runtimeCount: 4);
        $this->assertInstanceOf(HeadlessController::class, $controller);
    }

    public function testInvalidOverrideFailsClosedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('llmWorkerCount override must be an integer between 1 and 8');
        $this->createController(llmOverride: 9, runtimeCount: 4);
    }

    public function testZeroOverrideDoesNotFail(): void
    {
        $controller = $this->createController(llmOverride: 0, runtimeCount: RuntimeConfig::DEFAULT_LLM_WORKER_COUNT);
        $this->assertInstanceOf(HeadlessController::class, $controller);
    }

    private function createController(int $llmOverride, int $runtimeCount): HeadlessController
    {
        $logger = new TestLogger();
        $locator = $this->createStub(AppExecutableLocator::class);
        $locator->method('path')->willReturn('/bin/true');
        $locator->method('command')->willReturn(['/bin/true']);
        $config = new RuntimeProcessConfig($locator, sys_get_temp_dir());
        $supervisor = new ConsumerSupervisor($logger, $config);
        $boundary = new RuntimeExceptionBoundary(new EventDispatcher());
        $emitter = new RuntimeEventEmitter($logger);

        return new HeadlessController(
            consumerSupervisor: $supervisor,
            dispatcher: new EventDispatcher(),
            logger: $logger,
            toolExecutionSettings: new ToolSettings(maxParallelism: 1),
            boundary: $boundary,
            emitter: $emitter,
            sessionOwnerLockFactory: new LockFactory(new InMemoryStore()),
            runtimeCwd: sys_get_temp_dir(),
            runtimeConfig: new RuntimeConfig(llmWorkerCount: $runtimeCount),
            toolWorkerCount: 0,
            llmWorkerCount: $llmOverride,
        );
    }
}
