<?php

declare(strict_types=1);

namespace Ordinal\Database;

use Closure;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Commits successful database operations and rolls back failed operations.
 */
final class Transaction
{
    /**
     * Uses the supplied PDO connection for transaction boundaries.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Provides the database connection used by these operations. */
        private readonly PDO $connection,
    ) {}

    /**
     * Runs an operation in a new transaction; nesting is rejected and failures propagate.
     *
     * @param Closure $operation
     * @return void
     * @throws \Throwable
     */
    public function execute(Closure $operation): void
    {
        if ($this->connection->inTransaction()) {
            throw new LogicException('Nested transactions are not supported.');
        }

        if (!$this->connection->beginTransaction()) {
            throw new RuntimeException('Database transaction could not begin.');
        }

        try {
            $operation($this->connection);

            if (!$this->connection->commit()) {
                throw new RuntimeException('Database transaction could not commit.');
            }
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}
