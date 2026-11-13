<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exception\TaskTransitionException;
use Ineersa\HatfieldExt\TaskWorkflow\Pr\PrManager;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use PHPUnit\Framework\TestCase;

final class PrManagerTest extends TestCase
{
    public function testExactIssueHeadAndActualBaseSelection(): void
    {
        $calls = [];
        $manager = $this->manager([[[['number' => 9]]], $this->response()], $calls);
        $matches = $manager->find('task/7', 'release', 7);
        $this->assertCount(1, $matches);
        $this->assertContains('repos/acme/project/pulls?state=all&head=acme%3Atask%2F7&base=release&per_page=100', $calls[0]);
        $this->assertContains('--paginate', $calls[0]);
        $this->assertContains('--slurp', $calls[0]);
        foreach ([['other', 'release', 7], ['task/7', 'main', 7], ['task/7', 'release', 8]] as [$head, $base, $issue]) {
            try {
                $manager->assertIdentity($matches[0], $head, $base, $issue);
                $this->fail('Expected exact identity refusal');
            } catch (TaskTransitionException $exception) {
                $this->assertStringContainsString('exact', $exception->getMessage());
            }
        }
    }

    public function testRepositoryAndForkHeadsAreNotAccepted(): void
    {
        foreach ([['url' => 'https://github.com/other/project/pull/9'], ['headRepository' => ['nameWithOwner' => 'fork/project']]] as $change) {
            $calls = [];
            try {
                $this->manager([$this->response($change)], $calls)->view(9);
                $this->fail('Expected repository refusal');
            } catch (TaskTransitionException $exception) {
                $this->assertStringContainsString('repository', $exception->getMessage());
            }
        }
    }

    public function testFailedQueryIsNotAnEmptyLookup(): void
    {
        $calls = [];
        $this->assertSame([], $this->manager([[]], $calls)->find('task/7', 'release', 7));
        $this->expectException(TaskTransitionException::class);
        $this->manager([new ExecResultDTO('', 'unavailable', 1)], $calls)->find('task/7', 'release', 7);
    }

    public function testMergeUsesAcceptedHeadWithoutBypassingProtections(): void
    {
        $calls = [];
        $manager = $this->manager([$this->response(), [], $this->response(['state' => 'MERGED', 'mergedAt' => 'timestamp'])], $calls);
        $pr = $manager->view(9);
        $this->assertSame('MERGED', $manager->merge($pr, 'accepted-sha')->state);
        $this->assertSame(['pr', 'merge', '9', '--squash', '--match-head-commit', 'accepted-sha', '--repo', 'acme/project'], $calls[1]);
        $this->assertNotContains('--admin', $calls[1]);
        $this->assertNotContains('--delete-branch', $calls[1]);
        $this->expectException(TaskTransitionException::class);
        $manager->merge($pr, 'different-sha');
    }

    public function testRequiredReviewBlocksMerge(): void
    {
        $calls = [];
        $manager = $this->manager([$this->response(['reviewDecision' => 'CHANGES_REQUESTED'])], $calls);
        $blocked = $manager->view(9);
        $this->expectException(TaskTransitionException::class);
        $manager->merge($blocked, 'accepted-sha');
    }

    private function response(array $changes = []): array
    {
        return array_replace(['number' => 9, 'url' => 'https://github.com/acme/project/pull/9', 'state' => 'OPEN', 'mergedAt' => null, 'headRepository' => ['nameWithOwner' => 'acme/project'], 'headRefName' => 'task/7', 'baseRefName' => 'release', 'headRefOid' => 'accepted-sha', 'reviewDecision' => 'APPROVED', 'closingIssuesReferences' => [['number' => 7]]], $changes);
    }

    private function manager(array $responses, array &$calls): PrManager
    {
        return new PrManager(new StubExec(static function (string $command, array $args, ?ExecOptionsDTO $options) use (&$responses, &$calls): ExecResultDTO {
            self::assertSame('gh', $command);
            self::assertSame('1', $options->env['GH_PROMPT_DISABLED']);
            $calls[] = $args;
            $response = array_shift($responses) ?? throw new \LogicException('Unexpected gh call');

            return $response instanceof ExecResultDTO ? $response : new ExecResultDTO(json_encode($response, \JSON_THROW_ON_ERROR), '', 0);
        }), '/primary', new TaskWorkflowSettings(repository: 'acme/project', mergeMethod: 'squash'));
    }
}
