<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Thesis: OM supervisor start selection runs during register() from process argv
 * (because COMMAND listeners added mid-dispatch never receive the current event)
 * and skips non-agent plus agent --controller/--headless paths.
 */
final class OmConsoleLifecycleSelectionTest extends TestCase
{
    /** @var list<string>|null */
    private ?array $originalArgv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalArgv = $_SERVER['argv'] ?? null;
    }

    protected function tearDown(): void
    {
        if (null === $this->originalArgv) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = $this->originalArgv;
        }
        parent::tearDown();
    }

    public function testRegisterSkipsNonAgentCommands(): void
    {
        $_SERVER['argv'] = ['bin/console', 'cache:clear'];

        $extension = new ObservationalMemoryExtension();
        $extension->setLogger(new NullLogger());
        $extension->register($this->createStub(ExtensionApiInterface::class));

        $ref = new \ReflectionProperty($extension, 'started');
        $this->assertFalse($ref->getValue($extension));
    }

    public function testRegisterSkipsAgentControllerMode(): void
    {
        $_SERVER['argv'] = ['bin/console', 'agent', '--controller'];

        $extension = new ObservationalMemoryExtension();
        $extension->setLogger(new NullLogger());
        $api = $this->createStub(ExtensionApiInterface::class);
        $api->method('getCwd')->willReturn(sys_get_temp_dir());
        $api->method('getSettings')->willReturn(['enabled' => true]);
        $extension->register($api);

        $ref = new \ReflectionProperty($extension, 'started');
        $this->assertFalse($ref->getValue($extension));
    }

    public function testRegisterSkipsAgentHeadlessMode(): void
    {
        $_SERVER['argv'] = ['bin/console', 'agent', '--headless'];

        $extension = new ObservationalMemoryExtension();
        $extension->setLogger(new NullLogger());
        $api = $this->createStub(ExtensionApiInterface::class);
        $api->method('getCwd')->willReturn(sys_get_temp_dir());
        $api->method('getSettings')->willReturn(['enabled' => true]);
        $extension->register($api);

        $ref = new \ReflectionProperty($extension, 'started');
        $this->assertFalse($ref->getValue($extension));
    }

    public function testDoesNotSubscribeToConsoleCommand(): void
    {
        $events = ObservationalMemoryExtension::getSubscribedEvents();
        $this->assertArrayNotHasKey('console.command', $events);
        $this->assertArrayHasKey('console.terminate', $events);
        $this->assertArrayHasKey('console.error', $events);
    }
}
