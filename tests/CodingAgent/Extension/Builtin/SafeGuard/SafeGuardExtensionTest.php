<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardToolCallHook;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\ToolCallHookInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SafeGuardExtension — verifies registration and wiring.
 */
final class SafeGuardExtensionTest extends TestCase
{
    public function testExtensionImplementsHatfieldExtensionInterface(): void
    {
        $extension = new SafeGuardExtension();
        $this->assertInstanceOf(\Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface::class, $extension);
    }

    public function testRegisterCreatesHookAndCallsRegisterToolCallHook(): void
    {
        $extension = new SafeGuardExtension();

        $api = $this->createMock(ExtensionApiInterface::class);

        $api->expects($this->once())
            ->method('getSettings')
            ->with('safe_guard')
            ->willReturn([]);

        $api->expects($this->once())
            ->method('getCwd')
            ->willReturn('/tmp');

        // Verify registerToolCallHook is called with a SafeGuardToolCallHook instance
        $api->expects($this->once())
            ->method('registerToolCallHook')
            ->with($this->callback(static function (ToolCallHookInterface $hook): bool {
                return $hook instanceof SafeGuardToolCallHook;
            }));

        $extension->register($api);
    }

    public function testRegisterWithCustomSettings(): void
    {
        $extension = new SafeGuardExtension();

        $api = $this->createMock(ExtensionApiInterface::class);

        $api->expects($this->once())
            ->method('getSettings')
            ->with('safe_guard')
            ->willReturn([
                'tool_names' => [
                    'bash' => 'shell',
                    'write' => 'save',
                ],
                'allow_command_patterns' => ['git push'],
            ]);

        $api->expects($this->once())
            ->method('getCwd')
            ->willReturn('/project');

        $api->expects($this->once())
            ->method('registerToolCallHook')
            ->with($this->isInstanceOf(SafeGuardToolCallHook::class));

        $extension->register($api);
    }

    public function testRegisterCanBeCalledMultipleTimes(): void
    {
        // Each register() call creates a fresh hook — verify idempotent.
        $extension = new SafeGuardExtension();

        $api1 = $this->createMock(ExtensionApiInterface::class);
        $api1->method('getSettings')->willReturn([]);
        $api1->method('getCwd')->willReturn('/tmp');
        $api1->expects($this->once())->method('registerToolCallHook');

        $api2 = $this->createMock(ExtensionApiInterface::class);
        $api2->method('getSettings')->willReturn([]);
        $api2->method('getCwd')->willReturn('/tmp');
        $api2->expects($this->once())->method('registerToolCallHook');

        $extension->register($api1);
        $extension->register($api2);
    }
}
