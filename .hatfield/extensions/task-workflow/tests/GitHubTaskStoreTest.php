<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exception\GitHubTaskException;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\GitHubTaskStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use PHPUnit\Framework\TestCase;

final class GitHubTaskStoreTest extends TestCase
{
    public function testReadUsesTypedOptionAndPreservesClosedActiveTask(): void
    {
        $calls = [];
        $store = $this->store([['type' => 'Organization'], [$this->field()], $this->issue(), $this->values()], $calls);
        $task = $store->read('https://github.com/acme/project/issues/7');
        $this->assertSame(TaskStatusEnum::CODE_REVIEW, $task->status);
        $this->assertSame('closed', $task->state);
        $this->assertSame('Free Markdown', $task->body);
        foreach ($calls as $call) {
            $this->assertContains('GET', $call);
        }
    }

    public function testListingIsBoundedAndIncludesClosedIssues(): void
    {
        $calls = [];
        $store = $this->store([['type' => 'Organization'], [$this->field()], $this->issuePage([], true, 'next'), $this->issuePage([$this->graphIssue()]), $this->values()], $calls);
        $this->assertCount(1, $store->list([TaskStatusEnum::CODE_REVIEW], 2, 5));
        $this->assertSame(['owner' => 'acme', 'repo' => 'project', 'fieldId' => 'IFSS_example', 'status' => 'CODE-REVIEW', 'first' => 5, 'cursor' => null], $calls[2]['payload']['variables']);
        $this->assertStringContainsString('states:[OPEN,CLOSED]', $calls[2]['payload']['query']);
        $this->assertStringContainsString('issueFieldValues:[{fieldId:$fieldId,singleSelectOptionValue:$status}]', $calls[2]['payload']['query']);
        $this->assertSame('next', $calls[3]['payload']['variables']['cursor']);
        $this->assertCount(5, $calls);
    }

    public function testMissingFieldRefusesWithoutCreation(): void
    {
        $calls = [];
        $store = $this->store([['type' => 'Organization'], []], $calls);
        $this->expectException(GitHubTaskException::class);
        $this->expectExceptionMessage('initialization');
        $store->read(7);
    }

    public function testPublishUsesDedicatedNonemptyPost(): void
    {
        $calls = [];
        $read = [['type' => 'Organization'], [$this->field()], $this->issue(), $this->values()];
        $store = $this->store([...$read, ['type' => 'Organization'], [$this->field()], ...$read, [], ['type' => 'Organization'], [$this->field()], $this->issue(), $this->values('DONE')], $calls);
        $snapshot = $store->read(7);
        $this->assertSame(TaskStatusEnum::DONE, $store->publish($snapshot, TaskStatusEnum::DONE)->status);
        $post = $calls[10];
        $this->assertContains('POST', $post);
        $this->assertContains('repos/acme/project/issues/7/issue-field-values', $post);
        $this->assertSame(['issue_field_values' => [['field_id' => 42, 'value' => 'DONE']]], $post['payload']);
        $this->assertContains('--input', $post);
        $this->assertFileDoesNotExist($post['input_path']);
    }

    public function testStaleSnapshotBlocksMutation(): void
    {
        $calls = [];
        $changed = $this->issue();
        $changed['body'] = 'Changed remotely';
        $read = [['type' => 'Organization'], [$this->field()], $this->issue(), $this->values()];
        $store = $this->store([...$read, ['type' => 'Organization'], [$this->field()], $changed, $this->values()], $calls);
        $snapshot = $store->read(7);
        $this->expectException(GitHubTaskException::class);
        $this->expectExceptionMessage('changed');
        $store->edit($snapshot, null, 'New body');
    }

    public function testGraphqlErrorsAreNotEmptyRelationships(): void
    {
        $calls = [];
        $store = $this->store([['errors' => [['message' => 'Forbidden']]]], $calls);
        $this->expectException(GitHubTaskException::class);
        $store->relationships(7);
    }

    public function testInitializePreservesUnrelatedOptionsAndTheirIds(): void
    {
        $calls = [];
        $field = $this->field();
        $field['options'] = [['id' => 77, 'name' => 'Other', 'color' => 'red', 'priority' => 12, 'description' => 'Keep me']];
        $store = $this->store([[], [], [['pull_request' => []], $this->issue()], [], ['type' => 'Organization'], [$field], $this->field()], $calls);
        $store->initialize();
        $this->assertContains('PATCH', $calls[6]);
        $this->assertSame($field['options'][0], $calls[6]['payload']['options'][0]);
        $this->assertSame(['name' => 'TODO', 'color' => 'gray', 'priority' => 13], $calls[6]['payload']['options'][1]);
    }

    public function testRelationshipPaginationRetainsMergedPullRequests(): void
    {
        $calls = [];
        $page = static fn (string $key, array $nodes, bool $more, ?string $cursor): array => ['data' => ['repository' => ['issue' => [$key => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $more, 'endCursor' => $cursor]]]]]];
        $pr = ['number' => 8, 'url' => 'https://github.com/acme/project/pull/8', 'state' => 'MERGED', 'headRefName' => 'task/7', 'headRepository' => ['nameWithOwner' => 'acme/project']];
        $store = $this->store([$page('linkedBranches', [], false, null), $page('closedByPullRequestsReferences', [], true, 'next'), $page('closedByPullRequestsReferences', [$pr], false, null)], $calls);
        $relationships = $store->relationships(7);
        $this->assertCount(1, $relationships);
        $this->assertSame('MERGED', $relationships[0]->state);
        $this->assertSame('task/7', $relationships[0]->branch);
        $this->assertSame('next', $calls[2]['payload']['variables']['cursor']);
        $this->assertStringContainsString('includeClosedPrs:true', $calls[2]['payload']['query']);
        $this->assertStringNotContainsString('timelineItems', $calls[2]['payload']['query']);
    }

    public function testMultilineCommentUsesJsonFileAndCleansUpOnFailure(): void
    {
        $calls = [];
        $store = $this->store([new ExecResultDTO('', str_repeat('x', 5000), 1)], $calls);
        $body = "# Notes\n\nLiteral @file and quotes \"x\"";
        try {
            $store->comment(7, $body);
            $this->fail('Expected refusal');
        } catch (GitHubTaskException $e) {
            $this->assertLessThan(2200, \strlen($e->getMessage()));
        }
        $this->assertSame(['body' => $body], $calls[0]['payload']);
        $this->assertNotContains($body, $calls[0]);
        $this->assertFileDoesNotExist($calls[0]['input_path']);
    }

    public function testCreateStripsFrontmatterAndReportsCreatedUrlOnPublicationFailure(): void
    {
        $calls = [];
        $url = 'https://github.com/acme/project/issues/7';
        $store = $this->store([['type' => 'Organization'], [$this->field()], new ExecResultDTO($url."\n", '', 0), new ExecResultDTO('', 'Forbidden', 1)], $calls);
        try {
            $store->create('Title', "---\nname: Feature\n---\n# Specification\n\nPlan");
            $this->fail('Expected refusal');
        } catch (GitHubTaskException $e) {
            $this->assertStringContainsString($url, $e->getMessage());
            $this->assertStringContainsString('instead of creating a duplicate', $e->getMessage());
        }
        $this->assertContains('--repo', $calls[2]);
        $this->assertContains('acme/project', $calls[2]);
        $this->assertSame("# Specification\n\nPlan", $calls[2]['body']);
        $this->assertFileDoesNotExist($calls[2]['body_path']);
    }

    public function testEmptyRepositoryInitializationDoesNotCreateFields(): void
    {
        $calls = [];
        $store = $this->store([[], [], []], $calls);
        $this->expectException(GitHubTaskException::class);
        $this->expectExceptionMessage('Create an issue deliberately');
        try {
            $store->initialize();
        } finally {
            $this->assertCount(3, $calls);
        }
    }

    public function testInitializeSkipsPullRequestOnlyPage(): void
    {
        $calls = [];
        $store = $this->store([[], [], array_fill(0, 100, ['pull_request' => []]), [$this->issue()], [], ['type' => 'Organization'], [$this->field()]], $calls);
        $store->initialize();
        $this->assertContains('repos/acme/project/issues?state=all&per_page=100&page=2', $calls[3]);
        $this->assertContains('repos/acme/project/issues/7/artifacts?per_page=1&page=1', $calls[4]);
    }

    public function testNullNativeRelationshipConnectionRefuses(): void
    {
        $calls = [];
        $store = $this->store([['data' => ['repository' => ['issue' => null]]]], $calls);
        $this->expectException(GitHubTaskException::class);
        $store->relationships(7);
    }

    public function testMultipleStatusesUseIndependentFilteredPages(): void
    {
        $calls = [];
        $store = $this->store([['type' => 'Organization'], [$this->field()], $this->issuePage([]), $this->issuePage([$this->graphIssue()]), $this->values()], $calls);
        $this->assertCount(1, $store->list([TaskStatusEnum::TODO, TaskStatusEnum::CODE_REVIEW], 1, 2));
        $this->assertSame('TODO', $calls[2]['payload']['variables']['status']);
        $this->assertSame('CODE-REVIEW', $calls[3]['payload']['variables']['status']);
        $this->assertSame(2, $calls[3]['payload']['variables']['first']);
    }

    public function testFailedCliPropagates(): void
    {
        $calls = [];
        $store = $this->store([new ExecResultDTO('', 'Forbidden', 1)], $calls);
        $this->expectException(GitHubTaskException::class);
        $store->read(7);
    }

    public function testForeignUrlRefused(): void
    {
        $calls = [];
        $store = $this->store([], $calls);
        $this->expectException(GitHubTaskException::class);
        $store->relationships('https://github.com/other/project/issues/7');
    }

    public function testFilteredPaginationRefusesUnboundedTraversal(): void
    {
        $calls = [];
        $store = $this->store([], $calls);
        $this->expectException(GitHubTaskException::class);
        $this->expectExceptionMessage('1..20');
        $store->list([TaskStatusEnum::TODO], 21);
    }

    public function testNullTypedValueIsUnmanaged(): void
    {
        $calls = [];
        $values = $this->values();
        $values[0]['value'] = null;
        $values[0]['single_select_option'] = null;
        $store = $this->store([['type' => 'Organization'], [$this->field()], $this->issue(), $values], $calls);
        $this->assertNull($store->read(7)->status);
    }

    public function testTypedValueMustMatchResolvedOptionIdentity(): void
    {
        $calls = [];
        $values = $this->values();
        $values[0]['single_select_option']['id'] = 999;
        $store = $this->store([['type' => 'Organization'], [$this->field()], $this->issue(), $values], $calls);
        $this->expectException(GitHubTaskException::class);
        $this->expectExceptionMessage('Invalid typed Workflow option');
        $store->read(7);
    }

    public function testLinkedBranchesPaginateIndependentlyOfHistoricalPullRequests(): void
    {
        $calls = [];
        $connection = static fn (string $name, array $nodes, bool $more = false, ?string $cursor = null): array => ['data' => ['repository' => ['issue' => [$name => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $more, 'endCursor' => $cursor]]]]]];
        $branch = ['ref' => ['name' => 'task/7', 'repository' => ['nameWithOwner' => 'acme/project', 'url' => 'https://github.com/acme/project']]];
        $store = $this->store([$connection('linkedBranches', [], true, 'branches-next'), $connection('linkedBranches', [$branch]), $connection('closedByPullRequestsReferences', [])], $calls);
        $relations = $store->relationships(7);
        $this->assertSame('branch', $relations[0]->type);
        $this->assertSame('task/7', $relations[0]->branch);
        $this->assertSame('branches-next', $calls[1]['payload']['variables']['cursor']);
        $this->assertArrayNotHasKey('cursor', $calls[2]['payload']['variables']);
    }

    private function issuePage(array $nodes, bool $hasNextPage = false, ?string $cursor = null): array
    {
        return ['data' => ['repository' => ['issues' => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $cursor]]]]];
    }

    private function graphIssue(): array
    {
        return ['number' => 7, 'url' => 'https://github.com/acme/project/issues/7', 'title' => 'Title', 'body' => 'Free Markdown', 'state' => 'CLOSED', 'stateReason' => 'COMPLETED', 'updatedAt' => '2026-01-01T00:00:00Z'];
    }

    private function field(): array
    {
        // Shape from github/rest-api-description issue-field and issue-field-value schemas.
        $options = [];
        foreach (TaskStatusEnum::cases() as $i => $status) {
            $options[] = ['id' => 10 + $i, 'name' => $status->value, 'color' => 'gray', 'priority' => $i];
        }

        return ['id' => 42, 'node_id' => 'IFSS_example', 'name' => 'Workflow', 'data_type' => 'single_select', 'options' => $options];
    }

    private function issue(): array
    {
        return ['number' => 7, 'html_url' => 'https://github.com/acme/project/issues/7', 'title' => 'Title', 'body' => 'Free Markdown', 'state' => 'closed', 'state_reason' => 'completed', 'updated_at' => '2026-01-01T00:00:00Z'];
    }

    private function values(string $status = 'CODE-REVIEW'): array
    {
        $names = array_column(TaskStatusEnum::cases(), 'value');
        $id = 10 + array_search($status, $names, true);

        return [['issue_field_id' => 42, 'issue_field_name' => 'Workflow', 'node_id' => 'IFSS_example', 'data_type' => 'single_select', 'value' => $id, 'single_select_option' => ['id' => $id, 'name' => $status, 'color' => 'gray']]];
    }

    private function store(array $responses, array &$calls): GitHubTaskStore
    {
        $exec = new class($responses, $calls) implements ExecInterface {
            public function __construct(private array $responses, private array &$calls)
            {
            }

            public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO
            {
                TestCase::assertSame(['GH_PROMPT_DISABLED' => '1', 'GH_HOST' => 'github.com'], $options?->env);
                $record = $args;
                $input = array_search('--input', $args, true);
                if (false !== $input) {
                    $file = $args[$input + 1];
                    TestCase::assertFalse(str_starts_with($file, '/primary/'));
                    $record['payload'] = json_decode(file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
                    $record['input_path'] = $file;
                }
                $body = array_search('--body-file', $args, true);
                if (false !== $body) {
                    $record['body'] = file_get_contents($args[$body + 1]);
                    $record['body_path'] = $args[$body + 1];
                }
                $this->calls[] = $record;
                if ([] === $this->responses) {
                    throw new \LogicException('Unexpected command: '.implode(' ', $args));
                }
                $response = array_shift($this->responses);

                return $response instanceof ExecResultDTO ? $response : new ExecResultDTO(json_encode($response, \JSON_THROW_ON_ERROR), '', 0);
            }
        };

        return new GitHubTaskStore($exec, '/primary', new TaskWorkflowSettings(repository: 'acme/project'));
    }
}
