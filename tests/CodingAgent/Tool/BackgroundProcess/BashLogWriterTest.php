<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\BackgroundProcess;

use Ineersa\CodingAgent\Tool\BackgroundProcess\BashLogWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BashLogWriter::class)]
final class BashLogWriterTest extends TestCase
{
    public function testRetainsBoundedPrefixAndTailWithExactDiscardCount(): void
    {
        $input = tmpfile();
        $output = tmpfile();
        $this->assertIsResource($input);
        $this->assertIsResource($output);

        try {
            fwrite($input, 'start');
            fseek($input, BashLogWriter::LIMIT + 1000);
            fwrite($input, 'end');
            rewind($input);

            BashLogWriter::copy($input, $output);

            $this->assertLessThanOrEqual(BashLogWriter::LIMIT, fstat($output)['size']);
            rewind($output);
            $this->assertSame('start', fread($output, 5));
            fseek($output, -128, \SEEK_END);
            $this->assertStringContainsString("end\n[Bash output retention limit: discarded 1131 bytes", stream_get_contents($output));
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    public function testPreservesShortBinaryOutput(): void
    {
        $input = fopen('php://memory', 'w+b');
        $output = fopen('php://memory', 'w+b');
        $this->assertIsResource($input);
        $this->assertIsResource($output);
        try {
            fwrite($input, "a\x00\xFFb");
            rewind($input);
            BashLogWriter::copy($input, $output);
            rewind($output);
            $this->assertSame("a\x00\xFFb", stream_get_contents($output));
        } finally {
            fclose($input);
            fclose($output);
        }
    }
}
