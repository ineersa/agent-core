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

    public function testDefaultState(): void
    {
        $this->assertSame([], $this->registry->getStatusEntries());
        $this->assertTrue($this->registry->isWorkingVisible());
        $this->assertSame('', $this->registry->getWorkingMessage());
    }

    public function testStatusEntries(): void
    {
        $this->registry->setStatus('key1', 'value1');
        $this->registry->setStatus('key2', 'value2');

        $this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $this->registry->getStatusEntries());

        $this->registry->setStatus('key1', null);
        $this->assertSame(['key2' => 'value2'], $this->registry->getStatusEntries());
    }

    public function testWorkingState(): void
    {
        $this->registry->setWorkingMessage('Loading...');
        $this->assertSame('Loading...', $this->registry->getWorkingMessage());
        $this->assertTrue($this->registry->isWorkingVisible());

        $this->registry->setWorkingVisible(false);
        $this->assertFalse($this->registry->isWorkingVisible());

        $this->registry->setWorkingMessage(null);
        $this->assertSame('', $this->registry->getWorkingMessage());
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
