<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeModeBootstrapTest extends TestCase
{
    private const string BOOTSTRAP = __DIR__.'/../../../../src/CodingAgent/Tool/CodeMode/Resources/bootstrap.php.inc';

    public function testConnectionDoesNotInheritThePhpSocketReadTimeout(): void
    {
        // The child deliberately has no timer: the existing host budget/token
        // supervises and stops it. Assert the local setup without a 60s race.
        $bootstrap = file_get_contents(self::BOOTSTRAP);
        $this->assertIsString($bootstrap);
        $this->assertStringContainsString('stream_set_timeout($connection, -1);', $bootstrap);
    }

    #[DataProvider('readFailures')]
    public function testTimeoutAndPeerClosureAreDistinguished(bool $body, bool $closed): void
    {
        require_once self::BOOTSTRAP;
        $read = 'hatfield_code_mode_read';
        $this->assertIsCallable($read);
        [$reader, $writer] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        if ($body) {
            fwrite($writer, pack('N', 2));
        }
        if ($closed) {
            fclose($writer);
        } else {
            // No producer writes here: force a real timeout without a delayed
            // producer or an elapsed-time assertion (zero disables the flag).
            stream_set_timeout($reader, 0, 1);
        }

        try {
            $read($reader);
            $this->fail('Expected the incomplete frame to fail.');
        } catch (\RuntimeException $exception) {
            $part = $body ? 'body' : 'header';
            $this->assertSame(($closed ? 'Unexpected EOF' : 'Timed out').' while reading code-mode IPC '.$part.'.', $exception->getMessage());
            $this->assertSame(!$closed, stream_get_meta_data($reader)['timed_out']);
            $this->assertSame($closed, feof($reader));
        } finally {
            fclose($reader);
            if (!$closed) {
                fclose($writer);
            }
        }
    }

    public static function readFailures(): iterable
    {
        yield 'header timeout' => [false, false];
        yield 'body timeout' => [true, false];
        yield 'header EOF' => [false, true];
        yield 'body EOF' => [true, true];
    }
}
