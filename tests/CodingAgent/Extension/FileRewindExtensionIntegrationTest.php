<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\ExtensionsConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobDispatcher;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobRegistry;
use Ineersa\CodingAgent\Extension\ExtensionExecBridge;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\CodingAgent\Extension\ExtensionToolRegistryBridge;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\HatfieldExt\FileRewind\FileRewindExtension;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Completion\CompletionContext;
use Ineersa\Tui\Completion\SlashCommandCompletionProvider;
use Ineersa\Tui\Extension\TuiCommandRegistryAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

final class FileRewindExtensionIntegrationTest extends TestCase
{
    public function testFileRewindExtensionRegistersRewindSlashCommand(): void
    {
        $slashCatalog = new SlashCommandCatalog();
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
            new TuiCommandRegistryAdapter($slashCatalog),
            new class implements \Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface {
                public function run(\Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO $request): void
                {
                }

                public function contextWindow(string $exactModel): ?int
                {
                    return null;
                }
            },
            new class implements \Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface {
                public function readRange(string $runId, int $startSeq, int $endSeq): iterable
                {
                    return [];
                }
            },
            new ExtensionAgentJobRegistry(),
            new ExtensionAgentJobDispatcher(new class implements \Symfony\Component\Messenger\MessageBusInterface {
                public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
                {
                    return new \Symfony\Component\Messenger\Envelope($message);
                }
            }, new NullLogger(), 'in-memory://'),
            new StackToolExecutionContextAccessor(),
            new SkillDiscovery(
                config: new SkillsConfig(),
                pathResolver: new SettingsPathResolver($appConfig->cwd),
                appConfig: $appConfig,
                extractor: new MarkdownFrontmatterExtractor(),
                resources: new AppResourceLocator('' !== $appConfig->cwd ? $appConfig->cwd : '/tmp'),
                filesystem: new Filesystem(),
            ),
        );

        $diagnostics = (new ExtensionManager($appConfig, $bridge, new NullLogger(), new \Symfony\Component\EventDispatcher\EventDispatcher()))->loadExtensions();

        $this->assertSame([], $diagnostics, implode('; ', $diagnostics));
        $this->assertTrue($slashCatalog->has('rewind'));
        $names = array_map(static fn ($m) => $m->name, $slashCatalog->allMetadata());
        $this->assertContains('rewind', $names);

        $suggestions = (new SlashCommandCompletionProvider($slashCatalog))->getSuggestions(CompletionContext::forCursorAtEnd('/'));
        $inserts = array_map(static fn ($s) => trim($s->insertText), $suggestions);
        $this->assertContains('/rewind', $inserts);
    }
}
