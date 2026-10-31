<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface as EventManager;
use PHPUnit\Framework\TestCase;
use Zero\QuoteRequest\Model\Requests;
use Zero\QuoteRequest\Test\Unit\Fake\QuoteRequestTable;

/**
 * The claim is one conditional UPDATE (quoted, not past its date): of two
 * orders exactly one gets the quote. A release undoes only this order's
 * claim; a reopen only the claim of the order named, while not expired. The
 * admin can't answer over an ordered request (row read FOR UPDATE; the false
 * branch commits). A new request fires zero_quote_request_created.
 */
class RequestsTest extends TestCase
{
    use QuoteRequestTable;

    public function testClaimIsConditionalOnQuotedAndNotExpired(): void
    {
        $this->row(12, Requests::QUOTED, null, self::future());

        self::assertTrue($this->requests()->claim(12, '100000042'));

        $update = $this->updates()[0];
        self::assertSame('zero_quote_request', $update[1]);
        self::assertSame(['status' => Requests::ORDERED, 'order_increment_id' => '100000042'], $update[2]);
        self::assertSame(['request_id = ?', 'status = ?', '(expires_at IS NULL OR expires_at > ?)'], array_keys($update[3]));
        self::assertSame(12, $update[3]['request_id = ?']);
        self::assertSame(Requests::QUOTED, $update[3]['status = ?']);
        $now = strtotime($update[3]['(expires_at IS NULL OR expires_at > ?)'] . ' UTC');
        self::assertEqualsWithDelta(time(), $now, 5, 'compared with the time now, in UTC');
        self::assertSame(Requests::ORDERED, $this->table[12]['status']);
        self::assertSame('100000042', $this->table[12]['order_increment_id']);
    }

    public function testOfTwoClaimsOnlyTheFirstWins(): void
    {
        $this->row(12, Requests::QUOTED);
        $requests = $this->requests();

        self::assertTrue($requests->claim(12, '100000042'));
        self::assertFalse($requests->claim(12, '100000043'));
        self::assertSame('100000042', $this->table[12]['order_increment_id']);
    }

    public function testAnExpiredDeclinedOrNewRequestCannotBeClaimed(): void
    {
        $this->row(12, Requests::QUOTED, null, self::past());
        $this->row(13, Requests::DECLINED);
        $this->row(14, Requests::NEW);
        $requests = $this->requests();

        self::assertFalse($requests->claim(12, '100000042'));
        self::assertFalse($requests->claim(13, '100000042'));
        self::assertFalse($requests->claim(14, '100000042'));
        self::assertSame([Requests::QUOTED, Requests::DECLINED, Requests::NEW], array_column($this->table, 'status'));
    }

    public function testAClaimWithoutAnOrderNumberIsRefusedWithoutAQuery(): void
    {
        $this->row(12, Requests::QUOTED);

        self::assertFalse($this->requests()->claim(12, ''));
        self::assertSame([], $this->dbLog);
    }

    public function testReleaseOnlyUndoesThisOrdersClaim(): void
    {
        $this->row(12, Requests::ORDERED, '100000042');
        $requests = $this->requests();

        $requests->release(12, '100000043');
        self::assertSame('100000042', $this->table[12]['order_increment_id'], 'another order\'s claim is left alone');
        self::assertSame(
            ['request_id = ?' => 12, 'status = ?' => Requests::ORDERED, 'order_increment_id = ?' => '100000043'],
            $this->updates()[0][3]
        );

        $requests->release(12, '100000042');
        self::assertSame(Requests::QUOTED, $this->table[12]['status']);
        self::assertNull($this->table[12]['order_increment_id']);

        $requests->release(12, '');
        self::assertCount(2, $this->updates(), 'no order number: no query');
    }

    public function testReopenTakesBackOnlyTheNamedOrdersClaimWhileNotExpired(): void
    {
        $this->row(12, Requests::ORDERED, '100000042', self::future());
        $this->row(13, Requests::ORDERED, '100000050');
        $this->row(14, Requests::ORDERED, '100000060', self::past());
        $requests = $this->requests();

        $requests->reopen('100000042');
        $requests->reopen('100000060');
        $requests->reopen('');

        self::assertSame([Requests::QUOTED, null], [$this->table[12]['status'], $this->table[12]['order_increment_id']]);
        self::assertSame([Requests::ORDERED, '100000050'], [$this->table[13]['status'], $this->table[13]['order_increment_id']]);
        self::assertSame([Requests::ORDERED, '100000060'], [$this->table[14]['status'], $this->table[14]['order_increment_id']], 'past its date: stays ordered');
        self::assertCount(2, $this->updates());
        self::assertSame(
            ['order_increment_id = ?', 'status = ?', '(expires_at IS NULL OR expires_at > ?)'],
            array_keys($this->updates()[0][3])
        );
    }

    public function testQuoteRefusesAnOrderedRequest(): void
    {
        $this->row(12, Requests::ORDERED, '100000042');

        self::assertFalse($this->requests()->quote(12, [5 => 20.0], 'A team price', self::future()));

        self::assertSame([], $this->updates());
        self::assertSame([['begin'], ['fetchOne'], ['commit']], $this->dbLog, 'committed, never rolled back');
        self::assertContains(['forUpdate', [true]], $this->dbSelects[0]);
        self::assertContains(['where', ['request_id = ?', 12]], $this->dbSelects[0]);
    }

    public function testQuoteAnswersAnOpenRequest(): void
    {
        $this->row(12, Requests::NEW);

        self::assertTrue($this->requests()->quote(12, [5 => 20.0, 6 => -3], 'A team price', '2026-12-01 00:00:00'));

        $updates = $this->updates();
        self::assertSame(['zero_quote_request_item', ['quoted_price' => 20.0], ['item_id = ?' => 5, 'request_id = ?' => 12]], array_slice($updates[0], 1, 3));
        self::assertSame(['quoted_price' => 0.0], $updates[1][2], 'never below 0');
        self::assertSame(['status' => Requests::QUOTED, 'answer' => 'A team price', 'expires_at' => '2026-12-01 00:00:00'], $updates[2][2]);
        self::assertSame(['commit'], $this->dbLog[array_key_last($this->dbLog)]);
    }

    public function testDeclineRefusesAMissingOrOrderedRequest(): void
    {
        $this->row(12, Requests::ORDERED, '100000042');
        $requests = $this->requests();

        self::assertFalse($requests->decline(12, 'No'));
        self::assertFalse($requests->decline(99, 'No'));
        self::assertSame([], $this->updates());
        self::assertNotContains(['rollBack'], $this->dbLog);

        $this->row(13, Requests::QUOTED);
        self::assertTrue($requests->decline(13, 'No'));
        self::assertSame(Requests::DECLINED, $this->table[13]['status']);
    }

    public function testAFailedAnswerRollsBackAndPropagates(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn(Requests::QUOTED);
        $connection->method('update')->willThrowException(new \RuntimeException('Lock wait timeout'));
        $calls = [];
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $connection->method($method)->willReturnCallback(function () use (&$calls, $method, $connection) {
                $calls[] = $method;

                return $connection;
            });
        }

        try {
            (new Requests($this->resource($connection), $this->createStub(EventManager::class)))->decline(12, 'No');
            self::fail('the failure must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('Lock wait timeout', $e->getMessage());
        }
        self::assertSame(['beginTransaction', 'rollBack'], $calls);
    }

    public function testCreateDispatchesTheCreatedEvent(): void
    {
        $row = ['request_id' => '12', 'store_id' => '1', 'status' => Requests::NEW, 'token' => str_repeat('a', 48)];
        $items = [['item_id' => '5', 'request_id' => '12', 'options' => '[]', 'buy_request' => '[]', 'quoted_price' => null]];
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'order'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        // lastInsertId() is the PDO adapter's, not the interface's.
        $connection = $this->createStub(Mysql::class);
        $connection->method('select')->willReturn($select);
        $connection->method('lastInsertId')->willReturn('12');
        $connection->method('fetchRow')->willReturn($row);
        $connection->method('fetchAll')->willReturn($items);
        $events = $this->createMock(EventManager::class);
        $events->expects(self::once())->method('dispatch')->with(
            'zero_quote_request_created',
            self::callback(static fn (array $data) => $data['request'] === $row
                && $data['store_id'] === 1
                && $data['items'][0]['item_id'] === '5'
                && $data['items'][0]['options'] === [])
        );

        $created = (new Requests($this->resource($connection), $events))->create(
            ['store_id' => 1, 'name' => 'Zero', 'email' => 'zero@example.com', 'currency' => 'USD'],
            [['product_id' => 11, 'sku' => '24-MB01', 'name' => 'Bag', 'qty' => 5, 'price' => 34]]
        );

        self::assertSame($row, $created);
    }

    public function testTheEventManagerIsRequired(): void
    {
        $parameter = (new \ReflectionMethod(Requests::class, '__construct'))->getParameters()[1];

        self::assertSame(EventManager::class, (string) $parameter->getType());
        self::assertFalse($parameter->isOptional(), 'an optional object argument is never injected by Magento');
    }

    private function resource(AdapterInterface $connection): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return $resource;
    }
}
