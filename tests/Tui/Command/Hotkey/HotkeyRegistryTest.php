<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Command\Hotkey;

use Ineersa\Tui\Command\Hotkey\HotkeyBindingDTO;
use Ineersa\Tui\Command\Hotkey\HotkeyRegistry;
use PHPUnit\Framework\TestCase;

final class HotkeyRegistryTest extends TestCase
{
    private HotkeyRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new HotkeyRegistry();
    }

    public function testAllReturnsEmptyArrayWhenNoBindingsRegistered(): void
    {
        self::assertSame([], $this->registry->all());
    }

    public function testAllReturnsAddedBindings(): void
    {
        $this->registry->add(new HotkeyBindingDTO(
            context: 'Global',
            keys: ['ctrl+c'],
            action: 'Clear editor',
            source: 'core',
        ));

        $all = $this->registry->all();
        self::assertCount(1, $all);
        self::assertSame('Global', $all[0]->context);
        self::assertSame(['ctrl+c'], $all[0]->keys);
        self::assertSame('Clear editor', $all[0]->action);
    }

    public function testGroupedGroupsByContext(): void
    {
        $this->registry->add(new HotkeyBindingDTO(
            context: 'Editor',
            keys: ['enter'],
            action: 'Submit',
            priority: 10,
        ));
        $this->registry->add(new HotkeyBindingDTO(
            context: 'Global',
            keys: ['ctrl+c'],
            action: 'Clear',
            priority: 10,
        ));

        $groups = $this->registry->grouped();

        // Global should come before Editor per context ordering
        $contexts = array_keys($groups);
        self::assertSame('Global', $contexts[0]);
        self::assertSame('Editor', $contexts[1]);
    }

    public function testGroupedSortsByPriorityWithinContext(): void
    {
        $this->registry->add(new HotkeyBindingDTO(
            context: 'Editor',
            keys: ['enter'],
            action: 'Submit',
            priority: 20,
        ));
        $this->registry->add(new HotkeyBindingDTO(
            context: 'Editor',
            keys: ['ctrl+j'],
            action: 'Newline',
            priority: 10,
        ));

        $groups = $this->registry->grouped();
        $editor = $groups['Editor'];

        self::assertCount(2, $editor);
        self::assertSame('Newline', $editor[0]->action); // priority 10 first
        self::assertSame('Submit', $editor[1]->action);  // priority 20 second
    }

    public function testGroupedSortsByActionNameWhenSamePriority(): void
    {
        $this->registry->add(new HotkeyBindingDTO(
            context: 'Editor',
            keys: ['ctrl+b'],
            action: 'BBB',
            priority: 10,
        ));
        $this->registry->add(new HotkeyBindingDTO(
            context: 'Editor',
            keys: ['ctrl+a'],
            action: 'AAA',
            priority: 10,
        ));

        $editor = $this->registry->grouped()['Editor'];
        self::assertSame('AAA', $editor[0]->action);
        self::assertSame('BBB', $editor[1]->action);
    }

    public function testGroupedContextsInDefinedOrder(): void
    {
        $this->registry->add(new HotkeyBindingDTO(context: 'Model', keys: ['ctrl+p'], action: 'Cycle', priority: 10));
        $this->registry->add(new HotkeyBindingDTO(context: 'Completion', keys: ['tab'], action: 'Accept', priority: 10));
        $this->registry->add(new HotkeyBindingDTO(context: 'History', keys: ['up'], action: 'Recall', priority: 10));
        $this->registry->add(new HotkeyBindingDTO(context: 'Editor', keys: ['enter'], action: 'Submit', priority: 10));
        $this->registry->add(new HotkeyBindingDTO(context: 'Global', keys: ['ctrl+c'], action: 'Clear', priority: 10));

        $contexts = array_keys($this->registry->grouped());
        self::assertSame(['Global', 'History', 'Editor', 'Completion', 'Model'], $contexts);
    }

    public function testGroupedUnknownContextSortedLast(): void
    {
        $this->registry->add(new HotkeyBindingDTO(
            context: 'CustomUnknown',
            keys: ['f12'],
            action: 'Custom thing',
            priority: 10,
        ));
        $this->registry->add(new HotkeyBindingDTO(
            context: 'Global',
            keys: ['ctrl+c'],
            action: 'Clear',
            priority: 10,
        ));

        $contexts = array_keys($this->registry->grouped());
        // Global should be before unknown context
        self::assertSame('Global', $contexts[0]);
        self::assertSame('CustomUnknown', $contexts[1]);
    }
}
