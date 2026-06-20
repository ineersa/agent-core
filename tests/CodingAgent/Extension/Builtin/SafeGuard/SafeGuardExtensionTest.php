<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardToolCallHook;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallDecisionKindEnum;
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
        self::assertInstanceOf(\Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface::class, $extension);
    }

    public function testRegisterCreatesHookAndCallsRegisterToolCallHook(): void
    {
        $extension = new SafeGuardExtension();
        $api = $this->createMock(ExtensionApiInterface::class);
        $api->expects(self::once())->method('getSettings')->with('safe_guard')->willReturn([]);
        $api->expects(self::once())->method('getCwd')->willReturn('/tmp');
        $api->expects(self::once())->method('registerToolCallHook')
            ->with(self::callback(static fn (ToolCallHookInterface $hook): bool => $hook instanceof SafeGuardToolCallHook));
        $extension->register($api);
    }

    public function testRegisterWithCustomSettingsIncludingAutoDeny(): void
    {
        $extension = new SafeGuardExtension();

        /** @var SafeGuardToolCallHook|null $capturedHook */
        $capturedHook = null;

        $api = $this->createMock(ExtensionApiInterface::class);
        $api->expects(self::once())->method('getSettings')->with('safe_guard')->willReturn([
            'tool_names' => ['bash' => 'shell'],
            'auto_deny_in_noninteractive' => false,
        ]);
        $api->expects(self::once())->method('getCwd')->willReturn('/project');
        $api->expects(self::once())->method('registerToolCallHook')
            ->with(self::callback(static function (ToolCallHookInterface $hook) use (&$capturedHook): bool {
                $capturedHook = $hook;

                return $hook instanceof SafeGuardToolCallHook;
            }));
        $extension->register($api);

        self::assertNotNull($capturedHook, 'Expected hook to be captured');

        // Exercise the hook with a destructive command using the custom alias 'shell'
        // and verify auto_deny_in_noninteractive=false allows RequireApproval
        $dto = $capturedHook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_custom',
            toolName: 'shell',
            arguments: ['command' => 'rm -rf /tmp/build'],
            orderIndex: 0,
        ));

        self::assertSame(
            ToolCallDecisionKindEnum::RequireApproval,
            $dto->kind,
            'Custom alias "shell" should be recognized and auto_deny_in_noninteractive=false should return RequireApproval',
        );
    }

    public function testRegisterCanBeCalledMultipleTimes(): void
    {
        $extension = new SafeGuardExtension();
        $api1 = $this->createMock(ExtensionApiInterface::class);
        $api1->method('getSettings')->willReturn([]);
        $api1->method('getCwd')->willReturn('/tmp');
        $api1->expects(self::once())->method('registerToolCallHook');
        $extension->register($api1);

        $api2 = $this->createMock(ExtensionApiInterface::class);
        $api2->method('getSettings')->willReturn([]);
        $api2->method('getCwd')->willReturn('/tmp');
        $api2->expects(self::once())->method('registerToolCallHook');
        $extension->register($api2);
    }
}
