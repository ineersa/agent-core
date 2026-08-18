<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Layout;

use Ineersa\Tui\Layout\InputPriority;
use Ineersa\Tui\Layout\TuiSlotRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Event\InputEvent;

#[CoversClass(TuiSlotRegistry::class)]
final class TuiSlotRegistryTest extends TestCase
{
    private TuiSlotRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new TuiSlotRegistry();
    }

    public function testInputHandlers(): void
    {
        $h1 = static function (InputEvent $event): void {};
        $h2 = static function (InputEvent $event): void {};

        $this->registry->addInputHandler($h1, 90);
        $this->registry->addInputHandler($h2);

        $handlers = $this->registry->getInputHandlers();
        $this->assertCount(2, $handlers);
        $this->assertSame($h1, $handlers[0]['handler']);
        $this->assertSame(90, $handlers[0]['priority']);
        $this->assertSame($h2, $handlers[1]['handler']);
        $this->assertSame(InputPriority::EXTENSION_DEFAULT, $handlers[1]['priority']);
    }
}
