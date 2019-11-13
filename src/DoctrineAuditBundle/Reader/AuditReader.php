<?php

namespace DH\DoctrineAuditBundle\Reader;

use DH\DoctrineAuditBundle\Annotation\Security;
use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\Exception\AccessDeniedException;
use DH\DoctrineAuditBundle\Exception\InvalidArgumentException;
use DH\DoctrineAuditBundle\User\UserInterface;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata as ORMMetadata;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Pagerfanta\Adapter\DoctrineDbalSingleTableAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\Security\Core\Security as CoreSecurity;

class AuditReader
{
    public const UPDATE = 'update';
    public const ASSOCIATE = 'associate';
    public const DISSOCIATE = 'dissociate';
    public const INSERT = 'insert';
    public const REMOVE = 'remove';

    public const PAGE_SIZE = 50;

    /**
     * @var AuditConfiguration
     */
    private $configuration;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var ?string
     */
    private $filter;

    /**
     * AuditReader constructor.
     *
     * @param AuditConfiguration     $configuration
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(
        AuditConfiguration $configuration,
        EntityManagerInterface $entityManager
    ) {
        $this->configuration = $configuration;
        $this->entityManager = $entityManager;
    }

    /**
     * @return AuditConfiguration
     */
    public function getConfiguration(): AuditConfiguration
    {
        return $this->configuration;
    }

    /**
     * Set the filter for AuditEntry retrieving.
     *
     * @param string $filter
     *
     * @return AuditReader
     */
    public function filterBy(string $filter): self
    {
        if (!\in_array($filter, [self::UPDATE, self::ASSOCIATE, self::DISSOCIATE, self::INSERT, self::REMOVE], true)) {
            $this->filter = null;
        } else {
            $this->filter = $filter;
        }

        return $this;
    }

    /**
     * Returns current filter.
     *
     * @return null|string
     */
    public function getFilter(): ?string
    {
        return $this->filter;
    }

    /**
     * Returns an array of audit table names indexed by entity FQN.
     *
     * @throws \Doctrine\ORM\ORMException
     *
     * @return array
     */
    public function getEntities(): array
    {
        $metadataDriver = $this->entityManager->getConfiguration()->getMetadataDriverImpl();
        $entities = [];
        if (null !== $metadataDriver) {
            $entities = $metadataDriver->getAllClassNames();
        }
        $audited = [];
        foreach ($entities as $entity) {
            if ($this->configuration->isAuditable($entity)) {
                $audited[$entity] = $this->getEntityTableName($entity);
            }
        }
        ksort($audited);

        return $audited;
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param object|string   $entity
     * @param null|int|string $id
     * @param null|int        $page
     * @param null|int        $pageSize
     * @param null|string     $transactionHash
     * @param bool            $strict
     *
     * @throws AccessDeniedException
     * @throws InvalidArgumentException
     *
     * @return array
     */
    public function getAudits($entity, $id = null, ?int $page = null, ?int $pageSize = null, ?string $transactionHash = null, bool $strict = true): array
    {
        $this->checkAuditable($entity);
        $this->checkRoles($entity, Security::VIEW_SCOPE);

        $queryBuilder = $this->getAuditsQueryBuilder($entity, $id, $page, $pageSize, $transactionHash, $strict);

        /** @var Statement $statement */
        $statement = $queryBuilder->execute();
        $statement->setFetchMode(\PDO::FETCH_CLASS, AuditEntry::class);

        return $statement->fetchAll();
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param object|string $entity
     * @param int|string $id
     * @param null|int $page
     * @param null|int $pageSize
     * @param null|string $transactionHash
     * @param bool $strict
     *
     * @return array
     * @throws AccessDeniedException
     * @throws InvalidArgumentException*@throws \Doctrine\DBAL\DBALException
     * @throws DBALException
     *
     */
    public function getAuditsWithAssociations($entity, $id, ?int $page = null, ?int $pageSize = null, ?string $transactionHash = null, bool $strict = true): array
    {
        $this->checkAuditable($entity);
        $this->checkRoles($entity, Security::VIEW_SCOPE);

        $meta = $this->getClassMetadata($entity);

        /** @var QueryBuilder[] $queryBuilders */
        $queryBuilders = [];

        $queryBuilders[] = $this->getAuditsQueryBuilder($entity, $id, null, null, $transactionHash, $strict)->addSelect("'$entity' as class");;

        foreach ($meta->getAssociationNames() as $associationName) {
            $targetClass = $meta->getAssociationTargetClass($associationName);
            if ($this->configuration->isAuditable($targetClass)) {
                $mappedBy = $meta->getAssociationMappedByTargetField($associationName);
                $qb = $this->getAuditsQueryBuilder($targetClass, null, null, null, $transactionHash, $strict);
                $qb->addSelect("'$targetClass' as class");
                $qb
                    ->andWhere("diffs -> '$mappedBy' -> 'old' ->> 'id' = :object_id OR diffs -> '$mappedBy' -> 'new' ->> 'id' = :object_id")
                ;
                $queryBuilders[] = $qb;
            }
        }

        $storage = $this->selectStorage();$connection = $storage->getConnection();
        $sql = $this->unionAllQueryBuilders($queryBuilders);
        if ($page && $pageSize) {
            $offset = $page*$pageSize;
            $sql .= " LIMIT {$pageSize} OFFSET $offset";
        }
        $statement = $connection->executeQuery($sql, ['object_id' => $id]);
        $statement->setFetchMode(\PDO::FETCH_CLASS, AuditEntry::class);

        return $statement->fetchAll();
    }

    /**
     * @param array $queryBuilders
     *
     * @return string
     */
    private function unionAllQueryBuilders(array $queryBuilders)
    {
        $imploded = implode(') UNION ALL (', array_map(function (QueryBuilder $q) {
            return $q->getSQL();
        }, $queryBuilders));
        return '('.$imploded.')';
    }

    /**
     * Returns an array of all audited entries/operations for a given transaction hash
     * indexed by entity FQCN.
     *
     * @param string $transactionHash
     *
     * @throws InvalidArgumentException
     * @throws \Doctrine\ORM\ORMException
     *
     * @return array
     */
    public function getAuditsByTransactionHash(string $transactionHash): array
    {
        $results = [];

        $entities = $this->getEntities();
        foreach ($entities as $entity => $tablename) {
            try {
                $audits = $this->getAudits($entity, null, null, null, $transactionHash);
                if (\count($audits) > 0) {
                    $results[$entity] = $audits;
                }
            } catch (AccessDeniedException $e) {
                // acces denied
            }
        }

        return $results;
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param object|string   $entity
     * @param null|int|string $id
     * @param int             $page
     * @param int             $pageSize
     *
     * @throws AccessDeniedException
     * @throws InvalidArgumentException
     *
     * @return Pagerfanta
     */
    public function getAuditsPager($entity, $id = null, int $page = 1, int $pageSize = self::PAGE_SIZE): Pagerfanta
    {
        $queryBuilder = $this->getAuditsQueryBuilder($entity, $id);

        $adapter = new DoctrineDbalSingleTableAdapter($queryBuilder, 'at.id');

        $pagerfanta = new Pagerfanta($adapter);
        $pagerfanta
            ->setMaxPerPage($pageSize)
            ->setCurrentPage($page)
        ;

        return $pagerfanta;
    }

    /**
     * Returns the amount of audited entries/operations.
     *
     * @param object|string   $entity
     * @param null|int|string $id
     *
     * @throws AccessDeniedException
     * @throws InvalidArgumentException
     *
     * @return int
     */
    public function getAuditsCount($entity, $id = null): int
    {
        $queryBuilder = $this->getAuditsQueryBuilder($entity, $id);

        $result = $queryBuilder
            ->resetQueryPart('select')
            ->resetQueryPart('orderBy')
            ->select('COUNT(id)')
            ->execute()
            ->fetchColumn(0)
        ;

        return false === $result ? 0 : $result;
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param object|string   $entity
     * @param null|int|string $id
     * @param null|int        $page
     * @param null|int        $pageSize
     * @param null|string     $transactionHash
     * @param bool            $strict
     *
     * @throws AccessDeniedException
     * @throws InvalidArgumentException
     *
     * @return QueryBuilder
     */
    private function getAuditsQueryBuilder($entity, $id = null, ?int $page = null, ?int $pageSize = null, ?string $transactionHash = null, bool $strict = true): QueryBuilder
    {
        $this->checkAuditable($entity);
        $this->checkRoles($entity, Security::VIEW_SCOPE);

        if (null !== $page && $page < 1) {
            throw new \InvalidArgumentException('$page must be greater or equal than 1.');
        }

        if (null !== $pageSize && $pageSize < 1) {
            throw new \InvalidArgumentException('$pageSize must be greater or equal than 1.');
        }

        $storage = $this->selectStorage();
        $connection = $storage->getConnection();

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($this->getEntityAuditTableName($entity), 'at')
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
        ;

        $metadata = $this->getClassMetadata($entity);
        if ($strict && $metadata instanceof ORMMetadata && ORMMetadata::INHERITANCE_TYPE_SINGLE_TABLE === $metadata->inheritanceType) {
            $queryBuilder
                ->andWhere('discriminator = :discriminator')
                ->setParameter('discriminator', \is_object($entity) ? \get_class($entity) : $entity)
            ;
        }

        if (null !== $pageSize) {
            $queryBuilder
                ->setFirstResult(($page - 1) * $pageSize)
                ->setMaxResults($pageSize)
            ;
        }

        if (null !== $id) {
            $queryBuilder
                ->andWhere('object_id = :object_id')
                ->setParameter('object_id', $id)
            ;
        }

        if (null !== $this->filter) {
            $queryBuilder
                ->andWhere('type = :filter')
                ->setParameter('filter', $this->filter)
            ;
        }

        if (null !== $transactionHash) {
            $queryBuilder
                ->andWhere('transaction_hash = :transaction_hash')
                ->setParameter('transaction_hash', $transactionHash)
            ;
        }

        return $queryBuilder;
    }

    /**
     * @param object|string $entity
     * @param string        $id
     *
     * @throws AccessDeniedException
     * @throws InvalidArgumentException
     *
     * @return mixed[]
     */
    public function getAudit($entity, $id)
    {
        $this->checkAuditable($entity);
        $this->checkRoles($entity, Security::VIEW_SCOPE);

        $connection = $this->entityManager->getConnection();

        /**
         * @var \Doctrine\DBAL\Query\QueryBuilder
         */
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($this->getEntityAuditTableName($entity))
            ->where('id = :id')
            ->setParameter('id', $id)
        ;

        if (null !== $this->filter) {
            $queryBuilder
                ->andWhere('type = :filter')
                ->setParameter('filter', $this->filter)
            ;
        }

        /** @var Statement $statement */
        $statement = $queryBuilder->execute();
        $statement->setFetchMode(\PDO::FETCH_CLASS, AuditEntry::class);

        return $statement->fetchAll();
    }

    /**
     * @param object|string $entity
     *
     * @return ClassMetadata
     */
    private function getClassMetadata($entity): ClassMetadata
    {
        return $this->entityManager->getClassMetadata($entity);
    }

    /**
     * Returns the table name of $entity.
     *
     * @param object|string $entity
     *
     * @return string
     */
    public function getEntityTableName($entity): string
    {
        return $this->getClassMetadata($entity)->getTableName();
    }

    /**
     * Returns the audit table name for $entity.
     *
     * @param object|string $entity
     *
     * @return string
     */
    public function getEntityAuditTableName($entity): string
    {
        $entityName = \is_string($entity) ? $entity : \get_class($entity);
        $schema = $this->getClassMetadata($entityName)->getSchemaName() ? $this->getClassMetadata($entityName)->getSchemaName().'.' : '';

        return sprintf('%s%s%s%s', $schema, $this->configuration->getTablePrefix(), $this->getEntityTableName($entityName), $this->configuration->getTableSuffix());
    }

    /**
     * @return EntityManagerInterface
     */
    private function selectStorage(): EntityManagerInterface
    {
        return $this->configuration->getEntityManager() ?? $this->entityManager;
    }

    /**
     * @return EntityManagerInterface
     */
    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    /**
     * Throws an InvalidArgumentException if given entity is not auditable.
     *
     * @param object|string $entity
     *
     * @throws InvalidArgumentException
     */
    private function checkAuditable($entity): void
    {
        if (!$this->configuration->isAuditable($entity)) {
            throw new InvalidArgumentException('Entity '.$entity.' is not auditable.');
        }
    }

    /**
     * Throws an AccessDeniedException if user not is granted to access audits for the given entity.
     *
     * @param object|string $entity
     * @param string        $scope
     *
     * @throws AccessDeniedException
     */
    private function checkRoles($entity, string $scope): void
    {
        $userProvider = $this->configuration->getUserProvider();
        $user = null === $userProvider ? null : $userProvider->getUser();
        $security = null === $userProvider ? null : $userProvider->getSecurity();

        if (!($user instanceof UserInterface) || !($security instanceof CoreSecurity)) {
            // If no security defined or no user identified, consider access granted
            return;
        }

        $entities = $this->configuration->getEntities();

        if (!isset($entities[$entity]['roles']) || null === $entities[$entity]['roles']) {
            // If no roles are configured, consider access granted
            return;
        }

        if (!isset($entities[$entity]['roles'][$scope]) || null === $entities[$entity]['roles'][$scope]) {
            // If no roles for the given scope are configured, consider access granted
            return;
        }

        // roles are defined for the give scope
        foreach ($entities[$entity]['roles'][$scope] as $role) {
            if ($security->isGranted($role)) {
                // role granted => access granted
                return;
            }
        }

        // access denied
        throw new AccessDeniedException('You are not allowed to access audits of '.$entity.' entity.');
    }
}
