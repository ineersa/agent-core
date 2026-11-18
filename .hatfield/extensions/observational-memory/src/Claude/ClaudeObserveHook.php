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
 * the session id and the number of complete transcript lines, counted
 * incrementally across turns. It is best
 * effort: every failure is logged to stderr and the hook still succeeds.
 */
final readonly class ClaudeObserveHook
{
    // Cursors of sessions that ended without SessionEnd; Claude Code's default transcript retention.
    private const int CURSOR_MAX_AGE_SECONDS = 30 * 86_400;

    public function __construct(
        private ClaudeOmSettingsProvider $settings,
        private ClaudeTranscriptLineCounter $lines,
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
            $endSeq = $this->lines->completeLines($sessionId, $hook->transcript_path);
            if (0 !== $endSeq) {
                (new LibrarianOmClient($settings->librarian, $root, $this->logger, $this->httpClient))->observeClaudeCode($sessionId, $endSeq);
            }
            if ('SessionEnd' === $hook->hook_event_name) {
                $this->lines->forget($sessionId);
                $this->lines->prune(self::CURSOR_MAX_AGE_SECONDS);
            }
        } catch (\Throwable $error) {
            // Intentional local degradation: the next turn's signal covers this one.
            $this->logger->warning('om.claude.observe_hook_failed', [
                'component' => 'observational_memory', 'event_type' => 'om.claude.observe_hook_failed',
                'run_id' => $sessionId, 'session_id' => $sessionId,
                'exception_class' => $error::class,
            ]);
        }
    }
}
