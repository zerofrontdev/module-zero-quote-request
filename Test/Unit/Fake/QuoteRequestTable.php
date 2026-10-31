<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Test\Unit\Fake;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface as EventManager;
use PHPUnit\Framework\TestCase;
use Zero\QuoteRequest\Model\Requests;

/**
 * Requests over an in-memory zero_quote_request table: update() applies its
 * WHERE the way the database would (request_id, status, order_increment_id,
 * the expiry condition), so a test sees which rows a claim, a release or a
 * reopen really changes. Every connection call is logged in $dbLog.
 *
 * @mixin TestCase
 */
trait QuoteRequestTable
{
    /** @var array<int, array> request id => row */
    private array $table = [];
    /** @var list<array> connection calls: update (table, bind, where, rows changed), begin, commit, rollBack, fetchOne */
    private array $dbLog = [];
    /** @var list<list<array>> per select(): its builder calls */
    private array $dbSelects = [];

    private function row(int $id, string $status, ?string $orderIncrementId = null, ?string $expiresAt = null): void
    {
        $this->table[$id] = [
            'request_id' => (string) $id,
            'status' => $status,
            'order_increment_id' => $orderIncrementId,
            'expires_at' => $expiresAt,
            'store_id' => '1',
        ];
    }

    private function requests(?EventManager $events = null): Requests
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->dbSelect());
        foreach (['beginTransaction' => 'begin', 'commit' => 'commit', 'rollBack' => 'rollBack'] as $method => $name) {
            $connection->method($method)->willReturnCallback(function () use ($connection, $name) {
                $this->dbLog[] = [$name];

                return $connection;
            });
        }
        $connection->method('fetchOne')->willReturnCallback(function () {
            $this->dbLog[] = ['fetchOne'];
            $where = array_column(array_filter(end($this->dbSelects), static fn ($call) => $call[0] === 'where'), 1);
            foreach ($where as $args) {
                if ($args[0] === 'request_id = ?') {
                    return $this->table[(int) $args[1]]['status'] ?? false;
                }
            }

            return false;
        });
        $connection->method('update')->willReturnCallback(function (string $table, array $bind, $where = '') {
            $changed = 0;
            if ($table === 'zero_quote_request') {
                foreach ($this->table as $id => $row) {
                    if ($this->rowMatches($row, (array) $where)) {
                        $this->table[$id] = $bind + $row;
                        $changed++;
                    }
                }
            }
            $this->dbLog[] = ['update', $table, $bind, $where, $changed];

            return $changed;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new Requests($resource, $events ?? $this->createStub(EventManager::class));
    }

    private function rowMatches(array $row, array $where): bool
    {
        foreach ($where as $condition => $value) {
            $ok = match ($condition) {
                'request_id = ?' => (int) $row['request_id'] === (int) $value,
                'status = ?' => $row['status'] === $value,
                'order_increment_id = ?' => $row['order_increment_id'] === $value,
                '(expires_at IS NULL OR expires_at > ?)' => $row['expires_at'] === null || $row['expires_at'] > $value,
                default => throw new \LogicException('The fake table does not know the condition ' . $condition),
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    private function dbSelect(): Select
    {
        $index = count($this->dbSelects);
        $this->dbSelects[$index] = [];
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'forUpdate', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($index, $method, $select) {
                while ($args && end($args) === null) {
                    array_pop($args);
                }
                $this->dbSelects[$index][] = [$method, $args];

                return $select;
            });
        }

        return $select;
    }

    /** @return list<array> the update() calls */
    private function updates(): array
    {
        return array_values(array_filter($this->dbLog, static fn (array $call) => $call[0] === 'update'));
    }

    private static function future(): string
    {
        return gmdate('Y-m-d H:i:s', time() + 86400);
    }

    private static function past(): string
    {
        return gmdate('Y-m-d H:i:s', time() - 60);
    }
}
