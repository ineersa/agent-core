<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ActiveRunContextTest extends PerMethodIsolatedKernelTestCase
{
    public function testKnownReservedRunIsNotImplicitlyQueuedByLookup(): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('registry');
        $context = self::getContainer()->get(ActiveRunContextInterface::class);
        $this->expectException(RunContextNotLoadedException::class);
        $context->requireLoaded($run);
    }

    public function testExplicitCreationAndRecoveryRemainDistinctAndLookupIsMemoryOnly(): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('registry');
        $context = self::getContainer()->get(ActiveRunContextInterface::class);
        $created = $context->createNew($run);
        $this->assertSame(RunStatus::Queued, $created->status);
        $this->assertSame($created, $context->requireLoaded($run));
        $context->release($run);
        $recovered = new RunState($run, RunStatus::Completed, lastSeq: 4);
        $context->loadRecovered($recovered);
        $this->assertSame($recovered, $context->requireLoaded($run));
        $this->assertSame($recovered, $context->requireLoaded($run));
        $repository = self::getContainer()->get(RunOperationalProjectionRepository::class);
        $this->assertSame(RunStatus::Completed, $repository->findOperationalStatus($run)?->status);
    }

    public function testUnreservedCreationIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unreserved run');
        self::getContainer()->get(ActiveRunContextInterface::class)->createNew('unknown-registry-run');
    }

    public function testPublicationFailureLeavesRecoveryRequiredInsteadOfOldStateOrQueuedState(): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('registry');
        $context = self::getContainer()->get(ActiveRunContextInterface::class);
        $context->createNew($run);
        try {
            $context->replaceCurrent(new RunState($run, RunStatus::Completed, activeStepId: str_repeat('x', 256)));
            $this->fail('Invalid projection must fail publication.');
        } catch (ValidationFailedException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->expectException(RunContextNotLoadedException::class);
        $context->requireLoaded($run);
    }
}
