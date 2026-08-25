<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Session;

use Ineersa\CodingAgent\Agent\Diagnostics\SessionPromptCacheInspectionService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Human-readable provider cache-usage and optional prompt-cache diagnostics inspector.
 *
 * Usage:
 *   bin/console session:cache:inspect <session-id>
 */
#[AsCommand(
    name: 'session:cache:inspect',
    description: 'Inspect provider cache usage and optional privacy-safe request fingerprints for a session',
)]
final class SessionCacheInspectCommand
{
    public function __construct(
        private readonly SessionPromptCacheInspectionService $inspectionService,
    ) {
    }

    public function __invoke(
        #[Argument(description: 'Session ID to inspect')]
        string $sessionId,
        ?OutputInterface $output = null,
    ): int {
        $io = new SymfonyStyle(new ArgvInput(), $output);
        $report = $this->inspectionService->inspect($sessionId);

        if (!$report['found']) {
            $io->error($report['notice'] ?? 'Session not found.');

            return Command::FAILURE;
        }

        $io->title(\sprintf('Session prompt-cache inspect: %s', $report['session_id']));
        $io->note([
            'usage_source=provider_reported; prefix diffs are local structural inference only.',
            'cache_read = cache_read_tokens ?? cached_tokens; cache_write = cache_creation_tokens;',
            'uncached = input - read - write only when provider cache telemetry is present;',
            'cost uses persisted usage.cost (never recomputed from current pricing).',
            'Families are never combined across run/model/provider/transport/cache-family.',
        ]);

        $families = $report['families'];
        if ([] === $families) {
            $io->warning('No llm_step_completed/failed/aborted events found for this session or its child artifacts.');

            return Command::SUCCESS;
        }

        $hasStructuralDiagnostics = false;
        $summaryRows = [];
        foreach ($families as $family) {
            $hasStructuralDiagnostics = $hasStructuralDiagnostics || true === ($family['prefix_attribution_available'] ?? false);
            $summaryRows[] = [
                $family['role'] ?? 'unknown',
                $this->short($family['run_id'] ?? ''),
                $family['model'] ?? 'unknown',
                $family['provider'] ?? 'unknown',
                $family['transport'] ?? 'unknown',
                (string) ($family['request_count'] ?? 0),
                (string) ($family['input_tokens'] ?? 0),
                (string) ($family['output_tokens'] ?? 0),
                (string) ($family['thinking_tokens'] ?? 0),
                (string) ($family['cache_read_tokens'] ?? 0),
                (string) ($family['cache_write_tokens'] ?? 0),
                true === ($family['uncached_available'] ?? false) ? (string) ($family['uncached_tokens'] ?? 0) : 'n/a',
                $this->ratioLabel($family['cache_ratio'] ?? null),
                number_format((float) ($family['cost'] ?? 0.0), 6),
            ];
        }

        if (!$hasStructuralDiagnostics) {
            $io->warning('Detailed prefix diagnostics were not recorded. Launch future runs with HATFIELD_WRITE_PROMPT_CACHE_DIAGNOSTICS=1; it only records future requests.');
        }

        $io->section('Per-family summary (not combined)');
        $io->table(
            ['Role', 'Run', 'Model', 'Provider', 'Transport', 'Reqs', 'In', 'Out', 'Think', 'CRead', 'CWrite', 'Uncached', 'Ratio', 'Cost'],
            $summaryRows,
        );

        foreach ($families as $index => $family) {
            $title = \sprintf(
                'Family %d: %s run=%s model=%s provider=%s transport=%s cache_fp=%s',
                $index + 1,
                $family['role'] ?? 'unknown',
                $family['run_id'] ?? '',
                $family['model'] ?? 'unknown',
                $family['provider'] ?? 'unknown',
                $family['transport'] ?? 'unknown',
                $this->short((string) ($family['cache_family_fp'] ?? 'none'), 12),
            );
            $io->section($title);

            if (true !== ($family['prefix_attribution_available'] ?? false)) {
                $io->warning('Prefix attribution unavailable for this family (historical usage-only events). No cause was inferred.');
            }

            $requestRows = [];
            foreach ($family['requests'] ?? [] as $request) {
                if (!\is_array($request)) {
                    continue;
                }
                $requestRows[] = [
                    (string) ($request['seq'] ?? ''),
                    (string) ($request['step_id'] ?? ''),
                    $this->timeLabel($request['created_at'] ?? null),
                    (string) ($request['input_tokens'] ?? 0),
                    (string) ($request['output_tokens'] ?? 0),
                    (string) ($request['thinking_tokens'] ?? 0),
                    (string) ($request['cache_read_tokens'] ?? 0),
                    (string) ($request['cache_write_tokens'] ?? 0),
                    true === ($request['uncached_available'] ?? false) ? (string) ($request['uncached_tokens'] ?? 0) : 'n/a',
                    $this->ratioLabel($request['cache_ratio'] ?? null),
                    number_format((float) ($request['cost'] ?? 0.0), 6),
                ];
            }

            $io->table(
                ['Seq', 'Step', 'Time', 'In', 'Out', 'Think', 'CRead', 'CWrite', 'Uncached', 'Ratio', 'Cost'],
                $requestRows,
            );

            foreach ($family['requests'] ?? [] as $request) {
                if (!\is_array($request) || !\is_array($request['prefix_diff'] ?? null)) {
                    continue;
                }
                $diff = $request['prefix_diff'];
                if (($diff['kind'] ?? null) === 'stable') {
                    $io->text(\sprintf(
                        '  seq %s prefix: stable (common_prefix_len=%s) [local_structure]',
                        $request['seq'] ?? '?',
                        $diff['common_prefix_len'] ?? 0,
                    ));
                    continue;
                }
                $io->text(\sprintf(
                    '  seq %s prefix first %s at index %s: prev=%s/%s/%s/%s bytes=%s → curr=%s/%s/%s/%s bytes=%s [local_structure, not provider invalidation]',
                    $request['seq'] ?? '?',
                    $diff['kind'] ?? 'changed',
                    $diff['index'] ?? '?',
                    $diff['previous_section'] ?? '-',
                    $diff['previous_type'] ?? '-',
                    $diff['previous_role'] ?? '-',
                    $diff['previous_name'] ?? '-',
                    $diff['previous_bytes'] ?? '-',
                    $diff['current_section'] ?? '-',
                    $diff['current_type'] ?? '-',
                    $diff['current_role'] ?? '-',
                    $diff['current_name'] ?? '-',
                    $diff['current_bytes'] ?? '-',
                ));
            }
        }

        return Command::SUCCESS;
    }

    private function ratioLabel(mixed $ratio): string
    {
        if (!\is_float($ratio) && !\is_int($ratio)) {
            return 'n/a';
        }

        return number_format(((float) $ratio) * 100, 1).'%';
    }

    private function timeLabel(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return \is_string($value) ? $value : '';
    }

    private function short(string $value, int $len = 8): string
    {
        if (\strlen($value) <= $len) {
            return $value;
        }

        return substr($value, 0, $len).'…';
    }
}
