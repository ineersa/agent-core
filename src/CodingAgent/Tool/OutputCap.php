<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Reusable output capping and persistence for text-producing tools.
 *
 * Applies a configurable character limit to tool output. Oversized text is
 * persisted to disk under .hatfield/tmp/output-cap/ and replaced with a
 * model-facing notice containing the saved path and inspection hints.
 *
 * Settings defaults (20K code, 50K docs, 24h retention) can be wired from
 * Hatfield settings by TOOLS-R04.
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
    private bool $cleanedUp = false;

    /**
     * @param string|null $storageDir       Directory for persisted output files.
     *                                      Defaults to <cwd>/.hatfield/tmp/output-cap/.
     * @param int         $defaultCap       default char cap for non-doc paths (default 20,000)
     * @param int         $docCap           char cap for doc-like paths (default 50,000)
     * @param int         $retentionSeconds max age in seconds before stale files are
     *                                      cleaned up (default 86,400 = 24h)
     */
    public function __construct(
        ?string $storageDir = null,
        int $defaultCap = 20000,
        int $docCap = 50000,
        int $retentionSeconds = 86400,
    ) {
        $this->storageDir = $storageDir ?? getcwd().'/.hatfield/tmp/output-cap';
        $this->defaultCap = $defaultCap;
        $this->docCap = $docCap;
        $this->retentionSeconds = $retentionSeconds;
    }

    /**
     * Process text through output capping.
     *
     * If the text fits within the applicable cap (determined by $path
     * extension), it is returned unchanged.  Otherwise the full text is
     * persisted to disk and a model-facing capped notice is returned.
     *
     * Cleanup of stale persisted files runs once on first call (see
     * __construct docblock for rationale).
     *
     * @param string      $text the raw tool output
     * @param string|null $path Optional file path used to determine doc vs.
     *                          code cap.  Null paths use the default cap.
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
     * @param string $text the text to persist
     *
     * @return string absolute path to the saved file
     */
    public function persist(string $text): string
    {
        $dir = $this->storageDir;

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $filename = \sprintf(
            '%s-%s.txt',
            date('Ymd'),
            bin2hex(random_bytes(8)),
        );
        $filePath = $dir.'/'.$filename;

        file_put_contents($filePath, $text, \LOCK_EX);

        return $filePath;
    }

    /**
     * Delete stored files older than the configured retention period.
     *
     * Called automatically on first use (see maybeCleanup), but exposed
     * publicly so session hooks or scheduled tasks can trigger it explicitly.
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
     * Run cleanup once on first process() call.
     *
     * Chose first-use invocation over constructor because cleanup is an
     * I/O operation that should not happen during container/DI wiring.
     * TOOLS-R04 can wire session-start hooks to call cleanup() explicitly
     * if eager cleanup is desired.
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
}
