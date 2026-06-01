<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * Doctrine repository for HatfieldSession entities.
 *
 * Extends EntityRepository for compatibility with both
 * EntityManager::getRepository() and container autowiring.
 * EntityManager passes EntityManagerInterface; the container
 * passes ManagerRegistry via a service definition that
 * adapts to EntityManagerInterface.
 *
 * In production, DoctrineBundle auto-registers this repository
 * via #[ORM\Entity(repositoryClass: ...)]. The DefaultRepositoryFactory
 * constructs it with the EntityManager.
 *
 * @extends EntityRepository<HatfieldSession>
 *
 * @see HatfieldSession
 */
final class HatfieldSessionRepository extends EntityRepository
{
    /** @param EntityManagerInterface $em */
    public function __construct($em, \Doctrine\ORM\Mapping\ClassMetadata|string|null $class = null)
    {
        parent::__construct($em, $class ?? new \Doctrine\ORM\Mapping\ClassMetadata(HatfieldSession::class));
    }
}
