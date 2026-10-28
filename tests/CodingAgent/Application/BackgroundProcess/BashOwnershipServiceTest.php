<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\BackgroundProcess;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashOwnershipService;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashOwnershipStatusEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationService;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationStatusEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashOwnershipRequestDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessDTO;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class BashOwnershipServiceTest extends IsolatedKernelTestCase
{
    public function testAcceptanceWinsOverRetirementEvenWhenItsReplyWasLost(): void
    {
        $owner = self::getContainer()->get(HatfieldSessionStore::class)->createSession();
        $request = $this->registration($owner);
        $registration = self::getContainer()->get(BashRegistrationService::class);
        $result = $registration->register($request);
        $service = $this->service();
        $ownership = new BashOwnershipRequestDTO($request->identity, $owner);
        $this->assertSame(BashOwnershipStatusEnum::Accepted, $service->accept($ownership));
        // No acceptance response is needed to classify the subsequent worker exit.
        $this->assertSame(BashOwnershipStatusEnum::Accepted, $service->retire($ownership));
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $row = $connection->fetchAssociative('SELECT * FROM background_process WHERE id = ?', [$result->recordId]);
        $this->assertNotFalse($row);
        $this->assertNotNull($row['backgrounded_at']);
        $this->assertSame(0, (int) $row['stopped_by_user']);
        $this->assertNull($row['completion_notified_at']);
        self::getContainer()->get(ProcessStore::class)->deleteById($result->recordId);
        $this->assertSame(BashOwnershipStatusEnum::Accepted, $service->retire($ownership));
        $this->assertSame($result->recordId, $registration->register($request)->recordId);
        $this->assertFalse($registration->register($request)->processExists);
    }

    public function testRetirementBeforeRegistrationRefusesDelayedLaunchMetadata(): void
    {
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $owner = $sessions->createSession();
        $request = $this->registration($owner);
        $service = $this->service();
        $ownership = new BashOwnershipRequestDTO($request->identity, $owner);
        $this->assertSame(BashOwnershipStatusEnum::RegistrationMissing, $service->accept($ownership));
        $this->assertSame(BashOwnershipStatusEnum::Retired, $service->retire($ownership));
        $this->assertSame(BashOwnershipStatusEnum::Retired, $service->accept($ownership));
        $this->assertSame(BashRegistrationStatusEnum::Retired, self::getContainer()->get(BashRegistrationService::class)->register($request)->status);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM background_process'));
        $sessions->deleteSession($owner);
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM bash_launch_ownership'));
        $this->assertSame(BashOwnershipStatusEnum::SessionMissing, $service->retire($ownership));
    }

    public function testRetirementAfterRegistrationPreservesReceiptButRefusesAcceptance(): void
    {
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $owner = $sessions->createSession();
        $request = $this->registration($owner);
        $registration = self::getContainer()->get(BashRegistrationService::class);
        $first = $registration->register($request);
        $service = $this->service();
        $ownership = new BashOwnershipRequestDTO($request->identity, $owner);
        $this->assertSame(BashOwnershipStatusEnum::Retired, $service->retire($ownership));
        $this->assertSame(BashOwnershipStatusEnum::Retired, $service->accept($ownership));
        $this->assertSame($first->recordId, $registration->register($request)->recordId);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $row = $connection->fetchAssociative('SELECT * FROM background_process WHERE id = ?', [$first->recordId]);
        $this->assertNotFalse($row);
        $this->assertNull($row['backgrounded_at']);
        $this->assertSame(0, (int) $row['stopped_by_user']);
        $this->assertNull($row['completion_notified_at']);
        $otherOwner = $sessions->createSession();
        $this->assertSame(BashOwnershipStatusEnum::InputConflict, $service->retire(new BashOwnershipRequestDTO($request->identity, $otherOwner)));
        $this->assertSame(BashOwnershipStatusEnum::InvalidInput, $service->retire(new BashOwnershipRequestDTO('', $owner)));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCompletionOrStopCommittedBeforeAcceptanceStaysForeground(bool $stopped): void
    {
        $owner = self::getContainer()->get(HatfieldSessionStore::class)->createSession();
        $request = $this->registration($owner);
        $result = self::getContainer()->get(BashRegistrationService::class)->register($request);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        if ($stopped) {
            $connection->executeStatement('UPDATE background_process SET stopped_by_user = 1 WHERE id = ?', [$result->recordId]);
        } else {
            $connection->executeStatement("UPDATE background_process SET finished_at = '2026-10-28 00:00:01' WHERE id = ?", [$result->recordId]);
        }
        $ownership = new BashOwnershipRequestDTO($request->identity, $owner);
        $this->assertSame(BashOwnershipStatusEnum::ProcessFinished, $this->service()->accept($ownership));
        $this->assertNull($connection->fetchOne('SELECT backgrounded_at FROM background_process WHERE id = ?', [$result->recordId]));
        $this->assertSame(BashOwnershipStatusEnum::Retired, $this->service()->retire($ownership));
    }

    private function service(): BashOwnershipService
    {
        return new BashOwnershipService(self::getContainer()->get(EntityManagerInterface::class), self::getContainer()->get(RunLockManager::class), self::getContainer()->get(ValidatorInterface::class));
    }

    private function registration(string $owner): RegisterBashProcessDTO
    {
        return new RegisterBashProcessDTO(str_repeat('b', 32), $owner, $owner, 2147483620, 2147483620, 'not launched', 'ownership', null, false, '/not-launched/ownership.log', '/not-launched/ownership.status', new \DateTimeImmutable('2026-10-28T00:00:00+00:00'));
    }
}
