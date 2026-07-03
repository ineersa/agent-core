<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ExtensionsConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindExtension;
use Ineersa\CodingAgent\Extension\ExtensionExecBridge;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\CodingAgent\Extension\ExtensionToolRegistryBridge;
use Ineersa\CodingAgent\Extension\FileRewind\FileRewindRuntimePorts;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Completion\CompletionContext;
use Ineersa\Tui\Completion\SlashCommandCompletionProvider;
use Ineersa\Tui\Extension\TuiCommandRegistryAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FileRewindExtensionIntegrationTest extends TestCase
{
    public function testFileRewindExtensionRegistersRewindSlashCommand(): void
    {
        $slashRegistry = new SlashCommandRegistry();
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            extensions: new ExtensionsConfig(
                enabled: [FileRewindExtension::class],
                settings: ['file_rewind' => ['enabled' => true]],
            ),
            cwd: ProjectDir::get(),
        );
        $bridge = new ExtensionToolRegistryBridge(
            new ToolRegistry(),
            new ExtensionHookRegistry(),
            $appConfig,
            new ExtensionExecBridge(),
            new TuiCommandRegistryAdapter($slashRegistry),
            new FileRewindRuntimePorts(),
        );

        $diagnostics = (new ExtensionManager($appConfig, $bridge, new NullLogger()))->loadExtensions();

        self::assertSame([], $diagnostics, implode('; ', $diagnostics));
        self::assertTrue($slashRegistry->has('rewind'));
        $names = array_map(static fn ($m) => $m->name, $slashRegistry->allMetadata());
        self::assertContains('rewind', $names);

        $suggestions = (new SlashCommandCompletionProvider($slashRegistry))->getSuggestions(CompletionContext::forCursorAtEnd('/'));
        $inserts = array_map(static fn ($s) => trim($s->insertText), $suggestions);
        self::assertContains('/rewind', $inserts);
    }
}
