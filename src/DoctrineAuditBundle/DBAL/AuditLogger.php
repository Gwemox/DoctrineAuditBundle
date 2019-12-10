<?php

namespace DH\DoctrineAuditBundle\DBAL;

use Doctrine\DBAL\Logging\SQLLogger;

class AuditLogger implements SQLLogger
{
    /**
     * @var callable
     */
    private $flusher;

    public function __construct(?callable $flusher = null)
    {
        $this->setFlusher($flusher);
    }

    /**
     * Set the flusher closure
     * @param callable|null $flusher
     */
    public function setFlusher(?callable $flusher)
    {
        $this->flusher = $flusher;
    }

    /**
     * {@inheritdoc}
     */
    public function startQuery($sql, ?array $params = null, ?array $types = null): void
    {
        // right before commit insert all audit entries
        if ($this->flusher !== null && '"COMMIT"' === $sql) {
            \call_user_func($this->flusher);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stopQuery(): void
    {
    }
}
