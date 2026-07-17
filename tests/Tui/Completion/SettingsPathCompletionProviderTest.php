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

/**
 * Thesis: real provider loads fresh effective settings and returns sorted
 * direct-child suggestions with correct replacement ranges for root and nested
 * path prefixes, including project-only unknown effective keys.
 */
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
                'tui' => [
                    'theme' => 'cyberpunk',
                    'custom_only' => true,
                ],
                'zzz_project_only' => ['enabled' => true],
            ], 6, 4));

            $resources = new AppResourceLocator(ProjectDir::get());
            $loader = new AppConfigLoader(new SettingsPathResolver(ProjectDir::get(), $home));
            $provider = new SettingsPathCompletionProvider(
                new SettingsPathCompletionSource(
                    $loader,
                    $resources,
                    new AppConfig(tui: new TuiConfig(theme: 'cyberpunk'), logging: new LoggingConfig(), cwd: $cwd),
                ),
            );

            $root = $provider->getSuggestions(CompletionContext::forCursorAtEnd('/settings-show '));
            $this->assertNotEmpty($root);
            $rootPaths = array_map(static fn ($s) => $s->display, $root);
            $sorted = $rootPaths;
            sort($sorted);
            $this->assertSame($sorted, $rootPaths);
            $this->assertContains('tui', $rootPaths);
            $this->assertContains('zzz_project_only', $rootPaths);
            $this->assertSame(\strlen('/settings-show '), $root[0]->replacementStart);
            $this->assertSame(0, $root[0]->replacementLength);
            $tuiIndex = array_search('tui', $rootPaths, true);
            $this->assertNotFalse($tuiIndex);
            $this->assertSame('settings group', $root[$tuiIndex]->description);

            $tuiDot = $provider->getSuggestions(CompletionContext::forCursorAtEnd('/settings-show tui.'));
            $tuiPaths = array_map(static fn ($s) => $s->display, $tuiDot);
            $this->assertContains('tui.theme', $tuiPaths);
            $this->assertContains('tui.custom_only', $tuiPaths);
            $this->assertNotContains('tui.transcript.thinking.visible', $tuiPaths);
            $this->assertSame(\strlen('/settings-show '), $tuiDot[0]->replacementStart);
            $this->assertSame(\strlen('tui.'), $tuiDot[0]->replacementLength);
            $this->assertSame('tui.custom_only', $tuiDot[0]->insertText);

            $partial = $provider->getSuggestions(CompletionContext::forCursorAtEnd('/settings-show tui.t'));
            $partialPaths = array_map(static fn ($s) => $s->display, $partial);
            $this->assertContains('tui.theme', $partialPaths);
            $this->assertContains('tui.transcript', $partialPaths);
            $this->assertNotContains('tui.custom_only', $partialPaths);
            $this->assertSame(\strlen('tui.t'), $partial[0]->replacementLength);
        } finally {
            TestDirectoryIsolation::removeDirectory($tmp);
        }
    }
}
