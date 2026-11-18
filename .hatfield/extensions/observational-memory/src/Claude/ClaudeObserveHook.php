<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Claude;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\HatfieldExt\ObservationalMemory\DTO\ClaudeHookInputDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Librarian\LibrarianOmClient;
use Ineersa\HatfieldExt\ObservationalMemory\Librarian\LibrarianProjectPolicy;
use Psr\Log\LoggerInterface;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Claude Code Stop/SessionEnd hook: one content-free observe signal.
 *
 * Librarian locates and reads the transcript itself, so the hook sends only
 * the session id and the number of complete transcript lines. It is best
 * effort: every failure is logged to stderr and the hook still succeeds.
 */
final readonly class ClaudeObserveHook
{
    private const int READ_CHUNK_BYTES = 1_048_576;

    public function __construct(
        private ClaudeOmSettingsProvider $settings,
        private ExecInterface $exec,
        private LoggerInterface $logger,
        private ?HttpClientInterface $httpClient = null,
    ) {
    }

    /** @param string $projectDir CLAUDE_PROJECT_DIR, the directory Claude Code was started in */
    public function handle(string $input, string $projectDir): void
    {
        $sessionId = null;
        try {
            $root = realpath($projectDir);
            if (false === $root) {
                return;
            }
            $settings = $this->settings->settings($root);
            if (null === $settings) {
                return;
            }
            $hook = (new Serializer([new ObjectNormalizer(propertyTypeExtractor: new ReflectionExtractor())], [new JsonEncoder()]))
                ->deserialize($input, ClaudeHookInputDTO::class, 'json');
            if (0 !== \count(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($hook))) {
                throw new \UnexpectedValueException('Invalid Claude Code hook input.');
            }
            $sessionId = $hook->session_id;
            if (!ClaudeProjectRootPolicy::allows($root)) {
                return;
            }
            // Librarian finds the transcript under the header root encoded the
            // way Claude Code names project directories. A mismatch (a symlinked
            // start directory, or a name Claude Code shortened) means Librarian
            // cannot find it either: report it instead of skipping silently.
            $encodedRoot = preg_replace('/[^A-Za-z0-9]/', '-', $root);
            if (basename($hook->transcript_path) !== $sessionId.'.jsonl' || basename(\dirname($hook->transcript_path)) !== $encodedRoot) {
                $this->logger->warning('om.claude.transcript_path_mismatch', [
                    'component' => 'observational_memory', 'event_type' => 'om.claude.transcript_path_mismatch',
                    'run_id' => $sessionId, 'session_id' => $sessionId,
                ]);

                return;
            }
            if (!is_file($hook->transcript_path) || !(new LibrarianProjectPolicy($this->exec, $this->logger))->allows($root)) {
                return;
            }
            $endSeq = $this->completeLines($hook->transcript_path);
            if (0 === $endSeq) {
                return;
            }
            (new LibrarianOmClient($settings->librarian, $root, $this->logger, $this->httpClient))->observeClaudeCode($sessionId, $endSeq);
        } catch (\Throwable $error) {
            // Intentional local degradation: the next turn's signal covers this one.
            $this->logger->warning('om.claude.observe_hook_failed', [
                'component' => 'observational_memory', 'event_type' => 'om.claude.observe_hook_failed',
                'run_id' => $sessionId, 'session_id' => $sessionId,
                'exception_class' => $error::class,
            ]);
        }
    }

    /**
     * Librarian ignores an unterminated last line (still being written), so
     * count newlines only. Streams: transcripts reach tens of megabytes.
     */
    private function completeLines(string $path): int
    {
        $handle = fopen($path, 'rb');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open the Claude Code transcript.');
        }
        try {
            $lines = 0;
            while (!feof($handle)) {
                $chunk = fread($handle, self::READ_CHUNK_BYTES);
                if (false === $chunk) {
                    throw new \RuntimeException('Cannot read the Claude Code transcript.');
                }
                $lines += substr_count($chunk, "\n");
            }

            return $lines;
        } finally {
            fclose($handle);
        }
    }
}
