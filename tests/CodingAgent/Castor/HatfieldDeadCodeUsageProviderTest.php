<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
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
        yield 'other implementor method' => [BridgeTuiExtensionContext::class, 'requestRender', false];
        yield 'internal warning method' => [ChatScreen::class, 'setExtensionWarning', false];
        yield 'internal role formatter' => [PickerListLabelFormatter::class, 'formatRolePrefix', false];
        yield 'internal history interface' => [HistoryProviderInterface::class, 'promptText', false];
    }
}
