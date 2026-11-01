<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\Pipeline;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Application\Pipeline\BackgroundCompletionHandler;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use PHPUnit\Framework\Attributes\TestWith;

final class BackgroundCompletionHandlerTest extends IsolatedKernelTestCase
{
    public function testReceiptAcknowledgesProducerAndDeduplicatesRedelivery(): void
    {
        [$message, $record] = $this->completion();
        $handler = self::getContainer()->get(BackgroundCompletionHandler::class);
        $handler($message);
        $receipts = self::getContainer()->get(BackgroundCompletionReceiptRepository::class);
        $this->assertNotNull($receipts->receipt($message->identity));
        $this->assertSame($message->origin, $receipts->receipt($message->identity)->origin);
        $store = self::getContainer()->get(ProcessStore::class);
        $this->assertNotNull($store->snapshot($record->id)?->completionNotifiedAt);
        $commands = self::getContainer()->get(CommandStoreInterface::class);
        $before = $commands->pending($message->runId());
        $this->assertCount(1, $before);
        $handler($message);
        $this->assertCount(1, $commands->pending($message->runId()));
    }

    public function testReceiptSurvivesProducerAcknowledgementFailure(): void
    {
        [$message, $record] = $this->completion();
        $handler = self::getContainer()->get(BackgroundCompletionHandler::class);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("CREATE TEMP TRIGGER refuse_bash_ack BEFORE UPDATE OF completion_notified_at ON background_process BEGIN SELECT RAISE(ABORT, 'test acknowledgement refusal'); END");
        try {
            try {
                $handler($message);
                $this->fail('The acknowledgement write must fail after retained admission.');
            } catch (Exception $exception) {
                $this->assertStringContainsString('test acknowledgement refusal', $exception->getMessage());
            }
            $this->assertNotNull(self::getContainer()->get(BackgroundCompletionReceiptRepository::class)->receipt($message->identity));
            $this->assertNull(self::getContainer()->get(ProcessStore::class)->snapshot($record->id)?->completionNotifiedAt);
        } finally {
            $connection->executeStatement('DROP TRIGGER refuse_bash_ack');
        }
        $handler($message);
        $this->assertNotNull(self::getContainer()->get(ProcessStore::class)->snapshot($record->id)?->completionNotifiedAt);
        $this->assertCount(1, self::getContainer()->get(CommandStoreInterface::class)->pending($message->runId()));
    }

    #[TestWith(['completion_notified_at', '2026-10-28 00:00:00'])]
    #[TestWith(['backgrounded_at', null])]
    #[TestWith(['finished_at', null])]
    public function testQueuedCandidateCannotOverrideCurrentAuthority(string $column, int|string|null $value): void
    {
        [$message, $record] = $this->completion();
        // The producer already constructed its message. An independent owner
        // decision wins before consumption, without refreshing its old snapshot.
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->update('background_process', [$column => $value], ['id' => $record->id]);
        self::getContainer()->get(BackgroundCompletionHandler::class)($message);
        $this->assertNull(self::getContainer()->get(BackgroundCompletionReceiptRepository::class)->receipt($message->identity));
        $this->assertCount(0, self::getContainer()->get(CommandStoreInterface::class)->pending($message->runId()));
    }

    public function testExplicitStopDoesNotSuppressObservedCompletion(): void
    {
        [$message, $record] = $this->completion();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->update(
            'background_process',
            ['stopped_by_user' => 1, 'status' => BackgroundProcessStatusEnum::Stopped->value],
            ['id' => $record->id],
        );
        self::getContainer()->get(BackgroundCompletionHandler::class)($message);
        $this->assertNotNull(self::getContainer()->get(BackgroundCompletionReceiptRepository::class)->receipt($message->identity));
        $this->assertNotNull(self::getContainer()->get(ProcessStore::class)->snapshot($record->id)?->completionNotifiedAt);
        $this->assertCount(1, self::getContainer()->get(CommandStoreInterface::class)->pending($message->runId()));
    }

    /** @return array{DeliverBackgroundCompletionDTO, BackgroundProcess} */
    private function completion(): array
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('Bash completion owner');
        $event = PreparedEventStoreSeeder::append(self::getContainer()->get(PreparedTransitionEventStoreInterface::class), RunEvent::forAppend($runId, 0, 'run_started', ['payload' => ['messages' => []]]));
        self::getContainer()->get(ActiveRunContextInterface::class)->loadRecovered(self::getContainer()->get(RunStateReducer::class)->replay(RunState::queued($runId), [$event]));
        $record = new BackgroundProcess();
        $record->pid = 991234;
        $record->sessionId = $runId;
        $record->command = 'echo completed';
        $record->backgroundedAt = new \DateTimeImmutable();
        $record->finishedAt = new \DateTimeImmutable();
        $record->exitCode = 0;
        $record->status = BackgroundProcessStatusEnum::Finished;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($record);
        $em->flush();

        // Forwarding and recovery use the seventh positional origin parameter.
        // Native owner admission must preserve it alongside its process record.
        return [new DeliverBackgroundCompletionDTO($runId, 'background-process-'.$record->id, 'Bash completed', null, null, null, ['kind' => 'bash', 'process_id' => $record->id], processRecordId: $record->id, outputTail: 'completed'), $record];
    }
}
