<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\BackgroundProcess;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationService;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationStatusEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessDTO;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class BashRegistrationServiceTest extends IsolatedKernelTestCase
{
    public function testRegistrationReceiptSurvivesProcessDeletionButNotOwningSessionDeletion(): void
    {
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $owner = $sessions->createSession();
        $request = $this->request($owner);
        $service = $this->service();
        $first = $service->register($request);
        $this->assertSame(BashRegistrationStatusEnum::Registered, $first->status);
        $this->assertTrue($first->processExists);
        $store = self::getContainer()->get(ProcessStore::class);
        $this->assertTrue($store->deleteById($first->recordId));
        $replayed = $service->register($request);
        $this->assertSame($first->recordId, $replayed->recordId);
        $this->assertSame($first->pid, $replayed->pid);
        $this->assertSame($first->logPath, $replayed->logPath);
        $this->assertFalse($replayed->processExists);
        $this->assertNull($store->fetchByRecordId($first->recordId), 'Historical success must not recreate the process row.');
        $sessions->deleteSession($owner);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM bash_registration_receipt WHERE identity = ?', [$request->identity]));
        $this->assertSame(BashRegistrationStatusEnum::SessionMissing, $service->register($request)->status);
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM bash_registration_receipt WHERE identity = ?', [$request->identity]));
    }

    public function testConflictingImmutableInputsAreRefusedAndInvalidInputDoesNotWrite(): void
    {
        $owner = self::getContainer()->get(HatfieldSessionStore::class)->createSession();
        $service = $this->service();
        $original = $service->register($this->request($owner));
        $this->assertSame(BashRegistrationStatusEnum::Registered, $original->status);
        $this->assertSame(BashRegistrationStatusEnum::InputConflict, $service->register($this->request($owner, 'different'))->status);
        $invalid = $service->register($this->request('invalid'));
        $this->assertSame(BashRegistrationStatusEnum::InvalidInput, $invalid->status);
        $this->assertSame(['ownerSessionId'], $invalid->invalidFields);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM bash_registration_receipt'));
    }

    private function service(): BashRegistrationService
    {
        $container = self::getContainer();

        return new BashRegistrationService($container->get(EntityManagerInterface::class), $container->get(ProcessStore::class), $container->get(RunLockManager::class), $container->get(SerializerInterface::class), $container->get(ValidatorInterface::class));
    }

    private function request(string $owner, string $command = 'printf registration'): RegisterBashProcessDTO
    {
        return new RegisterBashProcessDTO(
            str_repeat('a', 32), $owner, $owner, 2147483620, 2147483620, $command,
            'registration-call', null, false, '/not-launched/registration.log', '/not-launched/registration.status',
            new \DateTimeImmutable('2026-10-27T10:00:00.123456+00:00'),
        );
    }
}
