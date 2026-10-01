<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Database;

use LogicException;
use Ordinal\Database\Transaction;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies the behavior of Transaction.
 */
final class TransactionTest extends TestCase
{
    /**
     * Verifies: commits successful operations.
     *
     * @return void
     */
    public function testCommitsSuccessfulOperations(): void
    {
        $connection = $this->createMock(PDO::class);
        $connection->method('inTransaction')->willReturn(false);
        $connection->expects(self::once())->method('beginTransaction')->willReturn(true);
        $connection->expects(self::once())->method('commit')->willReturn(true);
        $connection->expects(self::never())->method('rollBack');
        $wasCalled = false;

        (new Transaction($connection))->execute(
            /**
             * Runs the database operation within the transaction.
             *
             * @param PDO $transactionConnection
             * @return void
             */
            function (PDO $transactionConnection) use ($connection, &$wasCalled): void {
                self::assertSame($connection, $transactionConnection);
                $wasCalled = true;
            }
        );

        self::assertTrue($wasCalled);
    }

    /**
     * Verifies: rolls back and preserves the original failure.
     *
     * @return void
     */
    public function testRollsBackAndPreservesTheOriginalFailure(): void
    {
        $connection = $this->createMock(PDO::class);
        $connection->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $connection->expects(self::once())->method('beginTransaction')->willReturn(true);
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::once())->method('rollBack')->willReturn(true);
        $failure = new RuntimeException('Operation failed.');

        try {
            (new Transaction($connection))->execute(
                /**
                 * Runs the database operation within the transaction.
                 *
                 * @param PDO $transactionConnection
                 * @return void
                 */
                function (PDO $transactionConnection) use ($failure): void {
                    throw $failure;
                }
            );
            self::fail('Expected the operation failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    /**
     * Verifies: rejects nested transactions.
     *
     * @return void
     */
    public function testRejectsNestedTransactions(): void
    {
        $connection = $this->createMock(PDO::class);
        $connection->method('inTransaction')->willReturn(true);
        $connection->expects(self::never())->method('beginTransaction');

        $this->expectException(LogicException::class);
        (new Transaction($connection))->execute(
            /**
             * Runs the database operation within the transaction.
             *
             * @param PDO $transactionConnection
             * @return void
             */
            function (PDO $transactionConnection): void {
                self::fail('Nested operation must not run.');
            }
        );
    }

    /**
     * Verifies: does not run the operation when beginning fails.
     *
     * @return void
     */
    public function testDoesNotRunTheOperationWhenBeginningFails(): void
    {
        $connection = $this->createMock(PDO::class);
        $connection->method('inTransaction')->willReturn(false);
        $connection->expects(self::once())->method('beginTransaction')->willReturn(false);

        $this->expectException(RuntimeException::class);
        (new Transaction($connection))->execute(
            /**
             * Runs the database operation within the transaction.
             *
             * @param PDO $transactionConnection
             * @return void
             */
            function (PDO $transactionConnection): void {
                self::fail('Operation must not run.');
            }
        );
    }

    /**
     * Verifies: rolls back when commit fails.
     *
     * @return void
     */
    public function testRollsBackWhenCommitFails(): void
    {
        $connection = $this->createMock(PDO::class);
        $connection->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $connection->expects(self::once())->method('beginTransaction')->willReturn(true);
        $connection->expects(self::once())->method('commit')->willReturn(false);
        $connection->expects(self::once())->method('rollBack')->willReturn(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database transaction could not commit.');
        (new Transaction($connection))->execute(
            /**
             * Runs the database operation within the transaction.
             *
             * @param PDO $transactionConnection
             * @return void
             */
            function (PDO $transactionConnection): void {}
        );
    }
}
