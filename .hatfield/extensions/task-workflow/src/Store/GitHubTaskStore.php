<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Store;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Assets\TaskWorkflowMarkdownFrontmatter;
use Ineersa\HatfieldExt\TaskWorkflow\DTO\GitHubRelationshipDTO;
use Ineersa\HatfieldExt\TaskWorkflow\DTO\GitHubTaskDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exception\GitHubTaskException;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\InvocationControl;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

final class GitHubTaskStore
{
    private readonly string $hostname;
    private readonly string $owner;
    private readonly string $repo;

    public function __construct(private readonly ExecInterface $exec, private readonly string $primaryRoot, private readonly TaskWorkflowSettings $settings)
    {
        if (1 !== preg_match('~^(?:(?<host>[a-zA-Z0-9.-]+)/)?(?<owner>[a-zA-Z0-9_.-]+)/(?<repo>[a-zA-Z0-9_.-]+)$~', $settings->repository, $m)) {
            throw new GitHubTaskException('Configure repository as [HOST/]OWNER/REPO.');
        }
        $this->hostname = '' !== $m['host'] ? $m['host'] : 'github.com';
        $this->owner = $m['owner'];
        $this->repo = $m['repo'];
    }

    public function initialize(?InvocationControl $control = null): void
    {
        $this->run(['--version'], $control);
        $this->run(['issue', 'artifact', 'view', '--help'], $control);
        // Probe the artifact endpoint without creating a task or document.
        $issue = null;
        for ($page = 1; $page <= 5; ++$page) {
            $issues = $this->api('GET', $this->path().'/issues?state=all&per_page=100&page='.$page, [], $control);
            foreach ($issues as $candidate) {
                if (!isset($candidate['pull_request'])) {
                    $issue = $candidate;
                    break;
                }
            }
            if (null !== $issue || \count($issues) < 100) {
                break;
            }
        }
        if (null === $issue) {
            throw new GitHubTaskException('Artifact capability requires an existing issue (not a pull request) among the first 500 repository entries. Create an issue deliberately before initialization; no Workflow configuration was changed.');
        }
        $this->api('GET', $this->path().'/issues/'.$issue['number'].'/artifacts?per_page=1&page=1', [], $control);
        $this->field(true, $control);
    }

    public function read(int|string $reference, ?InvocationControl $control = null): GitHubTaskDTO
    {
        $field = $this->field(false, $control);
        $number = $this->number($reference);
        $issue = $this->api('GET', $this->path().'/issues/'.$number, [], $control);
        if (isset($issue['pull_request'])) {
            throw new GitHubTaskException('Reference identifies a pull request, not an issue.');
        }
        $values = $this->api('GET', $this->path().'/issues/'.$number.'/issue-field-values', [], $control);

        return $this->task($issue, $values, $field);
    }

    /** Bounded pages include both native open and closed tasks.
     * For multiple statuses, page is independently applied to each status. Return
     * up to perPage rows per status, in requested status order, deduplicated by
     * issue number. Filtered cursor traversal is capped at 20 pages per status;
     * only the requested page is enriched. No unfiltered history is traversed.
     *
     * @param list<TaskStatusEnum> $statuses
     *
     * @return list<GitHubTaskDTO>
     */
    public function list(array $statuses = [], int $page = 1, int $perPage = 30, ?InvocationControl $control = null): array
    {
        $this->bounds($page, $perPage);
        if ([] !== $statuses && $page > 20) {
            throw new GitHubTaskException('Filtered task listing supports pages 1..20.');
        }
        $field = $this->field(false, $control);
        $issues = [];
        $filters = [] === $statuses ? [null] : array_values(array_unique($statuses, \SORT_REGULAR));
        foreach ($filters as $status) {
            $endpoint = $this->path().'/issues?state=all&page='.$page.'&per_page='.$perPage;
            if (null !== $status) {
                $batch = $this->filteredIssues($field, $status, $page, $perPage, $control);
            } else {
                $batch = $this->api('GET', $endpoint, [], $control);
            }
            foreach ($batch as $issue) {
                if (!isset($issue['pull_request'])) {
                    $issues[$issue['number']] = $issue;
                }
            }
        }
        $tasks = [];
        foreach ($issues as $issue) {
            if (isset($issue['pull_request'])) {
                continue;
            }
            // Only enrich the bounded displayed page, never scan repository history.
            $values = $this->api('GET', $this->path().'/issues/'.$issue['number'].'/issue-field-values', [], $control);
            $task = $this->task($issue, $values, $field);
            if ([] === $statuses || \in_array($task->status, $statuses, true)) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    public function create(string $title, string $body, ?InvocationControl $control = null): GitHubTaskDTO
    {
        $this->field(false, $control);
        if ('' === trim($title)) {
            throw new GitHubTaskException('Issue title must not be empty.');
        }
        $body = TaskWorkflowMarkdownFrontmatter::parse($body)['body'];
        $filesystem = new Filesystem();
        $file = $this->temporaryFile('hatfield-issue-body-');
        try {
            $filesystem->dumpFile($file, $body);
            $url = trim($this->run(['issue', 'create', '--repo', $this->settings->repository, '--title', $title, '--body-file', $file], $control));
        } finally {
            $filesystem->remove($file);
        }
        try {
            $number = $this->number($url);

            return $this->adopt($this->read($number, $control), $control);
        } catch (GitHubTaskException $e) {
            throw new GitHubTaskException('Issue creation returned '.$url.'; recover this existing issue instead of creating a duplicate. '.$e->getMessage(), previous: $e);
        }
    }

    public function edit(GitHubTaskDTO $expected, ?string $title, ?string $body, ?InvocationControl $control = null): GitHubTaskDTO
    {
        $this->fresh($expected, $control);
        $payload = [];
        if (null !== $title) {
            if ('' === trim($title)) {
                throw new GitHubTaskException('Issue title must not be empty.');
            }
            $payload['title'] = $title;
        }
        if (null !== $body) {
            $payload['body'] = $body;
        }
        if ([] !== $payload) {
            $this->api('PATCH', $this->path().'/issues/'.$expected->number, $payload, $control);
        }

        return $this->read($expected->number, $control);
    }

    public function adopt(GitHubTaskDTO $expected, ?InvocationControl $control = null): GitHubTaskDTO
    {
        if (null !== $expected->status) {
            throw new GitHubTaskException('Task is already managed.');
        }

        return $this->writeStatus($expected, TaskStatusEnum::TODO, $control);
    }

    public function publish(GitHubTaskDTO $expected, TaskStatusEnum $status, ?InvocationControl $control = null): GitHubTaskDTO
    {
        if (null === $expected->status) {
            throw new GitHubTaskException('Explicit adoption is required before publication.');
        }

        return $this->writeStatus($expected, $status, $control);
    }

    public function close(GitHubTaskDTO $expected, string $reason, ?InvocationControl $control = null): GitHubTaskDTO
    {
        if (!\in_array($reason, ['completed', 'not_planned'], true)) {
            throw new GitHubTaskException('Close reason must be completed or not_planned.');
        }
        $current = $this->fresh($expected, $control);
        if ('closed' === strtolower($current->state)) {
            if ($reason !== $current->stateReason) {
                throw new GitHubTaskException('Already-closed issue has a conflicting reason.');
            }

            return $current;
        }
        $this->api('PATCH', $this->path().'/issues/'.$expected->number, ['state' => 'closed', 'state_reason' => $reason], $control);
        $closed = $this->read($expected->number, $control);
        if ('closed' !== strtolower($closed->state) || $reason !== $closed->stateReason) {
            throw new GitHubTaskException('GitHub did not confirm the requested close reason.');
        }

        return $closed;
    }

    /** @return list<GitHubRelationshipDTO> */
    public function relationships(int|string $reference, ?InvocationControl $control = null): array
    {
        $number = $this->number($reference);
        $relationships = [];
        foreach (['linkedBranches', 'closedByPullRequestsReferences'] as $connection) {
            $cursor = null;
            for ($page = 1; $page <= 100; ++$page) {
                $selection = 'linkedBranches' === $connection
                    ? 'linkedBranches(first:100,after:$cursor){nodes{ref{name repository{nameWithOwner url}}} pageInfo{hasNextPage endCursor}}'
                    : 'closedByPullRequestsReferences(first:100,after:$cursor,includeClosedPrs:true){nodes{number url state headRefName headRepository{nameWithOwner}} pageInfo{hasNextPage endCursor}}';
                $query = 'query($owner:String!,$repo:String!,$number:Int!,$cursor:String){repository(owner:$owner,name:$repo){issue(number:$number){'.$selection.'}}}';
                $payload = ['query' => $query, 'variables' => ['owner' => $this->owner, 'repo' => $this->repo, 'number' => $number]];
                if (null !== $cursor) {
                    $payload['variables']['cursor'] = $cursor;
                }
                $response = $this->api('POST', 'graphql', $payload, $control);
                $data = $response['data']['repository']['issue'][$connection] ?? null;
                if (!\is_array($data) || !isset($data['nodes'], $data['pageInfo'])) {
                    throw new GitHubTaskException('Relationship query returned incomplete data.');
                }
                foreach ($data['nodes'] as $node) {
                    if ('linkedBranches' === $connection) {
                        $ref = $node['ref'] ?? null;
                        if (!isset($ref['name'], $ref['repository']['url'], $ref['repository']['nameWithOwner'])) {
                            throw new GitHubTaskException('Native linked branch query returned an unavailable reference.');
                        }
                        $relationship = new GitHubRelationshipDTO('branch', $ref['repository']['url'].'/tree/'.rawurlencode($ref['name']), $ref['repository']['nameWithOwner'], $ref['name']);
                        $relationships[$relationship->url] = $relationship;
                    } else {
                        if (!isset($node['number'], $node['url'], $node['headRefName'], $node['state'])) {
                            throw new GitHubTaskException('Native closing pull request query returned incomplete data.');
                        }
                        $relationships[$node['url']] = new GitHubRelationshipDTO('pull_request', $node['url'], $node['headRepository']['nameWithOwner'] ?? '', $node['headRefName'], $node['number'], $node['state']);
                    }
                }
                if (!$data['pageInfo']['hasNextPage']) {
                    break;
                }
                $next = $data['pageInfo']['endCursor'];
                if (!\is_string($next) || $next === $cursor || 100 === $page) {
                    throw new GitHubTaskException('Relationship pagination could not complete safely.');
                }
                $cursor = $next;
            }
        }

        return array_values($relationships);
    }

    /**
     * Stale-read detection only; artifact editing has no atomic compare-and-set contract.
     *
     * @param array<string, mixed> $expected
     *
     * @return array<string, mixed>
     */
    public function editArtifact(int|string $reference, int $artifactNumber, array $expected, string $name, string $body, ?InvocationControl $control = null): array
    {
        if ($this->artifact($reference, $artifactNumber, $control) !== $expected) {
            throw new GitHubTaskException('Artifact changed since it was read.');
        }

        return $this->api('PATCH', $this->artifactPath($reference, $artifactNumber), ['name' => $name, 'body' => $body], $control);
    }

    /** @return array<string, mixed> */
    public function artifact(int|string $reference, int $artifactNumber, ?InvocationControl $control = null): array
    {
        $response = $this->api('GET', $this->artifactPath($reference, $artifactNumber), [], $control);
        $artifact = $response['artifact'] ?? null;
        if (!\is_array($artifact)) {
            throw new GitHubTaskException('GitHub returned an invalid artifact response: expected an artifact object.');
        }
        $errors = Validation::createValidator()->validate($artifact, new Assert\Collection(
            fields: [
                'number' => new Assert\Required([new Assert\NotNull(), new Assert\Type('integer'), new Assert\EqualTo($artifactNumber)]),
                'name' => new Assert\Required([new Assert\NotNull(), new Assert\Type('string')]),
                'body' => new Assert\Required([new Assert\NotNull(), new Assert\Type('string')]),
            ],
            allowExtraFields: true,
        ));
        if (\count($errors) > 0) {
            throw new GitHubTaskException('GitHub returned an invalid artifact response: number, name or body is missing or invalid.');
        }

        // Version-history pagination is not part of the current-content snapshot.
        return $artifact;
    }

    /** @return array<string, mixed> */
    public function comment(int|string $reference, string $body, ?InvocationControl $control = null): array
    {
        if ('' === trim($body)) {
            throw new GitHubTaskException('Comment must not be empty.');
        }

        return $this->api('POST', $this->path().'/issues/'.$this->number($reference).'/comments', ['body' => $body], $control);
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return list<array<string, mixed>>
     */
    private function filteredIssues(array $field, TaskStatusEnum $status, int $page, int $perPage, ?InvocationControl $control): array
    {
        if (!isset($field['node_id']) || !\is_string($field['node_id'])) {
            throw new GitHubTaskException('Workflow definition is missing its GraphQL node ID.');
        }
        $query = 'query($owner:String!,$repo:String!,$fieldId:ID!,$status:String!,$first:Int!,$cursor:String){repository(owner:$owner,name:$repo){issues(first:$first,after:$cursor,filterBy:{states:[OPEN,CLOSED],issueFieldValues:[{fieldId:$fieldId,singleSelectOptionValue:$status}]}){nodes{number url title body state stateReason updatedAt} pageInfo{hasNextPage endCursor}}}}';
        $cursor = null;
        for ($current = 1; $current <= $page; ++$current) {
            $response = $this->api('POST', 'graphql', ['query' => $query, 'variables' => ['owner' => $this->owner, 'repo' => $this->repo, 'fieldId' => $field['node_id'], 'status' => $status->value, 'first' => $perPage, 'cursor' => $cursor]], $control);
            $connection = $response['data']['repository']['issues'] ?? null;
            if (!\is_array($connection) || !isset($connection['nodes'], $connection['pageInfo']['hasNextPage'])) {
                throw new GitHubTaskException('Filtered task query returned incomplete data.');
            }
            if ($current === $page) {
                return array_map(static function (array $issue): array {
                    return ['number' => $issue['number'], 'html_url' => $issue['url'], 'title' => $issue['title'], 'body' => $issue['body'], 'state' => strtolower($issue['state']), 'state_reason' => isset($issue['stateReason']) ? strtolower($issue['stateReason']) : null, 'updated_at' => $issue['updatedAt']];
                }, $connection['nodes']);
            }
            if (!$connection['pageInfo']['hasNextPage']) {
                return [];
            }
            $next = $connection['pageInfo']['endCursor'] ?? null;
            if (!\is_string($next) || $next === $cursor) {
                throw new GitHubTaskException('Filtered task pagination returned an invalid cursor.');
            }
            $cursor = $next;
        }

        return [];
    }

    private function writeStatus(GitHubTaskDTO $expected, TaskStatusEnum $status, ?InvocationControl $control): GitHubTaskDTO
    {
        $field = $this->field(false, $control);
        $this->fresh($expected, $control);
        // POST adds this value; PUT or an empty array would destroy unrelated values.
        $this->api('POST', $this->path().'/issues/'.$expected->number.'/issue-field-values', ['issue_field_values' => [['field_id' => $field['id'], 'value' => $status->value]]], $control);
        $task = $this->read($expected->number, $control);
        if ($task->status !== $status) {
            throw new GitHubTaskException('GitHub did not confirm Workflow publication.');
        }

        return $task;
    }

    private function fresh(GitHubTaskDTO $expected, ?InvocationControl $control): GitHubTaskDTO
    {
        if ($expected->repository !== $this->settings->repository) {
            throw new GitHubTaskException('Snapshot belongs to another repository.');
        }
        $actual = $this->read($expected->number, $control);
        // This detects stale reads; GitHub does not provide atomic compare-and-set here.
        if (get_object_vars($actual) !== get_object_vars($expected)) {
            throw new GitHubTaskException('Task changed since it was read; reread before retrying.');
        }

        return $actual;
    }

    /** @return array<string, mixed> */
    private function field(bool $initialize, ?InvocationControl $control): array
    {
        $owner = $this->api('GET', 'users/'.$this->owner, [], $control);
        $endpoint = 'Organization' === ($owner['type'] ?? null) ? 'orgs/'.$this->owner.'/issue-fields' : 'user/issue-fields';
        if ('User' === ($owner['type'] ?? null)) {
            $user = $this->api('GET', 'user', [], $control);
            if (strtolower($user['login']) !== strtolower($this->owner)) {
                throw new GitHubTaskException('Personal owner-scoped fields require authentication as the repository owner.');
            }
        } elseif ('Organization' !== ($owner['type'] ?? null)) {
            throw new GitHubTaskException('Unsupported repository owner type.');
        }
        $fields = [];
        for ($page = 1; $page <= 20; ++$page) {
            $batch = $this->api('GET', $endpoint.'?per_page=100&page='.$page, [], $control);
            foreach ($batch as $definition) {
                if (!\is_array($definition) || !isset($definition['id'], $definition['node_id'], $definition['name'], $definition['data_type'])) {
                    throw new GitHubTaskException('Owner field query returned an invalid field definition.');
                }
            }
            array_push($fields, ...$batch);
            if (\count($batch) < 100) {
                break;
            }
            if (20 === $page) {
                throw new GitHubTaskException('Owner field pagination limit exceeded.');
            }
        }
        $matches = array_values(array_filter($fields, fn (array $f): bool => $this->settings->workflowField === $f['name']));
        if (\count($matches) > 1) {
            throw new GitHubTaskException('Ambiguous Workflow field.');
        }
        $options = [];
        foreach (TaskStatusEnum::cases() as $priority => $status) {
            $options[] = ['name' => $status->value, 'color' => 'gray', 'priority' => $priority];
        }
        if ([] === $matches) {
            if (!$initialize) {
                throw new GitHubTaskException('Workflow field missing; run deliberate project initialization.');
            }

            return $this->api('POST', $endpoint, ['name' => $this->settings->workflowField, 'data_type' => 'single_select', 'options' => $options], $control);
        }
        $field = $matches[0];
        if ('single_select' !== $field['data_type']) {
            throw new GitHubTaskException('Workflow must be a single_select field.');
        }
        $existing = $field['options'] ?? [];
        foreach ($existing as $option) {
            if (!\is_array($option) || !isset($option['id'], $option['name'])) {
                throw new GitHubTaskException('Workflow definition contains an invalid option.');
            }
        }
        $names = array_column($existing, 'name');
        if (\count($names) !== \count(array_unique($names))) {
            throw new GitHubTaskException('Ambiguous Workflow option names.');
        }
        $missing = array_values(array_filter($options, static fn (array $o): bool => !\in_array($o['name'], $names, true)));
        if ([] !== $missing) {
            if (!$initialize) {
                throw new GitHubTaskException('Workflow options missing; run deliberate project initialization.');
            }
            // Retain every existing option ID and supported property, including unrelated options.
            $preserved = array_map(static fn (array $o): array => array_intersect_key($o, array_flip(['id', 'name', 'description', 'color', 'priority'])), $existing);
            foreach ($preserved as $option) {
                if (!isset($option['id'], $option['name'], $option['color'], $option['priority'])) {
                    throw new GitHubTaskException('Cannot safely preserve an existing option missing id/name/color/priority.');
                }
            }
            $priority = [] === $preserved ? 0 : max(array_column($preserved, 'priority')) + 1;
            foreach ($missing as &$option) {
                $option['priority'] = $priority++;
            }
            unset($option);
            $field = $this->api('PATCH', $endpoint.'/'.$field['id'], ['options' => [...$preserved, ...$missing]], $control);
        }

        return $field;
    }

    /**
     * @param array<string, mixed>       $issue
     * @param list<array<string, mixed>> $values
     * @param array<string, mixed>       $field
     */
    private function task(array $issue, array $values, array $field): GitHubTaskDTO
    {
        $status = null;
        $matches = array_values(array_filter($values, static fn (array $v): bool => $v['issue_field_id'] === $field['id']));
        if (\count($matches) > 1) {
            throw new GitHubTaskException('Duplicate Workflow values.');
        }
        if ([] !== $matches) {
            $value = $matches[0];
            if ('single_select' !== $value['data_type']) {
                throw new GitHubTaskException('Invalid typed Workflow value.');
            }
            if (null !== ($value['value'] ?? null) || null !== ($value['single_select_option'] ?? null)) {
                $selected = $value['single_select_option'] ?? [];
                if (!isset($selected['id'], $selected['name']) || $selected['id'] !== $value['value']) {
                    throw new GitHubTaskException('Invalid typed Workflow option.');
                }
                $definitions = array_values(array_filter($field['options'], static fn (array $o): bool => $o['id'] === $selected['id'] && $o['name'] === $selected['name']));
                if (1 !== \count($definitions)) {
                    throw new GitHubTaskException('Workflow option does not match its owner-scoped definition.');
                }
                $status = TaskStatusEnum::tryFrom($selected['name']);
                if (null === $status) {
                    throw new GitHubTaskException('Unknown Workflow option: '.$selected['name']);
                }
            }
        }

        return new GitHubTaskDTO($issue['number'], $issue['html_url'], $issue['title'], $issue['body'] ?? '', $issue['state'], $issue['state_reason'] ?? null, $issue['updated_at'], $this->settings->repository, $status);
    }

    private function artifactPath(int|string $reference, int $artifactNumber): string
    {
        if ($artifactNumber < 1) {
            throw new GitHubTaskException('Use the artifact per-issue number, not its name.');
        }

        return $this->path().'/issues/'.$this->number($reference).'/artifacts/'.$artifactNumber;
    }

    private function number(int|string $reference): int
    {
        if (\is_string($reference) && str_starts_with($reference, 'https://')) {
            $prefix = 'https://'.$this->hostname.'/'.$this->owner.'/'.$this->repo.'/issues/';
            if (!str_starts_with($reference, $prefix)) {
                throw new GitHubTaskException('Issue URL belongs to a different repository.');
            }
            $reference = substr($reference, \strlen($prefix));
        }
        if (1 !== preg_match('/^[1-9][0-9]*$/D', (string) $reference) || (int) $reference <= 0) {
            throw new GitHubTaskException('Expected a positive issue number or canonical issue URL.');
        }

        return (int) $reference;
    }

    private function bounds(int $page, int $perPage): void
    {
        if ($page < 1 || $page > 1000 || $perPage < 1 || $perPage > 100) {
            throw new GitHubTaskException('Page must be 1..1000 and perPage 1..100.');
        }
    }

    private function path(): string
    {
        return 'repos/'.$this->owner.'/'.$this->repo;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     */
    private function api(string $method, string $endpoint, array $payload = [], ?InvocationControl $control = null): array
    {
        $args = ['api', '--hostname', $this->hostname, '--method', $method, '-H', 'Accept: application/vnd.github+json', '-H', 'X-GitHub-Api-Version: 2026-03-10', $endpoint];
        $file = null;
        $filesystem = new Filesystem();
        try {
            if ([] !== $payload) {
                // The OS temporary directory survives task-worktree removal. Bodies
                // and nested option arrays never become command-line arguments.
                $file = $this->temporaryFile('hatfield-github-json-');
                $filesystem->dumpFile($file, (new Serializer([], [new JsonEncoder()]))->serialize($payload, 'json'));
                $args[] = '--input';
                $args[] = $file;
            }
            $output = $this->run($args, $control);
        } finally {
            if (null !== $file) {
                $filesystem->remove($file);
            }
        }
        try {
            $result = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GitHubTaskException('GitHub returned invalid JSON.', previous: $e);
        }
        if (!\is_array($result) || isset($result['errors'])) {
            throw new GitHubTaskException('GitHub query failed or returned an invalid response.');
        }

        return $result;
    }

    private function temporaryFile(string $prefix): string
    {
        $directory = realpath(sys_get_temp_dir());
        $root = realpath($this->primaryRoot);
        if (false === $directory || Path::isBasePath(false !== $root ? $root : $this->primaryRoot, $directory)) {
            throw new GitHubTaskException('GitHub payloads require an OS temporary directory outside the project checkout.');
        }

        return (new Filesystem())->tempnam($directory, $prefix);
    }

    /** @param list<string> $args */
    private function run(array $args, ?InvocationControl $control): string
    {
        if (null !== $control?->interrupted()) {
            throw new GitHubTaskException('GitHub operation interrupted before execution.');
        }
        $result = $this->exec->exec('gh', $args, new ExecOptionsDTO(cwd: $this->primaryRoot, timeout: $control?->remainingTimeoutSeconds(120.0) ?? 120.0, env: ['GH_PROMPT_DISABLED' => '1', 'GH_HOST' => $this->hostname], cancellationToken: $control?->cancellationToken));
        if ($result->cancelled || $result->timedOut || 0 !== $result->exitCode) {
            throw new GitHubTaskException('GitHub command failed (exit '.$result->exitCode.', cancelled='.(int) $result->cancelled.', timed_out='.(int) $result->timedOut.'). '.substr($result->stderr, 0, 2000));
        }

        return $result->stdout;
    }
}
