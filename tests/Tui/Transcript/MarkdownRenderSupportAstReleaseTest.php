<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Widget-owned parsers must release Markdown ASTs when widgets are evicted.
 *
 * MarkdownParser keeps the last document in closedBlockParsers until the next
 * parse. Sharing one parser across widgets would pin evicted ASTs for the
 * factory lifetime. The production probe keeps the factory, drops the widget,
 * collects cycles, and asserts the Document is unreachable without parsing
 * again. The shared-parser counterfactual keeps the parser and must stay alive.
 *
 * Runs under XDEBUG_MODE=off in a child process: debugger retainers can keep
 * observed objects alive and make WeakReference proofs environment-dependent.
 */
final class MarkdownRenderSupportAstReleaseTest extends TestCase
{
    public function testEvictedWidgetAstBecomesUnreachableWhileFactoryRetained(): void
    {
        $result = $this->runProbe('production');
        $this->assertTrue($result['ok']);
        $this->assertSame('production', $result['mode']);
        $this->assertGreaterThanOrEqual(200, $result['closed_before_evict']);
        $this->assertSame('Document', $result['root_class']);
        $this->assertFalse($result['document_alive_after_evict']);
        $this->assertTrue($result['factory_retained']);
        $this->assertFalse($result['shared_parser_retained']);
        $this->assertFalse($result['parsed_after_evict']);
    }

    public function testSharedParserCounterfactualRetainsAstWithoutRepparse(): void
    {
        $result = $this->runProbe('shared-parser');
        $this->assertTrue($result['ok']);
        $this->assertSame('shared-parser', $result['mode']);
        $this->assertGreaterThanOrEqual(200, $result['closed_before_evict']);
        $this->assertTrue($result['document_alive_after_evict']);
        $this->assertTrue($result['shared_parser_retained']);
        $this->assertFalse($result['parsed_after_evict']);
    }

    /**
     * @return array{
     *     ok: bool,
     *     mode: string,
     *     closed_before_evict: int,
     *     root_class: string,
     *     document_alive_after_evict: bool,
     *     factory_retained: bool,
     *     shared_parser_retained: bool,
     *     parsed_after_evict: bool
     * }
     */
    private function runProbe(string $mode): array
    {
        $process = new Process(
            [\PHP_BINARY, __DIR__.'/Fixtures/markdown-render-support-ast-release.php', $mode],
            env: [
                'HATFIELD_SESSION_ID' => false,
                'XDEBUG_MODE' => 'off',
            ],
            timeout: 8,
        );

        try {
            $process->mustRun();

            return json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
        } finally {
            $process->stop(0);
        }
    }
}
