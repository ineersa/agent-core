<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;

/**
 * Thesis: removing SettingsTool must drop the model-visible settings tool
 * from permanent registration.
 */
final class SettingsToolAbsenceTest extends IsolatedKernelTestCase
{
    public function testSettingsToolIsNotRegistered(): void
    {
        $registry = self::getContainer()->get(ToolRegistryInterface::class);

        $this->assertNotContains('settings', $registry->activeToolNames());
        $this->assertNull($registry->toolDefinition('settings'));
    }
}
