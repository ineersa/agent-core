<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Symfony\Component\Serializer\SerializerInterface;

/** @extends ServiceEntityRepository<CodeModeReportedReturn> */
final class CodeModeReportedReturnRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly SerializerInterface $serializer)
    {
        parent::__construct($registry, CodeModeReportedReturn::class);
    }

    public function record(string $id, ToolPayloadReferenceDTO $reply): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO code_mode_reported_return (invocationId, replyReference) VALUES (?, ?) ON CONFLICT (invocationId) DO NOTHING',
            [$id, $this->serializer->serialize($reply, 'json')],
        );
    }

    public function reply(string $id): ?ToolPayloadReferenceDTO
    {
        $json = $this->getEntityManager()->getConnection()->fetchOne('SELECT replyReference FROM code_mode_reported_return WHERE invocationId = ?', [$id]);

        return \is_string($json) ? $this->serializer->deserialize($json, ToolPayloadReferenceDTO::class, 'json') : null;
    }

    public function remove(string $id): void
    {
        $this->getEntityManager()->getConnection()->delete('code_mode_reported_return', ['invocationId' => $id]);
    }
}
