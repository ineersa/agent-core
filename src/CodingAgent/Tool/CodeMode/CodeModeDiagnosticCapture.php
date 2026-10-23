<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Contract\Tool\MalformedToolResultException;
use Ineersa\CodingAgent\Tool\OutputCap;

/** Read invocation-owned diagnostic files without materializing oversized streams. */
final class CodeModeDiagnosticCapture
{
    public readonly string $stdoutPath;
    public readonly string $stderrPath;

    public function __construct(
        private readonly OutputCap $outputCap,
        private readonly ?string $runId,
        string $workspace,
    ) {
        $this->stdoutPath = $workspace.'/stdout.log';
        $this->stderrPath = $workspace.'/stderr.log';
    }

    /** @return array{stdout?: string, stderr?: string} */
    public function prepare(int $wrapperPrefixLines): array
    {
        $diagnostics = [];
        foreach (['stdout' => $this->stdoutPath, 'stderr' => $this->stderrPath] as $label => $path) {
            $stream = @fopen($path, 'rb');
            if (false === $stream) {
                throw new \RuntimeException('Failed to open code-mode diagnostic stream.');
            }
            try {
                $captured = $this->outputCap->capture($this->validatedChunks($stream), $this->runId);
            } finally {
                fclose($stream);
            }
            if (\is_string($captured)) {
                $prepared = CodeModeDiagnostics::prepare(
                    'stdout' === $label ? $captured : null,
                    'stderr' === $label ? $captured : null,
                    $wrapperPrefixLines,
                );
                if (isset($prepared[$label])) {
                    $diagnostics[$label] = $prepared[$label];
                }
            } else {
                $artifact = $captured['output_cap'];
                $diagnostics[$label] = '[Raw '.$label.' diagnostics: '.$artifact['byte_count'].' bytes > '.OutputCap::STREAM_BYTE_LIMIT."-byte safety limit; paths and whitespace are unmodified]\nSaved full output: ".$artifact['saved_path'];
            }
        }

        return $diagnostics;
    }

    /**
     * Validate every byte without assuming read boundaries align with UTF-8.
     * Holding at most three trailing bytes avoids rejecting split characters. Even
     * a held, constrained-invalid prefix is checked on the next chunk or at EOF.
     *
     * @param resource $stream
     *
     * @return \Generator<string>
     */
    private function validatedChunks($stream): \Generator
    {
        $pending = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if (false === $chunk) {
                throw new \RuntimeException('Failed to read code-mode diagnostic stream.');
            }
            $text = $pending.$chunk;
            $pending = '';
            if (preg_match('/[\xC2-\xF4][\x80-\xBF]{0,2}$/D', $text, $match)) {
                $pending = $match[0];
                $text = substr($text, 0, -\strlen($pending));
            }
            if (!mb_check_encoding($text, 'UTF-8')) {
                throw new MalformedToolResultException();
            }
            yield $text;
        }
        if (!mb_check_encoding($pending, 'UTF-8')) {
            throw new MalformedToolResultException();
        }
        yield $pending;
    }
}
