<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;

final class ObservationalMemoryExtensionRegistrationTest extends OmTestCase
{
    public function testOnlyViewAndUnchangedRemoteToolSchemasAreRegistered(): void
    {
        $api = $this->api();
        $api->expects($this->once())->method('registerCommand')->with($this->callback(static fn (CommandDefinitionDTO $dto): bool => 'om-view' === $dto->name));
        $tools = [];
        $api->expects($this->exactly(2))->method('registerTool')->willReturnCallback(static function (ToolRegistrationDTO $tool) use (&$tools): void { $tools[$tool->name] = $tool; });
        $api->expects($this->once())->method('registerExtensionAgentJobHandler');
        $api->expects($this->once())->method('registerAfterTurnCommitHook');
        $api->expects($this->once())->method('registerBeforeCompactionHook');
        $api->expects($this->never())->method('registerPromptContributor');
        (new ObservationalMemoryExtension())->register($api);
        $this->assertSame(['memory_search', 'recall'], array_keys($tools));
        $this->assertSame(['id'], array_keys($tools['recall']->parametersJsonSchema['properties']));
        $this->assertSame(['id'], $tools['recall']->parametersJsonSchema['required']);
        $this->assertSame('^[a-f0-9]{12,64}$', $tools['recall']->parametersJsonSchema['properties']['id']['pattern']);
        $this->assertTrue($tools['recall']->mainOnly);
        $this->assertTrue($tools['memory_search']->mainOnly);
    }

    public function testWorktreeRegistersNothing(): void
    {
        $api = $this->api(worktree: true);
        foreach (['registerCommand', 'registerTool', 'registerExtensionAgentJobHandler', 'registerAfterTurnCommitHook', 'registerBeforeCompactionHook', 'registerPromptContributor'] as $method) {
            $api->expects($this->never())->method($method);
        }
        (new ObservationalMemoryExtension())->register($api);
    }
}
