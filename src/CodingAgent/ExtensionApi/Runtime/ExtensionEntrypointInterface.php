<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Runtime;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;

/**
 * Optional public contract for extensions that expose CLI entrypoints.
 *
 * Host bootstrap only: {@see \Ineersa\CodingAgent\CLI\ExtensionRunCommand}
 * loads extensions normally, resolves the selected instance, and invokes
 * {@see runEntrypoint()} with the process-local Extension API. This is not a
 * background-worker registry and does not supervise processes.
 */
interface ExtensionEntrypointInterface
{
    /**
     * Deterministic entrypoint names this extension supports.
     *
     * @return list<string>
     */
    public static function entrypoints(): array;

    /**
     * Run a named entrypoint with the process-local Extension API.
     *
     * @return int Process exit code (0 = success)
     */
    public function runEntrypoint(string $entrypoint, ExtensionApiInterface $api): int;
}
