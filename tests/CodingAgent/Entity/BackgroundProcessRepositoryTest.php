<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Entity;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessRepository;
use Ineersa\CodingAgent\Entity\DeferredToolCompletion;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Tests\Support\SqlLoggingKernel;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class BackgroundProcessRepositoryTest extends IsolatedKernelTestCase
{
    public function testPollingSeeksPendingInvocationsIncludingFencedLaunches(): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $repository = $container->get(BackgroundProcessRepository::class);
        $logger = $container->get('test.sql_logger');

        foreach (['settled', 'pending', 'fenced', 'unlaunched', 'foreign'] as $id) {
            $invocation = new DeferredToolCompletion();
            $invocation->runId = 'foreign' === $id ? 'other' : 'main';
            $invocation->deferredId = $invocation->toolCallId = $id;
            $invocation->toolName = 'code_mode';
            $invocation->status = 'settled' === $id ? 'completed' : 'pending';
            $em->persist($invocation);
            if ('unlaunched' === $id) {
                continue;
            }
            $launch = new BackgroundProcess();
            $launch->sessionId = $invocation->runId;
            $launch->codeModeId = $id;
            $launch->finishedAt = 'fenced' === $id ? new \DateTimeImmutable() : null;
            $em->persist($launch);
        }
        $em->flush();

        $logger->records = [];
        $this->assertEqualsCanonicalizing(['pending', 'fenced'], $repository->pendingCodeModeIds('main'));
        $queries = array_values(array_filter($logger->records, static fn (array $row): bool => str_contains($row['context']['sql'] ?? '', 'SELECT invocation.deferred_id')));
        $this->assertCount(1, $queries);
        $query = $queries[0]['context'];
        $plan = $em->getConnection()->fetchAllAssociative('EXPLAIN QUERY PLAN '.$query['sql'], $query['params'] ?? []);
        $detail = implode("\n", array_column($plan, 'detail'));
        $this->assertStringContainsString('SEARCH invocation USING INDEX idx_deferred_active_tool (tool_name=? AND status=? AND run_id=?)', $detail);
        $this->assertMatchesRegularExpression('/SEARCH process USING (?:COVERING )?INDEX \S+ \(code_mode_id=\?\)/', $detail);
        $this->assertStringNotContainsString('SCAN ', $detail);

        $this->assertSame(['settled', 'pending', 'fenced'], $repository->codeModeIds('main'), 'Cleanup still sees the entire owned process catalog.');
    }

    protected static function createKernel(array $options = []): Kernel
    {
        return new SqlLoggingKernel('test', false);
    }
}
