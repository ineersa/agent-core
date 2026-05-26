<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Output cap settings resolved from Hatfield config.
 *
 * Immutable value object. Contains the storage directory for persisted
 * oversized tool output, character caps for code vs doc-like paths,
 * retention duration for stale-file cleanup, and an optional session
 * prefix for filename generation (wired by TOOLS-R04+).
 *
 * The storageDir is always an absolute path.
 */
final readonly class OutputCapConfig
{
    /**
     * @param string      $storageDir       Absolute directory path for persisted output files
     * @param int         $defaultCap       Default character cap for non-doc paths
     * @param int         $docCap           Character cap for doc-like paths (.md, .txt, .toon)
     * @param int         $retentionSeconds Max age in seconds before stale files are cleaned up
     * @param string|null $sessionPrefix    Optional session/run prefix for filenames (wired by TOOLS-R04)
     */
    public function __construct(
        public string $storageDir,
        public int $defaultCap = 20000,
        public int $docCap = 50000,
        public int $retentionSeconds = 86400,
        public ?string $sessionPrefix = null,
    ) {
    }

    /**
     * DI factory — extract output-cap settings from resolved AppConfig.
     *
     * Used by the Symfony container via services.yaml factory definition.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return self::fromArray($appConfig->raw, $appConfig->cwd);
    }

    /**
     * Create from raw merged Hatfield config.
     *
     * Paths are expected to already be resolved by AppConfigLoader.
     *
     * @param array<string, mixed> $data The resolved merged config array
     * @param string|null          $cwd  Fallback project directory when path is not in config
     */
    public static function fromArray(array $data, ?string $cwd = null): self
    {
        $tools = $data['tools'] ?? [];
        if (!\is_array($tools)) {
            $tools = [];
        }

        $cap = $tools['output_cap'] ?? [];
        if (!\is_array($cap)) {
            $cap = [];
        }

        $storageDir = $cap['path'] ?? null;
        if (!\is_string($storageDir) || '' === $storageDir) {
            $storageDir = self::resolveDefaultStorageDir($cwd);
        }

        $defaultCap = $cap['default_cap'] ?? null;
        $defaultCap = \is_int($defaultCap) ? $defaultCap : 20000;

        $docCap = $cap['doc_cap'] ?? null;
        $docCap = \is_int($docCap) ? $docCap : 50000;

        $retention = $cap['retention'] ?? null;
        $retention = \is_int($retention) ? $retention : 86400;

        $sessionPrefix = $cap['session_prefix'] ?? null;
        $sessionPrefix = \is_string($sessionPrefix) && '' !== $sessionPrefix ? $sessionPrefix : null;

        return new self(
            storageDir: $storageDir,
            defaultCap: $defaultCap,
            docCap: $docCap,
            retentionSeconds: $retention,
            sessionPrefix: $sessionPrefix,
        );
    }

    /**
     * Default storage directory: CWD/.hatfield/tmp/output-cap.
     *
     * Used as a fallback when tools.output_cap.path is not present in the
     * resolved config data (should not happen in production — defaults
     * are always loaded).
     */
    private static function resolveDefaultStorageDir(?string $cwd = null): string
    {
        $cwd ??= getcwd();

        return (false !== $cwd ? $cwd : '/tmp').'/.hatfield/tmp/output-cap';
    }
}
