<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests\Support;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Ineersa\HatfieldExt\TaskWorkflow\Pr\PrManager;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\GitHubTaskStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\MoveTaskHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\HookRunner;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorkspaceStateStore;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorktreeManager;

/** Stateful fake GitHub boundary; Git itself uses only the owned temporary remote. */
final class TransitionFixture implements ExecInterface
{
    public WorkspaceFixture $workspace;
    public WorkspaceStateStore $states;
    public MoveTaskHandler $handler;
    public GitHubTaskStore $store;
    public TaskWorkflowSettings $settings;
    public string $taskTitle = 'Title';
    public string $taskBody = 'Specification';
    public array $artifact = ['number' => 2, 'name' => 'Plan', 'body' => 'Original plan'];
    public ?string $comment = null;
    public string $status = 'TODO';
    public string $issueState = 'open';
    public ?string $closeReason = null;
    public ?array $pr = null;
    public array $calls = [];
    public array $hookResults = [];
    public array $hookCalls = [];
    public ?\Closure $onHook = null;

    public function __construct()
    {
        $this->workspace = new WorkspaceFixture();
        $root = $this->workspace->root;
        $git = new GitExecutor($this);
        $this->workspace->git->gitOk(['clone', '--bare', $root, $this->workspace->directory.'/remote.git'], $root);
        $this->workspace->git->gitOk(['remote', 'add', 'origin', $this->workspace->directory.'/remote.git'], $root);
        $this->workspace->git->gitOk(['push', 'origin', 'main:task/issue-7'], $root);
        foreach (['setup', 'validate', 'cleanup'] as $hook) {
            file_put_contents($root.'/'.$hook.'.sh', 'exit 0');
        }
        file_put_contents($root.'/.git/info/exclude', "*.sh\n");
        // Distinct paths let the boundary identify hook phases without inspecting command text.
        $settings = new TaskWorkflowSettings(repository: 'acme/project', worktreeRoot: $this->workspace->directory.'/tasks', hooks: array_combine(['setup', 'validate', 'cleanup'], array_map(static fn (string $name): array => ['script' => $name.'.sh', 'timeout_seconds' => 3], ['setup', 'validate', 'cleanup'])));
        $this->settings = $settings;
        $this->states = new WorkspaceStateStore($git, $root);
        $this->store = new GitHubTaskStore($this, $root, $settings);
        $this->handler = new MoveTaskHandler($this->store, $git, new WorktreeManager($git), new PrManager($this, $root, $settings), new HookRunner($this), $this->states, $root, $settings);
    }

    public function record(): ?array
    {
        return $this->states->load('acme/project', 7);
    }

    public function setPr(string $state = 'OPEN', string $base = 'main'): void
    {
        $record = $this->record();
        $sha = trim($this->workspace->git->gitOk(['rev-parse', 'HEAD'], $record['worktree'] ?? $this->workspace->root)->stdout);
        $this->pr = ['number' => 9, 'url' => 'https://github.com/acme/project/pull/9', 'state' => $state, 'mergedAt' => 'MERGED' === $state ? '2026-01-01T00:00:00Z' : null, 'headRepository' => ['nameWithOwner' => 'acme/project'], 'headRefName' => 'task/issue-7', 'baseRefName' => $base, 'headRefOid' => $sha, 'reviewDecision' => 'APPROVED', 'closingIssuesReferences' => [['number' => 7]]];
    }

    public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO
    {
        $this->calls[] = [$command, $args];
        if ('git' === $command) {
            if (['remote', 'get-url', 'origin'] === $args) {
                return new ExecResultDTO('https://github.com/acme/project.git', '', 0);
            }

            return $this->workspace->exec($command, $args, $options);
        }
        if ('bash' === $command) {
            $phase = basename($args[2], '.sh');
            $this->hookCalls[] = [$phase, $options->env, $options->cwd];
            $this->onHook?->__invoke($phase, $options);

            return $this->hookResults[$phase] ?? new ExecResultDTO('', '', 0);
        }
        if ('gh' !== $command) {
            throw new \LogicException('Unexpected command '.$command);
        }
        if ('pr' === $args[0]) {
            if ('view' === $args[1]) {
                return $this->json($this->pr ?? throw new \LogicException('No fixture PR'));
            }
            if ('merge' === $args[1]) {
                $this->pr['state'] = 'MERGED';
                $this->pr['mergedAt'] = '2026-01-01T00:00:00Z';
            }
            if ('close' === $args[1]) {
                $this->pr['state'] = 'CLOSED';
            }
            if ('create' === $args[1]) {
                $this->setPr();

                return new ExecResultDTO($this->pr['url'], '', 0);
            }

            return $this->json([]);
        }
        if ('issue' === $args[0]) {
            if ('create' === $args[1]) {
                $this->taskTitle = $args[array_search('--title', $args, true) + 1];
                $this->taskBody = file_get_contents($args[array_search('--body-file', $args, true) + 1]);
                $this->status = '';

                return new ExecResultDTO('https://github.com/acme/project/issues/7', '', 0);
            }

            return $this->json([]);
        }
        $endpoint = '';
        foreach ($args as $arg) {
            if ('graphql' === $arg || str_starts_with($arg, 'repos/') || str_starts_with($arg, 'orgs/')) {
                $endpoint = $arg;
                break;
            }
        }
        if ('' === $endpoint && \in_array('users/acme', $args, true)) {
            return $this->json(['type' => 'Organization']);
        }
        $payload = [];
        foreach ($args as $index => $arg) {
            if ('--input' === $arg) {
                $payload = json_decode(file_get_contents($args[$index + 1]), true, flags: \JSON_THROW_ON_ERROR);
            }
        }
        if ('graphql' === $endpoint) {
            $query = $payload['query'] ?? implode(' ', $args);
            if (str_contains($query, 'issueFieldValues')) {
                $nodes = ($payload['variables']['status'] ?? '') === $this->status ? [['number' => 7, 'url' => 'https://github.com/acme/project/issues/7', 'title' => $this->taskTitle, 'body' => $this->taskBody, 'state' => strtoupper($this->issueState), 'stateReason' => null === $this->closeReason ? null : strtoupper($this->closeReason), 'updatedAt' => '2026-01-01T00:00:00Z']] : [];

                return $this->json(['data' => ['repository' => ['issues' => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]]);
            }
            $key = str_contains($query, 'linkedBranches') ? 'linkedBranches' : 'closedByPullRequestsReferences';
            $nodes = [];
            if ('linkedBranches' !== $key && null !== $this->pr) {
                $node = ['number' => 9, 'url' => $this->pr['url'], 'state' => $this->pr['state'], 'headRefName' => $this->pr['headRefName'], 'headRepository' => $this->pr['headRepository']];
                $nodes = [$node];
            }

            return $this->json(['data' => ['repository' => ['issue' => [$key => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]]]);
        }
        if (str_contains($endpoint, '/pulls?')) {
            return $this->json([null === $this->pr ? [] : [['number' => 9]]]);
        }
        if (str_starts_with($endpoint, 'orgs/acme/issue-fields')) {
            return $this->json([['id' => 42, 'node_id' => 'IF_42', 'name' => 'Workflow', 'data_type' => 'single_select', 'options' => array_map(static fn (TaskStatusEnum $s): array => ['id' => 10, 'name' => $s->value], TaskStatusEnum::cases())]]);
        }
        if (str_ends_with($endpoint, '/issue-field-values')) {
            if (\in_array('POST', $args, true)) {
                $this->status = $payload['issue_field_values'][0]['value'];

                return $this->json([]);
            }

            return $this->json('' === $this->status ? [] : [['issue_field_id' => 42, 'data_type' => 'single_select', 'value' => 10, 'single_select_option' => ['id' => 10, 'name' => $this->status]]]);
        }
        if ('repos/acme/project/issues/7/artifacts/2' === $endpoint) {
            if (\in_array('PATCH', $args, true)) {
                $this->artifact = [...$this->artifact, ...$payload];

                return $this->json($this->artifact);
            }

            return $this->json(['artifact' => $this->artifact, 'versions' => [], 'has_next_page' => false, 'total_count' => 0]);
        }
        if ('repos/acme/project/issues/7/comments' === $endpoint) {
            $this->comment = $payload['body'];

            return $this->json(['id' => 1, 'body' => $this->comment]);
        }
        if ('repos/acme/project/issues/7' === $endpoint) {
            if (\in_array('PATCH', $args, true)) {
                $this->issueState = $payload['state'] ?? $this->issueState;
                $this->closeReason = $payload['state_reason'] ?? $this->closeReason;
                $this->taskTitle = $payload['title'] ?? $this->taskTitle;
                $this->taskBody = $payload['body'] ?? $this->taskBody;
            }

            return $this->json(['number' => 7, 'html_url' => 'https://github.com/acme/project/issues/7', 'title' => $this->taskTitle, 'body' => $this->taskBody, 'state' => $this->issueState, 'state_reason' => $this->closeReason, 'updated_at' => '2026-01-01T00:00:00Z']);
        }
        throw new \LogicException('Unexpected gh call '.implode(' ', $args));
    }

    private function json(array $data): ExecResultDTO
    {
        return new ExecResultDTO(json_encode($data, \JSON_THROW_ON_ERROR), '', 0);
    }
}
