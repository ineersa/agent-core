<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CodeModeReportedReturn> */
final class CodeModeReportedReturnRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CodeModeReportedReturn::class);
    }

    /** @param array<string, mixed> $reply */
    public function record(string $id, array $reply): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO code_mode_reported_return (invocationId, reply) VALUES (?, ?) ON CONFLICT (invocationId) DO NOTHING',
            [$id, $reply],
            [ParameterType::STRING, 'json'],
        );
    }

    /** @return array<string, mixed>|null */
    public function reply(string $id): ?array
    {
        return $this->find($id)?->reply;
    }

    public function remove(string $id): void
    {
        $this->getEntityManager()->getConnection()->delete('code_mode_reported_return', ['invocationId' => $id]);
    }
}
