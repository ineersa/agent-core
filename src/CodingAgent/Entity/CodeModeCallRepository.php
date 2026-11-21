<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\SerializerInterface;

/** @extends ServiceEntityRepository<CodeModeCall> */
final class CodeModeCallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly SerializerInterface $serializer, #[Autowire(service: 'hatfield.bash.sidecar_lock_factory')] private readonly LockFactory $locks)
    {
        parent::__construct($registry, CodeModeCall::class);
    }

    /**
     * @param array<string, mixed>                $request
     * @param callable(): ToolPayloadReferenceDTO $execute
     * @param callable(): ToolPayloadReferenceDTO $unknown
     */
    public function settle(string $invocationId, string $callId, array $request, callable $execute, callable $unknown): ?ToolPayloadReferenceDTO
    {
        $fingerprint = hash('sha256', json_encode($request, \JSON_THROW_ON_ERROR));
        // The same native lock facility used by process sidecars releases on worker exit.
        $lock = $this->locks->createLock('code-mode.call.'.hash('sha256', $invocationId.':'.$callId));
        if (!$lock->acquire()) {
            return null;
        }
        try {
            $db = $this->connection();
            if (1 === $db->executeStatement('INSERT INTO code_mode_call (invocationId, callId, fingerprint) VALUES (?, ?, ?) ON CONFLICT (invocationId, callId) DO NOTHING', [$invocationId, $callId, $fingerprint])) {
                $outcome = $execute();
            } else {
                $row = $db->fetchAssociative('SELECT fingerprint, replyReference FROM code_mode_call WHERE invocationId = ? AND callId = ?', [$invocationId, $callId]);
                if (false === $row || $row['fingerprint'] !== $fingerprint) {
                    throw new ToolCallException('Code-mode call ID was reused for a different request.', retryable: false);
                }
                if (\is_string($row['replyReference'])) {
                    return $this->decode($row['replyReference']);
                }
                $outcome = $unknown();
            }
            $db->executeStatement('UPDATE code_mode_call SET replyReference = ? WHERE invocationId = ? AND callId = ? AND replyReference IS NULL', [$this->serializer->serialize($outcome, 'json'), $invocationId, $callId]);

            return $this->reply($invocationId, $callId);
        } finally {
            $lock->release();
        }
    }

    public function reply(string $invocationId, string $callId): ?ToolPayloadReferenceDTO
    {
        $json = $this->connection()->fetchOne('SELECT replyReference FROM code_mode_call WHERE invocationId = ? AND callId = ?', [$invocationId, $callId]);

        return \is_string($json) ? $this->decode($json) : null;
    }

    public function remove(string $invocationId): void
    {
        $this->connection()->delete('code_mode_call', ['invocationId' => $invocationId]);
    }

    private function connection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    private function decode(string $json): ToolPayloadReferenceDTO
    {
        return $this->serializer->deserialize($json, ToolPayloadReferenceDTO::class, 'json');
    }
}
