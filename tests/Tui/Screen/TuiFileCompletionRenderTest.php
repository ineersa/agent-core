<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Completion\CompletionProviderRegistry;
use Ineersa\Tui\Completion\FileMentionCompletionProvider;
use Ineersa\Tui\Completion\FileMentionIndexReader;
use Ineersa\Tui\Listener\CompletionListener;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic @ file completion menu + accept proof without tmux.
 *
 * Test thesis: Tab on @test opens the completion overlay with indexed
 * paths and a second Tab inserts the selected @path through
 * CompletionListener → FileMentionCompletionProvider → CompletionMenu.
 */
final class TuiFileCompletionRenderTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private string $tmpDir = '';

    protected function tearDown(): void
    {
        if ('' !== $this->tmpDir && is_dir($this->tmpDir)) {
            @unlink($this->tmpDir.'/index.jsonl');
            @rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function testAtTestTabOpensMenuAndSecondTabAcceptsPath(): void
    {
        $indexPath = $this->writeFileMentionIndex([
            '{"path":"home/testfiles","dir":true}',
            '{"path":"home/testfiles/alpha.txt","dir":false}',
        ]);

        $reader = new FileMentionIndexReader($indexPath);
        $registry = new CompletionProviderRegistry([
            new FileMentionCompletionProvider($reader),
        ]);

        $harness = new VirtualTuiHarness(columns: 120, rows: 50, sessionId: 'virtual-file-completion');
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState(new TuiSessionState('virtual-file-completion'))
            ->withScreen($harness->screen())
            ->build();

        (new CompletionListener($registry))->register($context);

        try {
            $harness->startInputLoop();

            $harness->screen()->promptEditor()->typeText('@test');
            $harness->render();

            $harness->sendInput("\t");

            $menuScreen = $harness->plainScreenText();
            $this->assertStringContainsString('Completions', $menuScreen, 'Tab should open completion overlay');
            $this->assertTrue(
                str_contains($menuScreen, 'testfiles')
                    || str_contains($menuScreen, '@home/testfiles'),
                'Menu should list an indexed path matching @test',
            );

            $harness->sendInput("\t");

            $acceptedText = $harness->screen()->promptEditor()->getText();
            $this->assertStringContainsString('@home/testfiles', $acceptedText, 'Second Tab should insert accepted @ path');

            $afterAcceptScreen = $harness->plainScreenText();
            $this->assertStringNotContainsString(
                'Completions — arrows move',
                $afterAcceptScreen,
                'Completion menu should close after accept',
            );
        } finally {
            $harness->stopInputLoop();
        }
    }

    /**
     * @param list<string> $lines
     */
    private function writeFileMentionIndex(array $lines): string
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('tui-file-completion');
        $indexPath = $this->tmpDir.'/index.jsonl';
        file_put_contents($indexPath, implode("\n", $lines)."\n");

        return $indexPath;
    }
}
