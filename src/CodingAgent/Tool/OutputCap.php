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
 * config via {@see OutputCapConfig} which is injected through DI.
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
     * @param OutputCapConfig $config Resolved cap settings from Hatfield config.
     *                                Production code always receives this from
     *                                DI. Tests construct OutputCapConfig directly.
     */
    public function __construct(
        private readonly OutputCapConfig $config,
    ) {
        $this->storageDir = $config->storageDir;
        $this->defaultCap = $config->defaultCap;
        $this->docCap = $config->docCap;
        $this->retentionSeconds = $config->retentionSeconds;
        $this->sessionPrefix = $config->sessionPrefix;
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
     * Build a unique filename for persisted output.
     *
     * Format: [session_prefix|Ymd]-[16-random-hex].txt
     */
    private function buildFilename(): string
    {
        $prefix = $this->sessionPrefix ?? date('Ymd');

        return $prefix.'-'.bin2hex(random_bytes(8)).'.txt';
    }

    /**
     * Determine which character cap applies based on file extension.
     *
     * Doc-like extensions (.md, .txt, .toon) use {@see docCap}.
     * Everything else uses {@see defaultCap}.
     * Null paths (no file context) use {@see defaultCap}.
     */
    private function resolveCap(?string $path): int
    {
        if (null === $path) {
            return $this->defaultCap;
        }

        foreach (self::DOC_EXTENSIONS as $ext) {
            if (str_ends_with(strtolower($path), '.'.$ext)) {
                return $this->docCap;
            }
        }

        return $this->defaultCap;
    }

    /**
     * Build a model-facing notice about capped output.
     */
    private function buildCappedNotice(string $fullText, int $cap, string $savedPath): string
    {
        $charCount = mb_strlen($fullText);
        $tokenEstimate = (int) ceil($charCount / 4);

        return \sprintf(
            "[Output capped to %d characters, full output saved to %s]\n\nFull output: %d characters (~%d tokens).\nSaved to: %s\n\nTo view: **%s**\nTo view first lines: `head -50 %s`\nTo search: `grep 'pattern' %s`\n",
            $cap,
            $savedPath,
            $charCount,
            $tokenEstimate,
            $savedPath,
            $savedPath,
            $savedPath,
            $savedPath,
        );
    }
}
