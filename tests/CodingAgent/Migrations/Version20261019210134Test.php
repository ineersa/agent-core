<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Migrations;

use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261019210134;
use Ineersa\CodingAgent\Entity\ToolQuestion;
use Ineersa\CodingAgent\Entity\ToolQuestionStatusEnum;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;

final class Version20261019210134Test extends IsolatedKernelTestCase
{
    public function testGeneratedMigrationPreservesAllQuestionFieldsInBothDirections(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach (ToolQuestionStatusEnum::cases() as $status) {
            $question = ToolQuestion::create($status->value, 'migration-run', 'call-'.$status->value, 'bash', 123, '/tmp/question.log', 'echo test', 'Continue?');
            $question->status = $status;
            $question->schema = '{"type":"boolean"}';
            $question->answer = ToolQuestionStatusEnum::Answered === $status ? true : null;
            $question->emittedAt = new \DateTimeImmutable('2026-01-02T03:04:05+00:00');
            $question->answeredAt = ToolQuestionStatusEnum::Pending === $status ? null : new \DateTimeImmutable('2026-01-02T03:04:06+00:00');
            $em->persist($question);
        }
        $em->flush();
        $em->clear();
        $db = $em->getConnection();
        $before = $db->fetchAllAssociative('SELECT * FROM tool_question ORDER BY id');
        foreach (['down', 'up'] as $direction) {
            $migration = new Version20261019210134($db, new NullLogger());
            $migration->{$direction}($db->createSchemaManager()->introspectSchema());
            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            $after = $db->fetchAllAssociative('SELECT * FROM tool_question ORDER BY id');
            $this->assertEquals($before, $after, 'Generated table rebuild must preserve every retained field and identity.');
            $indexes = $db->createSchemaManager()->listTableIndexes('tool_question');
            $this->assertSame('up' === $direction, isset($indexes['idx_tool_question_unemitted']));
            $this->assertTrue($indexes['uniq_tool_question_request_id']->isUnique());
            $this->assertSame(['request_id'], $indexes['uniq_tool_question_request_id']->getColumns());
        }
    }
}
