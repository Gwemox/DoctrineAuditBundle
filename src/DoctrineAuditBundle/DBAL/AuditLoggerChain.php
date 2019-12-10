<?php

namespace DH\DoctrineAuditBundle\DBAL;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Logging\SQLLogger;

class AuditLoggerChain implements SQLLogger
{
    /** @var ArrayCollection */
    private $loggers;

    public function __construct()
    {
        $this->loggers = new ArrayCollection();
    }

    /**
     * Remove a logger in the chain.
     *
     * @param SQLLogger $logger
     * @return bool TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeLogger(SQLLogger $logger): bool
    {
        return $this->loggers->removeElement($logger);
    }

    /**
     * Adds a logger in the chain.
     *
     * @param SQLLogger $logger
     */
    public function addLogger(SQLLogger $logger): void
    {
        $this->loggers->add($logger);
    }

    /**
     * {@inheritdoc}
     */
    public function startQuery($sql, ?array $params = null, ?array $types = null): void
    {
        foreach ($this->loggers as $logger) {
            $logger->startQuery($sql, $params, $types);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stopQuery(): void
    {
        foreach ($this->loggers as $logger) {
            $logger->stopQuery();
        }
    }

    /**
     * @return ArrayCollection
     */
    public function getLoggers()
    {
        return $this->loggers;
    }
}
