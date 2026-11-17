<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Goal;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\HatfieldSessionRepository;
use Ineersa\CodingAgent\Session\Goal\Exception\GoalAccessException;
use Ineersa\CodingAgent\Session\Goal\SessionGoalService;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class SessionGoalServiceTest extends IsolatedKernelTestCase
{
    public function testPersistReplaceClearAndFreshReadsDespiteManagedEntity(): void
    {
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $goals = self::getContainer()->get(SessionGoalService::class);
        $id = $store->createSession('ordinary prompt');
        $entity = $store->findSession($id);
        $this->assertNull($entity->goal);
        $this->assertNull($goals->get($id));
        $this->assertSame('Fix the migration', $goals->set($id, "  Fix the migration\n"));
        $this->assertSame('Fix the migration', $goals->get($id));
        $this->assertSame('Updated objective', $goals->set($id, 'Updated objective'));
        // A different process can replace metadata without hydrating this identity map.
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('UPDATE hatfield_session SET goal = ? WHERE id = ?', ['External update', $id]);
        $this->assertSame('External update', $goals->get($id));
        $this->assertNull($goals->set($id, null));
        $this->assertNull($goals->get($id));
        $this->assertTrue($store->exists($id));
        $this->assertSame('ordinary prompt', $entity->prompt);
    }

    public function testDraftCreationPersistsGoalAndNewSessionsDoNotInheritIt(): void
    {
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $goals = self::getContainer()->get(SessionGoalService::class);
        $parent = $store->createSession('goal prompt', 'Ship the feature');
        $this->assertSame('Ship the feature', $goals->get($parent));
        $child = $store->createSession('delegated work');
        $store->updateMetadata($child, ['parent_id' => $parent]);
        $this->assertNull(self::getContainer()->get(HatfieldSessionRepository::class)->findGoal($child));
        $this->expectException(GoalAccessException::class);
        $goals->set($child, 'Cannot control goals');
    }

    public function testNativeChildrenAndUnknownSessionsCannotReadOrWriteGoals(): void
    {
        $goals = self::getContainer()->get(SessionGoalService::class);
        foreach (['', 'native-child-uuid', '9999999999999999999999999999'] as $id) {
            foreach (['get', 'set'] as $operation) {
                try {
                    'get' === $operation ? $goals->get($id) : $goals->set($id, 'Unauthorized');
                    $this->fail('Unknown and native child runs must not own a goal.');
                } catch (GoalAccessException $exception) {
                    $this->assertStringContainsString('main session', $exception->getMessage());
                }
            }
        }
    }

    public function testValidationIsUnicodeAwareAndDoesNotOverwriteExistingGoal(): void
    {
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $goals = self::getContainer()->get(SessionGoalService::class);
        $id = $store->createSession('', 'Existing');
        $this->assertSame(str_repeat('界', 4000), $goals->validateObjective(str_repeat('界', 4000)));
        foreach (['   ', str_repeat('界', 4001), "invalid\xFF"] as $invalid) {
            try {
                $goals->set($id, $invalid);
                $this->fail('Invalid goal must be refused.');
            } catch (ValidationFailedException $exception) {
                $this->assertGreaterThan(0, $exception->getViolations()->count());
            }
            $this->assertSame('Existing', $goals->get($id));
        }
    }
}
