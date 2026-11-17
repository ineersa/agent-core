<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ObservationalMemoryExtensionRegistrationTest extends OmTestCase
{
    /** @param array<string, mixed> $legacy @param list<string> $expectedKeys */
    #[DataProvider('registrationSettings')]
    public function testRegistrationInspectsGitExactlyOnceAndWarnsOnlyAtRegistration(array $legacy, array $expectedKeys): void
    {
        mkdir($this->directory.'/.git');
        $exec = $this->createMock(ExecInterface::class);
        $exec->expects($this->once())->method('exec')->willReturn(new ExecResultDTO($this->directory.'/.git'."\n".$this->directory.'/.git', '', 0));
        $api = $this->createMock(ExtensionApiInterface::class);
        $api->expects($this->once())->method('exec')->willReturn($exec);
        $api->method('getCwd')->willReturn($this->directory);
        $api->expects($this->exactly(3))->method('getSettings')->willReturn($legacy + ['librarian' => ['url' => 'http://librarian.test/_mcp']]);
        $api->expects($this->once())->method('registerAfterTurnCommitHook');
        $logger = new TestLogger();
        $extension = new ObservationalMemoryExtension();
        $extension->setLogger($logger);
        $extension->register($api);
        $recordsAfterRegistration = $logger->records;
        OmSettings::fromApi($api);
        OmSettings::fromApi($api);
        $this->assertSame($recordsAfterRegistration, $logger->records);
        $warnings = array_values(array_filter($logger->records, static fn (array $record): bool => 'warning' === $record['level']));
        $this->assertCount([] === $expectedKeys ? 0 : 1, $warnings);
        if ([] !== $expectedKeys) {
            $this->assertSame([
                'component' => 'observational_memory', 'event_type' => 'om.settings.obsolete_keys_ignored',
                'run_id' => null, 'session_id' => null, 'obsolete_keys' => $expectedKeys,
            ], $warnings[0]['context']);
            $this->assertStringContainsString('Librarian owns', $warnings[0]['message']);
            $this->assertStringContainsString('can be removed', $warnings[0]['message']);
        }
        $this->assertStringNotContainsString('private-value', json_encode($logger->records));
    }

    public static function registrationSettings(): iterable
    {
        yield 'modern settings' => [[], []];
        yield 'all legacy keys' => [[
            'storage' => ['database' => 'private-value'], 'model' => 'private-value',
            'observer' => false, 'reflector' => 42, 'pools' => null,
        ], ['storage', 'model', 'observer', 'reflector', 'pools']];
        yield 'only null model' => [['model' => null], ['model']];
    }

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
