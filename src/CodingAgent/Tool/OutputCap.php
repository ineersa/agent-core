<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\CodingAgent\Config\OutputCapConfig;

/**
 * Reusable output capping and persistence for text-producing tools.
 *
 * Applies a configurable character limit to tool output. Oversized text is
 * persisted to disk under a configurable storage directory and replaced with
 * a model-facing notice containing the saved path and inspection hints.
 *
 * Settings (defaults, storage path, caps, retention) hydrate from Hatfield
 * config via {@see OutputCapConfig}. Use the constructor parameters to
 * override specific values for testing.
 *
 * @see .pi/plans/toolbox-design-plan.md § "Output capping (OutputCap)"
 */
final class OutputCap
{
    /**
     * File extensions treated as "doc-like" (higher cap).
     */
    private const DOC_EXTENSIONS = ['md', 'txt', 'toon'];

    private readonly string $storageDir;
    private readonly int $defaultCap;
    private readonly int $docCap;
    private readonly int $retentionSeconds;
    private readonly ?string $sessionPrefix;
    private bool $cleanedUp = false;

    /**
     * @param OutputCapConfig|null $config           Resolved cap settings from Hatfield config.
     *                                               When null, built-in defaults are used (primarily
     *                                               for testing); production should always inject a
     *                                               configured instance via DI.
     * @param string|null          $storageDir       override storage directory (takes precedence
     *                                               over config)
     * @param int|null             $defaultCap       override default char cap (takes precedence
     *                                               over config)
     * @param int|null             $docCap           override doc-like char cap (takes precedence
     *                                               over config)
     * @param int|null             $retentionSeconds override retention (takes precedence over
     *                                               config)
     * @param string|null          $sessionPrefix    Override session prefix for filenames (takes
     *                                               precedence over config). Use null for date-based
     *                                               prefix.
     */
    public function __construct(
        ?OutputCapConfig $config = null,
        ?string $storageDir = null,
        ?int $defaultCap = null,
        ?int $docCap = null,
        ?int $retentionSeconds = null,
        ?string $sessionPrefix = null,
    ) {
        $cfg = $config ?? new OutputCapConfig(
            storageDir: self::resolveFallbackDir(),
        );

        $this->storageDir = $storageDir ?? $cfg->storageDir;
        $this->defaultCap = $defaultCap ?? $cfg->defaultCap;
        $this->docCap = $docCap ?? $cfg->docCap;
        $this->retentionSeconds = $retentionSeconds ?? $cfg->retentionSeconds;
        $this->sessionPrefix = $sessionPrefix ?? $cfg->sessionPrefix;
    }

    /**
     * Process text through output capping.
     *
     * If the text fits within the applicable cap (determined by $path
     * extension), it is returned unchanged. Otherwise the full text is
     * persisted to disk and a model-facing capped notice is returned.
     *
     * Cleanup of stale persisted files runs once on first call.
     *
     * @param string      $text the raw tool output
     * @param string|null $path Optional file path used to determine doc vs.
     *                          code cap. Null paths use the default cap.
     *
     * @return string the original text, or a capped notice with saved path
     *                and inspection hints
     */
    public function process(string $text, ?string $path = null): string
    {
        $this->maybeCleanup();

        $cap = $this->resolveCap($path);

        if (mb_strlen($text) <= $cap) {
            return $text;
        }

        $savedPath = $this->persist($text);

        return $this->buildCappedNotice($text, $cap, $savedPath);
    }

    /**
     * Persist full text to disk unconditionally.
     *
     * Useful when a consumer (e.g. bash tool) always wants full output
     * saved regardless of whether it exceeds the cap.
     *
     * Stale-file cleanup runs once on first call, matching process()
     * behaviour.
     *
     * @param string $text the text to persist
     *
     * @return string absolute path to the saved file
     *
     * @throws \RuntimeException when the storage directory cannot be
     *                           created or the file cannot be written
     */
    public function persist(string $text): string
    {
        $this->maybeCleanup();

        $this->ensureStorageDirExists();

        $filename = $this->buildFilename();
        $filePath = $this->storageDir.'/'.$filename;

        $written = @file_put_contents($filePath, $text, \LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException(\sprintf('Failed to write output cap file: %s', $filePath));
        }

        return $filePath;
    }

    /**
     * Delete stored files older than the configured retention period.
     *
     * Called automatically on first use, but exposed publicly so session
     * hooks or scheduled tasks can trigger it explicitly.
     */
    public function cleanup(): void
    {
        $dir = $this->storageDir;

        if (!is_dir($dir)) {
            return;
        }

        $cutoff = time() - $this->retentionSeconds;

        $handle = opendir($dir);
        if (false === $handle) {
            return;
        }

        while (($entry = readdir($handle)) !== false) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $filePath = $dir.'/'.$entry;

            if (is_file($filePath) && filemtime($filePath) < $cutoff) {
                @unlink($filePath);
            }
        }

        closedir($handle);
    }

    /**
     * Run cleanup once on first use (process() or persist()).
     *
     * Chose first-use invocation over constructor because cleanup is an
     * I/O operation that should not happen during container/DI wiring.
     */
    private function maybeCleanup(): void
    {
        if ($this->cleanedUp) {
            return;
        }

        $this->cleanedUp = true;
        $this->cleanup();
    }

    /**
     * Ensure the storage directory exists with restrictive permissions.
     *
     * @throws \RuntimeException when the directory cannot be created
     */
    private function ensureStorageDirExists(): void
    {
        if (is_dir($this->storageDir)) {
            return;
        }

        if (!@mkdir($this->storageDir, 0750, true) && !is_dir($this->storageDir)) {
            throw new \RuntimeException(\sprintf('Failed to create output cap storage directory: %s', $this->storageDir));
        }
    }

    /**
     * Build a unique filename for a persisted output file.
     *
     * Format: <prefix>-<random-hex>.txt
     * Prefix is the session prefix when set, otherwise today's date (Ymd).
     */
    private function buildFilename(): string
    {
        $prefix = $this->sessionPrefix ?? date('Ymd');

        return \sprintf(
            '%s-%s.txt',
            $prefix,
            bin2hex(random_bytes(8)),
        );
    }

    /**
     * Determine the character cap for a given file path.
     */
    private function resolveCap(?string $path): int
    {
        if (null === $path) {
            return $this->defaultCap;
        }

        $ext = mb_strtolower(
            pathinfo($path, \PATHINFO_EXTENSION),
        );

        if (\in_array($ext, self::DOC_EXTENSIONS, true)) {
            return $this->docCap;
        }

        return $this->defaultCap;
    }

    /**
     * Build the model-facing capped notice.
     *
     * @param string $text      the original (oversized) text
     * @param int    $cap       the cap that was exceeded
     * @param string $savedPath absolute path to the persisted full output
     *
     * @return string human-readable (and model-readable) capped notice
     */
    private function buildCappedNotice(string $text, int $cap, string $savedPath): string
    {
        $charCount = mb_strlen($text);
        $tokenEstimate = (int) ceil($charCount / 4);

        return \sprintf(
            "⛔ Output capped: %s chars (~%s tokens) exceeds %s char limit.\n\nFull output saved to: %s\nUse `head -50 %s` or `grep <pattern> %s` to inspect.",
            number_format($charCount),
            number_format($tokenEstimate),
            number_format($cap),
            $savedPath,
            $savedPath,
            $savedPath,
        );
    }

    /**
     * Fallback storage dir when neither config nor explicit path is given.
     *
     * Avoids silent root-filesystem paths by raising an exception when
     * getcwd() fails, matching AppConfig::resolveCurrentWorkingDirectory().
     *
     * This path is only reached in tests or edge cases where OutputCap is
     * constructed without any configuration — production always injects
     * OutputCapConfig via DI.
     *
     * @throws \RuntimeException when no current working directory is available
     */
    private static function resolveFallbackDir(): string
    {
        $cwd = getcwd();

        if (false === $cwd) {
            throw new \RuntimeException('No current working directory available.');
        }

        return $cwd.'/.hatfield/tmp/output-cap';
    }
}
