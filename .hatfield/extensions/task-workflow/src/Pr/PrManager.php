<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Pr;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\HatfieldExt\TaskWorkflow\DTO\PullRequestDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exception\TaskTransitionException;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\InvocationControl;
use Symfony\Component\Filesystem\Filesystem;

final readonly class PrManager
{
    private const string FIELDS = 'number,url,state,mergedAt,headRepository,headRefName,baseRefName,headRefOid,reviewDecision,closingIssuesReferences';

    public function __construct(private ExecInterface $exec, private string $primaryRoot, private TaskWorkflowSettings $settings)
    {
    }

    public function view(int|string $reference, ?InvocationControl $control = null): PullRequestDTO
    {
        $data = json_decode($this->gh(['pr', 'view', (string) $reference, '--json', self::FIELDS], $control), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || !isset($data['number'], $data['headRepository']['nameWithOwner'], $data['headRefOid'], $data['url'])) {
            throw new TaskTransitionException('Invalid PR response; no state was inferred.');
        }
        $repository = $this->repositoryName();
        $expectedPrefix = 'https://'.$this->hostname().'/'.$repository.'/pull/';
        if (!str_starts_with($data['url'], $expectedPrefix) || $repository !== $data['headRepository']['nameWithOwner']) {
            throw new TaskTransitionException('PR repository/head repository differs from the configured task repository.');
        }

        return new PullRequestDTO(
            (int) $data['number'], $data['url'], (string) $data['state'], $data['mergedAt'] ?? null,
            $data['headRepository']['nameWithOwner'], (string) $data['headRefName'], (string) $data['baseRefName'],
            $data['headRefOid'], (string) ($data['reviewDecision'] ?? ''),
            array_map(static fn (array $issue): int => (int) $issue['number'], $data['closingIssuesReferences'] ?? []),
        );
    }

    /** @return list<PullRequestDTO> */
    public function find(string $branch, string $base, int $issue, ?InvocationControl $control = null): array
    {
        // The paginated REST lookup distinguishes a failed query from a proven empty
        // result. It includes closed/merged PRs so uncertain creates are recoverable.
        $endpoint = 'repos/'.$this->repositoryName().'/pulls?state=all&head='.rawurlencode(explode('/', $this->repositoryName())[0].':'.$branch).'&base='.rawurlencode($base).'&per_page=100';
        $pages = json_decode($this->gh(['api', '--hostname', $this->hostname(), '--paginate', '--slurp', $endpoint], $control), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($pages)) {
            throw new TaskTransitionException('Invalid PR lookup response.');
        }
        $matches = [];
        foreach ($pages as $page) {
            if (!\is_array($page)) {
                throw new TaskTransitionException('Invalid PR lookup page.');
            }
            foreach ($page as $row) {
                $pr = $this->view((int) $row['number'], $control);
                $this->assertIdentity($pr, $branch, $base, $issue);
                $matches[] = $pr;
            }
        }

        return $matches;
    }

    public function assertIdentity(PullRequestDTO $pr, string $branch, string $base, int $issue): void
    {
        if ($pr->headBranch !== $branch || $pr->baseBranch !== $base || !\in_array($issue, $pr->closingIssues, true)) {
            throw new TaskTransitionException('PR does not match the exact issue, head branch and base branch.');
        }
    }

    public function create(int $issue, string $branch, string $base, string $title, string $body, ?InvocationControl $control = null): PullRequestDTO
    {
        // Bodies are data, never shell source; temp files survive workspace removal.
        $filesystem = new Filesystem();
        $file = $filesystem->tempnam(sys_get_temp_dir(), 'hatfield-pr-');
        try {
            $filesystem->dumpFile($file, rtrim($body)."\n\nCloses #".$issue."\n");
            $url = trim($this->gh(['pr', 'create', '--head', $branch, '--base', $base, '--title', $title, '--body-file', $file], $control));
            if (!preg_match('~^https://[^\s]+/pull/\d+$~D', $url)) {
                throw new TaskTransitionException('PR creation returned no canonical URL; reconcile the remote lookup before retrying.');
            }

            return $this->view($url, $control);
        } finally {
            $filesystem->remove($file);
        }
    }

    public function merge(PullRequestDTO $pr, string $acceptedHead, ?InvocationControl $control = null): PullRequestDTO
    {
        if ($pr->headSha !== $acceptedHead) {
            throw new TaskTransitionException('PR head changed; inspect/review the actual revision before completion.');
        }
        if ('MERGED' === $pr->state) {
            return $pr;
        }
        if ('OPEN' !== $pr->state || \in_array($pr->reviewDecision, ['CHANGES_REQUESTED', 'REVIEW_REQUIRED'], true)) {
            throw new TaskTransitionException('PR is not open or its required review is not satisfied.');
        }
        // GitHub enforces protections. No admin bypass and no premature branch deletion.
        $this->gh(['pr', 'merge', (string) $pr->number, '--'.$this->settings->mergeMethod, '--match-head-commit', $acceptedHead], $control);

        return $this->view($pr->number, $control);
    }

    public function close(PullRequestDTO $pr, ?InvocationControl $control = null): PullRequestDTO
    {
        if ('MERGED' === $pr->state || null !== $pr->mergedAt) {
            throw new TaskTransitionException('PR is merged; use DONE reconciliation, never cancellation.');
        }
        if ('OPEN' === $pr->state) {
            $this->gh(['pr', 'close', (string) $pr->number], $control);
        }
        $current = $this->view($pr->number, $control);
        if ('CLOSED' !== $current->state || null !== $current->mergedAt) {
            throw new TaskTransitionException('PR was not confirmed closed and unmerged; reconcile completion or remote changes.');
        }

        return $current;
    }

    public function develop(int $issue, string $branch, string $base, ?InvocationControl $control = null): void
    {
        $this->gh(['issue', 'develop', (string) $issue, '--name', $branch, '--base', $base], $control);
    }

    /** @param list<string> $args */
    private function gh(array $args, ?InvocationControl $control): string
    {
        if (null !== $control?->interrupted()) {
            throw new TaskTransitionException('GitHub operation interrupted before launch.');
        }
        if ('api' !== $args[0]) {
            $args = [...$args, '--repo', $this->settings->repository];
        }
        $result = $this->exec->exec('gh', $args, new ExecOptionsDTO(
            cwd: $this->primaryRoot,
            timeout: $control?->remainingTimeoutSeconds(120.0) ?? 120.0,
            env: ['GH_PROMPT_DISABLED' => '1', 'GH_HOST' => $this->hostname()],
            cancellationToken: $control?->cancellationToken,
        ));
        if (0 !== $result->exitCode || $result->timedOut || $result->cancelled) {
            throw new TaskTransitionException('GitHub operation failed or was interrupted: '.substr($result->stderr, 0, 3000));
        }

        return $result->stdout;
    }

    private function repositoryName(): string
    {
        $parts = explode('/', $this->settings->repository);

        return implode('/', \array_slice($parts, -2));
    }

    private function hostname(): string
    {
        $parts = explode('/', $this->settings->repository);

        return 3 === \count($parts) ? $parts[0] : 'github.com';
    }
}
