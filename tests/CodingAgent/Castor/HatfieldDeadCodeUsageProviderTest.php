<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Extension\Agent\ConfiguredModelAgentRunner;
use Ineersa\CodingAgent\Extension\ExtensionToolRegistryBridge;
use Ineersa\CodingAgent\Extension\Session\ExtensionSessionEventReader;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Tests\Extension\InMemoryExtensionApiBridge;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\BashProcessRecordDTO;
use Ineersa\HatfieldExt\ObservationalMemory\DTO\LibrarianResponseDTO;
use Ineersa\Tools\PHPStan\DeadCode\HatfieldDeadCodeUsageProvider;
use Ineersa\Tui\Picker\PickerListLabelFormatter;
use Ineersa\Tui\Runtime\BridgeTuiExtensionContext;
use Ineersa\Tui\Screen\ChatScreen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

final class HatfieldDeadCodeUsageProviderTest extends TestCase
{
    /** @param class-string $class */
    #[DataProvider('tuiMethods')]
    public function testOnlyExactPublishedTuiImplementorMethodsAreExempt(string $class, string $method, bool $exempt): void
    {
        $rule = new \ReflectionMethod(HatfieldDeadCodeUsageProvider::class, 'shouldMarkMethodAsUsed');
        $usage = $rule->invoke(new HatfieldDeadCodeUsageProvider(), new \ReflectionMethod($class, $method));

        if (!$exempt) {
            $this->assertNull($usage);

            return;
        }

        $this->assertInstanceOf(VirtualUsageData::class, $usage);
        $this->assertSame('Published TuiExtensionContextInterface method with no current extension callers', $usage->getNote());
    }

    /** @return iterable<string, array{class-string, string, bool}> */
    public static function tuiMethods(): iterable
    {
        yield 'extension warnings' => [BridgeTuiExtensionContext::class, 'setExtensionWarning', true];
        yield 'muted formatting' => [BridgeTuiExtensionContext::class, 'formatMuted', true];
        yield 'role formatting' => [BridgeTuiExtensionContext::class, 'formatRolePrefix', true];
        yield 'full history rows' => [BridgeTuiExtensionContext::class, 'turnRowsInDisplayOrder', true];
        yield 'status' => [BridgeTuiExtensionContext::class, 'setStatus', true];
        yield 'tick callback' => [BridgeTuiExtensionContext::class, 'onTick', true];
        yield 'internal status method' => [ChatScreen::class, 'setStatus', false];
        yield 'other implementor method' => [BridgeTuiExtensionContext::class, 'requestRender', false];
        yield 'internal warning method' => [ChatScreen::class, 'setExtensionWarning', false];
        yield 'internal role formatter' => [PickerListLabelFormatter::class, 'formatRolePrefix', false];
        yield 'internal history interface' => [HistoryProviderInterface::class, 'promptText', false];
    }

    /** @param class-string $class */
    #[DataProvider('capabilityMethods')]
    public function testExactPublishedCapabilitiesOnImplementors(string $class, string $method, bool $exempt): void
    {
        $rule = new \ReflectionMethod(HatfieldDeadCodeUsageProvider::class, 'shouldMarkMethodAsUsed');
        $usage = $rule->invoke(new HatfieldDeadCodeUsageProvider(), new \ReflectionMethod($class, $method));
        $this->assertSame($exempt, $usage instanceof VirtualUsageData);
    }

    /** @return iterable<string, array{class-string, string, bool}> */
    public static function capabilityMethods(): iterable
    {
        yield 'host agent' => [ExtensionToolRegistryBridge::class, 'agent', true];
        yield 'host events' => [ExtensionToolRegistryBridge::class, 'sessionEvents', true];
        yield 'test agent' => [InMemoryExtensionApiBridge::class, 'agent', true];
        yield 'test events' => [InMemoryExtensionApiBridge::class, 'sessionEvents', true];
        yield 'runner context window' => [ConfiguredModelAgentRunner::class, 'contextWindow', true];
        yield 'event reader' => [ExtensionSessionEventReader::class, 'readRange', true];
        yield 'other runner method' => [ConfiguredModelAgentRunner::class, 'buildStepId', false];
        yield 'other bridge method' => [ExtensionToolRegistryBridge::class, 'getCwd', false];
    }

    /** @param class-string $class */
    #[DataProvider('serializedProperties')]
    public function testExactSerializedPropertiesRemainUsed(string $class, string $property, bool $exempt): void
    {
        $rule = new \ReflectionMethod(HatfieldDeadCodeUsageProvider::class, 'shouldMarkPropertyAsRead');
        $usage = $rule->invoke(new HatfieldDeadCodeUsageProvider(), new \ReflectionProperty($class, $property));
        $this->assertSame($exempt, $usage instanceof VirtualUsageData);
    }

    /** @return iterable<string, array{class-string, string, bool}> */
    public static function serializedProperties(): iterable
    {
        yield 'validator ok' => [LibrarianResponseDTO::class, 'ok', true];
        yield 'validator contract' => [LibrarianResponseDTO::class, 'contract_version', true];
        yield 'serializer text' => [LibrarianResponseDTO::class, 'text', true];
        yield 'validator status' => [LibrarianResponseDTO::class, 'status', true];
        yield 'unrelated internal DTO' => [BashProcessRecordDTO::class, 'status', false];
    }
}
