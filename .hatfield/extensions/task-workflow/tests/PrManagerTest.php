<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\HatfieldExt\TaskWorkflow\Exception\TaskTransitionException;
use Ineersa\HatfieldExt\TaskWorkflow\Pr\PrManager;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\InvocationControl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class PrManagerTest extends TestCase
{
    #[DataProvider('linkAttempts')]
    public function testCreateWaitsOnlyUntilTheExactIssueIsLinked(int $unlinkedReads, int $waitSeconds): void
    {
        $clock = new MockClock('2026-01-01T00:00:00Z');
        $start = $clock->now();
        $calls = [];
        $manager = $this->manager([
            new ExecResultDTO('https://github.com/acme/project/pull/9', '', 0),
            ...array_fill(0, $unlinkedReads, $this->response(['closingIssuesReferences' => [['number' => 8]]])),
            $this->response(),
        ], $calls, $clock);

        $pr = $manager->create(7, 'task/7', 'release', 'Title', 'Body');

        $manager->assertIdentity($pr, 'task/7', 'release', 7);
        $this->assertCount($unlinkedReads + 2, $calls);
        $this->assertEquals($start->modify('+'.$waitSeconds.' seconds'), $clock->now());
        $this->assertFileDoesNotExist($calls[0][array_search('--body-file', $calls[0], true) + 1]);
    }

    public static function linkAttempts(): iterable
    {
        yield 'already linked: no wait' => [0, 0];
        yield 'linked after first wait' => [1, 1];
        yield 'linked on final attempt' => [4, 15];
    }

    public function testCreateReportsTheCreatedPrWhenLinkNeverAppears(): void
    {
        $clock = new MockClock('2026-01-01T00:00:00Z');
        $start = $clock->now();
        $calls = [];
        $manager = $this->manager([
            new ExecResultDTO('https://github.com/acme/project/pull/9', '', 0),
            ...array_fill(0, 5, $this->response(['closingIssuesReferences' => []])),
        ], $calls, $clock);

        try {
            $manager->create(7, 'task/7', 'release', 'Title', 'Body');
            $this->fail('Expected a bounded linking refusal');
        } catch (TaskTransitionException $exception) {
            $this->assertSame('PR #9 created but GitHub has not linked it to issue #7 yet; retry with pr: 9.', $exception->getMessage());
        }

        $this->assertCount(6, $calls);
        $this->assertEquals($start->modify('+15 seconds'), $clock->now());
        $this->assertFileDoesNotExist($calls[0][array_search('--body-file', $calls[0], true) + 1]);
    }

    public function testCancellationDuringBackoffStopsBeforeTheNextView(): void
    {
        $clock = new MockClock('2026-01-01T00:00:00Z');
        $cancelAt = $clock->now()->modify('+300 milliseconds');
        $token = new class($clock, $cancelAt) implements ToolCancellationTokenInterface {
            public function __construct(private ClockInterface $clock, private \DateTimeImmutable $cancelAt)
            {
            }

            public function isCancellationRequested(): bool
            {
                return $this->clock->now() >= $this->cancelAt;
            }
        };
        $calls = [];
        $manager = $this->manager([
            new ExecResultDTO('https://github.com/acme/project/pull/9', '', 0),
            $this->response(['closingIssuesReferences' => []]),
        ], $calls, $clock);

        try {
            $manager->create(7, 'task/7', 'release', 'Title', 'Body', new InvocationControl($token, null, null));
            $this->fail('Expected cancellation during the wait');
        } catch (TaskTransitionException $exception) {
            $this->assertStringContainsString('Interrupted while waiting', $exception->getMessage());
        }

        $this->assertCount(2, $calls);
        $this->assertEquals($cancelAt, $clock->now());
        $this->assertFileDoesNotExist($calls[0][array_search('--body-file', $calls[0], true) + 1]);
    }

    public function testFindStillRejectsUnlinkedPrWithoutPolling(): void
    {
        $calls = [];
        $manager = $this->manager([[[['number' => 9]]], $this->response(['closingIssuesReferences' => []])], $calls);

        $this->expectException(TaskTransitionException::class);
        $this->expectExceptionMessage('PR does not match the exact issue, head branch and base branch.');
        $manager->find('task/7', 'release', 7);
    }

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

    private function manager(array $responses, array &$calls, ?ClockInterface $clock = null): PrManager
    {
        return new PrManager(new StubExec(static function (string $command, array $args, ?ExecOptionsDTO $options) use (&$responses, &$calls): ExecResultDTO {
            self::assertSame('gh', $command);
            self::assertSame('1', $options->env['GH_PROMPT_DISABLED']);
            $calls[] = $args;
            $response = array_shift($responses) ?? throw new \LogicException('Unexpected gh call');

            return $response instanceof ExecResultDTO ? $response : new ExecResultDTO(json_encode($response, \JSON_THROW_ON_ERROR), '', 0);
        }), '/primary', new TaskWorkflowSettings(repository: 'acme/project', mergeMethod: 'squash'), $clock ?? new MockClock());
    }
}
