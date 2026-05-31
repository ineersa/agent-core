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
        $api->expects($this->once())->method('getSettings')->with('safe_guard')->willReturn([]);
        $api->expects($this->once())->method('getCwd')->willReturn('/tmp');
        $api->expects($this->once())->method('registerToolCallHook')
            ->with($this->callback(static fn (ToolCallHookInterface $hook): bool => $hook instanceof SafeGuardToolCallHook));
        $extension->register($api);
    }

    public function testRegisterWithCustomSettingsIncludingAutoDeny(): void
    {
        $extension = new SafeGuardExtension();
        $api = $this->createMock(ExtensionApiInterface::class);
        $api->expects($this->once())->method('getSettings')->with('safe_guard')->willReturn([
            'tool_names' => ['bash' => 'shell'],
            'auto_deny_in_noninteractive' => false,
        ]);
        $api->expects($this->once())->method('getCwd')->willReturn('/project');
        $api->expects($this->once())->method('registerToolCallHook')
            ->with($this->isInstanceOf(SafeGuardToolCallHook::class));
        $extension->register($api);
    }

    public function testRegisterCanBeCalledMultipleTimes(): void
    {
        $extension = new SafeGuardExtension();
        $api1 = $this->createMock(ExtensionApiInterface::class);
        $api1->method('getSettings')->willReturn([]);
        $api1->method('getCwd')->willReturn('/tmp');
        $api1->expects($this->once())->method('registerToolCallHook');
        $extension->register($api1);

        $api2 = $this->createMock(ExtensionApiInterface::class);
        $api2->method('getSettings')->willReturn([]);
        $api2->method('getCwd')->willReturn('/tmp');
        $api2->expects($this->once())->method('registerToolCallHook');
        $extension->register($api2);
    }
}
