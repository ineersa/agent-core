<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\AgentCore\Contract\Tool\MalformedToolResultException;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticCapture;
use Ineersa\CodingAgent\Tool\OutputCap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class CodeModeDiagnosticCaptureTest extends TestCase
{
    private string $directory;
    private CodeModeDiagnosticCapture $capture;

    protected function setUp(): void
    {
        $this->directory = TestDirectoryIsolation::createOsTempDir('code-mode-capture');
        $cap = new OutputCap(new OutputCapConfig(storageDir: $this->directory.'/artifacts'), new LockFactory(new FlockStore($this->directory)), new NullLogger());
        $this->capture = new CodeModeDiagnosticCapture($cap, 'capture-test', $this->directory);
        file_put_contents($this->capture->stdoutPath, '');
        file_put_contents($this->capture->stderrPath, '');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->directory);
    }

    public function testSmallStreamsPreserveNormalizationAndUnicodeAcrossBoundaries(): void
    {
        $text = str_repeat('a', 8191)."\u{1f600}e\u{0301}";
        foreach (str_split($text, 13) as $chunk) {
            file_put_contents($this->capture->stdoutPath, $chunk, \FILE_APPEND);
        }
        file_put_contents($this->capture->stderrPath, "  /tmp/owned/script.php on line 7\n");
        $this->assertSame(['stdout' => $text, 'stderr' => 'script.php(4)'], $this->capture->prepare(3));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUtf8(): iterable
    {
        yield 'incomplete' => ["\xf0\x9f"];
        yield 'surrogate' => ["\xed\xa0\x80"];
        yield 'out of range' => ["\xf4\x90\x80\x80"];
        yield 'overlong' => ["\xc0\xaf"];
        yield 'bad continuation' => ["\x80"];
    }

    #[DataProvider('invalidUtf8')]
    public function testInvalidBytesAfterSafetyThresholdAreRefused(string $invalid): void
    {
        for ($i = 0; $i < 129; ++$i) {
            file_put_contents($this->capture->stderrPath, str_repeat('a', 8192), \FILE_APPEND);
        }
        file_put_contents($this->capture->stderrPath, $invalid, \FILE_APPEND);
        try {
            $this->expectException(MalformedToolResultException::class);
            $this->capture->prepare(3);
        } finally {
            $this->assertDirectoryDoesNotExist($this->directory.'/artifacts');
        }
    }
}
