<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Query;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Librarian\LibrarianClient;
use Ineersa\HatfieldExt\ObservationalMemory\Librarian\LibrarianOmClient;
use Ineersa\HatfieldExt\ObservationalMemory\Librarian\LibrarianProjectPolicy;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Path;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Remote-only query surface; never opens local memory or journals. */
final class OmQueryService
{
    private const string ID_PATTERN = '/^[a-f0-9]{12,64}$/';

    private const int SEARCH_DEFAULT_LIMIT = 20;

    private const int SEARCH_MAX_LIMIT = 50;

    private const string MEMORY_DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/';
    private ?LibrarianClient $librarian = null;
    private ?bool $externalAllowed = null;

    /**
     * @param string                 $cwd        Host project binding used to resolve the Librarian project root
     * @param \Closure(string): bool $isChildRun Main-only policy: child runs never reach Librarian
     */
    public function __construct(
        private readonly ExecInterface $exec,
        private readonly string $cwd,
        private readonly \Closure $isChildRun,
        private readonly OmSettings $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?HttpClientInterface $librarianHttpClient = null,
    ) {
    }

    public function usesLibrarian(): bool
    {
        return $this->externalAllowed ??= (new LibrarianProjectPolicy($this->exec, $this->logger))->allows($this->cwd);
    }

    public function projection(string $runId): ?string
    {
        if (($this->isChildRun)($runId) || !$this->usesLibrarian()) {
            return null;
        }

        return (new LibrarianOmClient($this->settings->librarian, $this->projectRoot(), $this->logger, $this->librarianHttpClient))->projection($runId);
    }

    public function formatView(string $runId): string
    {
        $text = $this->projection($runId);
        if (null === $text) {
            return 'OM view unavailable.';
        }

        return '' === trim($text) ? 'No observational memory for this session.' : $text;
    }

    /** @return array<string, mixed> */
    public function search(
        string $query,
        ?string $after = null,
        ?string $before = null,
        ?int $limit = null,
        ?ToolCancellationTokenInterface $cancellationToken = null,
        ?int $timeoutSeconds = null,
        ?int $deadlineNs = null,
        ?string $runId = null,
    ): array {
        if (null !== $runId && ($this->isChildRun)($runId)) {
            return ['ok' => false, 'error' => 'main_only', 'message' => 'Observational memory is available only in main runs.'];
        }
        $query = trim($query);
        if ('' === $query) {
            return [
                'ok' => false,
                'error' => 'invalid_query',
                'message' => 'query must be a non-empty string.',
            ];
        }

        if (null !== ($dateError = $this->validateMemoryDate($after, 'after'))) {
            return $dateError;
        }
        if (null !== ($dateError = $this->validateMemoryDate($before, 'before'))) {
            return $dateError;
        }
        if (null !== ($rangeError = $this->validateMemoryDateRange($after, $before))) {
            return $rangeError;
        }

        $limit = $limit ?? self::SEARCH_DEFAULT_LIMIT;
        if ($limit < 1) {
            return [
                'ok' => false,
                'error' => 'invalid_limit',
                'message' => 'limit must be a positive integer.',
            ];
        }
        if ($limit > self::SEARCH_MAX_LIMIT) {
            $limit = self::SEARCH_MAX_LIMIT;
        }

        if (null !== ($interrupt = $this->interruptMap($cancellationToken, $timeoutSeconds, $deadlineNs, 'Cancelled before Librarian request.'))) {
            return $interrupt;
        }

        if (!$this->usesLibrarian()) {
            return ['ok' => false, 'error' => 'om_unavailable', 'message' => 'Observational memory is unavailable in linked worktrees or when Git identity cannot be inspected.'];
        }

        return $this->external()->call('memory_search', array_filter([
            'query' => $query, 'after' => $after, 'before' => $before, 'limit' => $limit,
        ], static fn (mixed $value): bool => null !== $value), $cancellationToken, $deadlineNs, $runId);
    }

    /** @return array<string, mixed> */
    public function recall(
        string $runId,
        string $id,
        ?ToolCancellationTokenInterface $cancellationToken = null,
        ?int $timeoutSeconds = null,
        ?int $deadlineNs = null,
    ): array {
        if (($this->isChildRun)($runId)) {
            return ['ok' => false, 'error' => 'main_only', 'message' => 'Observational memory is available only in main runs.'];
        }
        $id = trim($id);
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            return [
                'ok' => false,
                'error' => 'invalid_id',
                'message' => 'id must be a lowercase hex string of 12 to 64 characters.',
            ];
        }

        if (!$this->usesLibrarian()) {
            return ['ok' => false, 'error' => 'om_unavailable', 'message' => 'Observational memory is unavailable in linked worktrees or when Git identity cannot be inspected.'];
        }

        return $this->external()->call('recall', ['id' => $id], $cancellationToken, $deadlineNs, $runId);
    }

    private function projectRoot(): string
    {
        // The public CWD is the host project binding used to resolve session paths.
        $root = realpath($this->cwd);

        return Path::canonicalize(false === $root ? $this->cwd : $root);
    }

    private function external(): LibrarianClient
    {
        return $this->librarian ??= new LibrarianClient($this->settings->librarian, $this->projectRoot(), $this->logger, $this->librarianHttpClient);
    }

    /** @return array<string, mixed>|null */
    private function interruptMap(
        ?ToolCancellationTokenInterface $cancellationToken,
        ?int $timeoutSeconds,
        ?int $deadlineNs,
        string $message,
    ): ?array {
        if (null !== $cancellationToken && $cancellationToken->isCancellationRequested()) {
            return [
                'cancelled' => true,
                'message' => $message,
            ];
        }

        if (null !== $deadlineNs && hrtime(true) >= $deadlineNs) {
            return [
                'timed_out' => true,
                'timeout_seconds' => $timeoutSeconds ?? 0,
                'message' => $message,
            ];
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function validateMemoryDate(?string $value, string $field): ?array
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        $value = trim($value);
        if (1 !== preg_match(self::MEMORY_DATE_PATTERN, $value)) {
            return [
                'ok' => false,
                'error' => 'invalid_'.$field,
                'message' => $field.' must be YYYY-MM-DD or YYYY-MM-DD HH:MM.',
            ];
        }

        if (10 === \strlen($value)) {
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (false === $dt || $dt->format('Y-m-d') !== $value) {
                return [
                    'ok' => false,
                    'error' => 'invalid_'.$field,
                    'message' => $field.' must be a real calendar date (YYYY-MM-DD or YYYY-MM-DD HH:MM).',
                ];
            }

            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value);
        if (false === $dt || $dt->format('Y-m-d H:i') !== $value) {
            return [
                'ok' => false,
                'error' => 'invalid_'.$field,
                'message' => $field.' must be a real calendar date (YYYY-MM-DD or YYYY-MM-DD HH:MM).',
            ];
        }

        return null;
    }

    /**
     * @return array{ok: false, error: string, message: string}|null
     */
    private function validateMemoryDateRange(?string $after, ?string $before): ?array
    {
        $after = null === $after || '' === trim($after) ? null : trim($after);
        $before = null === $before || '' === trim($before) ? null : trim($before);
        if (null === $after || null === $before) {
            return null;
        }

        $afterKey = $this->comparableMemorySortKey($this->normalizeObservationFilter($after, lowerBound: true) ?? $after);
        $beforeKey = $this->comparableMemorySortKey($this->normalizeObservationFilter($before, lowerBound: false) ?? $before);
        if ($afterKey > $beforeKey) {
            return [
                'ok' => false,
                'error' => 'invalid_date_range',
                'message' => 'after must be less than or equal to before.',
            ];
        }

        return null;
    }

    private function normalizeObservationFilter(?string $value, bool $lowerBound): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        $value = trim($value);
        if (10 === \strlen($value)) {
            return $lowerBound ? $value.' 00:00' : $value.' 23:59';
        }

        return $value;
    }

    private function comparableMemorySortKey(string $value): string
    {
        $value = trim($value);
        if ('' === $value) {
            return '';
        }

        if (1 === preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            return str_replace(' ', 'T', $value).':00';
        }
        if (1 === preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $value, $matches)) {
            return $matches[1].'T'.$matches[2];
        }

        return $value;
    }
}
