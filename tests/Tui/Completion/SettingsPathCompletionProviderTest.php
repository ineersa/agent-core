<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Completion;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Completion\CompletionContext;
use Ineersa\Tui\Completion\SettingsPathCompletionProvider;
use Ineersa\Tui\Listener\SettingsPathCompletionSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Thesis: fresh effective direct-child suggestions, sorted; project-only key; replacement range on nested prefix. */
#[CoversClass(SettingsPathCompletionProvider::class)]
final class SettingsPathCompletionProviderTest extends TestCase
{
    #[Test]
    public function testSuggestsSortedDirectChildrenFromFreshEffectiveSettings(): void
    {
        $tmp = TestDirectoryIsolation::createProjectTempDir('settings-path-completion');
        try {
            $home = $tmp.'/home';
            $cwd = $tmp.'/project';
            TestDirectoryIsolation::ensureDirectory($home.'/.hatfield');
            TestDirectoryIsolation::createHatfieldTree($cwd);
            file_put_contents($cwd.'/.hatfield/settings.yaml', Yaml::dump([
                'tui' => ['theme' => 'cyberpunk', 'custom_only' => true],
                'zzz_project_only' => ['enabled' => true],
            ], 6, 4));
            $provider = new SettingsPathCompletionProvider(
                new SettingsPathCompletionSource(
                    new AppConfigLoader(new SettingsPathResolver(ProjectDir::get(), $home)),
                    new AppResourceLocator(ProjectDir::get()),
                    new AppConfig(tui: new TuiConfig(theme: 'cyberpunk'), logging: new LoggingConfig(), cwd: $cwd),
                ),
            );

            $rootPaths = array_map(
                static fn ($s) => $s->display,
                $provider->getSuggestions(CompletionContext::forCursorAtEnd('/settings-show ')),
            );
            $sorted = $rootPaths;
            sort($sorted);
            $this->assertSame($sorted, $rootPaths);
            $this->assertContains('tui', $rootPaths);
            $this->assertContains('zzz_project_only', $rootPaths);
            $tuiDot = $provider->getSuggestions(CompletionContext::forCursorAtEnd('/settings-show tui.'));
            $tuiPaths = array_map(static fn ($s) => $s->display, $tuiDot);
            $this->assertContains('tui.theme', $tuiPaths);
            $this->assertContains('tui.custom_only', $tuiPaths);
            $this->assertNotContains('tui.transcript.thinking.visible', $tuiPaths);
            $this->assertSame(\strlen('/settings-show '), $tuiDot[0]->replacementStart);
            $this->assertSame(\strlen('tui.'), $tuiDot[0]->replacementLength);
            $partial = array_map(
                static fn ($s) => $s->display,
                $provider->getSuggestions(CompletionContext::forCursorAtEnd('/settings-show tui.t')),
            );
            $this->assertContains('tui.theme', $partial);
            $this->assertContains('tui.transcript', $partial);
            $this->assertNotContains('tui.custom_only', $partial);
        } finally {
            TestDirectoryIsolation::removeDirectory($tmp);
        }
    }
}
