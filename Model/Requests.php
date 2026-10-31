<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManager;

/**
 * Quote requests and their lines. Statuses: new (asked), quoted (answered
 * with prices, valid until expires_at), ordered (claimed by the order placed
 * from it; quoted again if that order is cancelled or its cart restored
 * unpaid), declined.
 */
class Requests
{
    public const NEW = 'new';
    public const QUOTED = 'quoted';
    public const ORDERED = 'ordered';
    public const DECLINED = 'declined';

    private const TABLE = 'zero_quote_request';
    private const ITEMS = 'zero_quote_request_item';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EventManager $eventManager
    ) {
    }

    /**
     * @param array $request store_id, customer_id, name, email, phone, company, comment, currency
     * @param array<int, array> $items product_id, sku, name, options (array), buy_request (array), qty, price
     * @return array The request
     */
    public function create(array $request, array $items): array
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $connection->insert($this->resource->getTableName(self::TABLE), array_intersect_key($request, array_flip([
                'store_id', 'customer_id', 'name', 'email', 'phone', 'company', 'comment', 'currency',
            ])) + ['status' => self::NEW, 'token' => bin2hex(random_bytes(24))]);
            $id = (int) $connection->lastInsertId($this->resource->getTableName(self::TABLE));
            foreach ($items as $item) {
                $connection->insert($this->resource->getTableName(self::ITEMS), [
                    'request_id' => $id,
                    'product_id' => (int) $item['product_id'],
                    'sku' => mb_substr((string) $item['sku'], 0, 64),
                    'name' => mb_substr((string) $item['name'], 0, 255),
                    'options' => json_encode($item['options'] ?? []),
                    'buy_request' => json_encode($item['buy_request'] ?? []),
                    'qty' => max(1.0, (float) $item['qty']),
                    'price' => isset($item['price']) ? (float) $item['price'] : null,
                ]);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        $row = $this->get($id);
        // Zero_Webhooks listens (quote_request.created).
        $this->eventManager->dispatch('zero_quote_request_created', ['request' => $row, 'items' => $this->items($id), 'store_id' => (int) $row['store_id']]);
        return $row;
    }

    public function get(int $id): ?array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName(self::TABLE))->where('request_id = ?', $id)
        ) ?: null;
    }

    public function byToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $connection = $this->resource->getConnection();

        return $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName(self::TABLE))->where('token = ?', $token)
        ) ?: null;
    }

    /**
     * @return array<int, array> With options and buy_request decoded
     */
    public function items(int $requestId): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()->from($this->resource->getTableName(self::ITEMS))->where('request_id = ?', $requestId)->order('item_id')
        );

        return array_map(static fn (array $row) => [
            'options' => json_decode((string) $row['options'], true) ?: [],
            'buy_request' => json_decode((string) $row['buy_request'], true) ?: [],
        ] + $row, $rows);
    }

    /**
     * Newest first.
     *
     * @return array<int, array>
     */
    public function all(string $status = '', string $search = '', ?int $customerId = null, int $limit = 200): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['r' => $this->resource->getTableName(self::TABLE)])
            ->joinLeft(
                ['i' => $this->resource->getTableName(self::ITEMS)],
                'i.request_id = r.request_id',
                ['lines' => 'COUNT(i.item_id)', 'quoted_total' => 'SUM(i.quoted_price * i.qty)', 'asked_total' => 'SUM(i.price * i.qty)']
            )
            ->group('r.request_id')
            ->order('r.request_id DESC')
            ->limit($limit);
        if ($status !== '') {
            $select->where('r.status = ?', $status);
        }
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $select->where('r.email LIKE ? OR r.name LIKE ? OR r.company LIKE ?', $like);
        }
        if ($customerId !== null) {
            $select->where('r.customer_id = ?', $customerId);
        }

        return $connection->fetchAll($select);
    }

    /**
     * The store's answer: a price per line (unit, in the request's
     * currency), a note, valid until. False (nothing changed) when the
     * request is gone or already ordered: the row is read FOR UPDATE, so an
     * order placed at the same moment can't be answered over.
     *
     * @param array<int, float> $prices By item id
     */
    public function quote(int $requestId, array $prices, string $answer, string $expiresAtUtc): bool
    {
        return $this->answer($requestId, function ($connection) use ($requestId, $prices, $answer, $expiresAtUtc): void {
            foreach ($prices as $itemId => $price) {
                $connection->update(
                    $this->resource->getTableName(self::ITEMS),
                    ['quoted_price' => round(max(0.0, (float) $price), 4)],
                    ['item_id = ?' => (int) $itemId, 'request_id = ?' => $requestId]
                );
            }
            $connection->update($this->resource->getTableName(self::TABLE), [
                'status' => self::QUOTED,
                'answer' => $answer,
                'expires_at' => $expiresAtUtc,
            ], ['request_id = ?' => $requestId]);
        });
    }

    /** False (nothing changed) when the request is gone or already ordered. */
    public function decline(int $requestId, string $answer): bool
    {
        return $this->answer($requestId, function ($connection) use ($requestId, $answer): void {
            $connection->update(
                $this->resource->getTableName(self::TABLE),
                ['status' => self::DECLINED, 'answer' => $answer],
                ['request_id = ?' => $requestId]
            );
        });
    }

    /**
     * Kept for callers outside the module; orders claim their quote with claim().
     */
    public function markOrdered(int $requestId, string $orderIncrementId): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['status' => self::ORDERED, 'order_increment_id' => $orderIncrementId],
            ['request_id = ?' => $requestId, 'status = ?' => self::QUOTED]
        );
    }

    /**
     * The order being placed takes the quote: one conditional UPDATE, so of
     * two carts placing at once exactly one wins. False when it is no longer
     * quoted (ordered, declined, re-answered away) or past its date.
     */
    public function claim(int $requestId, string $orderIncrementId): bool
    {
        if ($orderIncrementId === '') {
            return false;
        }

        return $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['status' => self::ORDERED, 'order_increment_id' => $orderIncrementId],
            [
                'request_id = ?' => $requestId,
                'status = ?' => self::QUOTED,
                '(expires_at IS NULL OR expires_at > ?)' => gmdate('Y-m-d H:i:s'),
            ]
        ) > 0;
    }

    /** Undoes this order's claim (placing it failed); another order's claim is left alone. */
    public function release(int $requestId, string $orderIncrementId): void
    {
        if ($orderIncrementId === '') {
            return;
        }
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['status' => self::QUOTED, 'order_increment_id' => null],
            ['request_id = ?' => $requestId, 'status = ?' => self::ORDERED, 'order_increment_id = ?' => $orderIncrementId]
        );
    }

    /**
     * The order did not consume its quote after all (cancelled, or its cart
     * restored while unpaid): quoted again, if not past its date. Only the
     * requests this very order claimed; safe to repeat.
     */
    public function reopen(string $orderIncrementId): void
    {
        if ($orderIncrementId === '') {
            return;
        }
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['status' => self::QUOTED, 'order_increment_id' => null],
            [
                'order_increment_id = ?' => $orderIncrementId,
                'status = ?' => self::ORDERED,
                '(expires_at IS NULL OR expires_at > ?)' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Quoted and not past its date: it can be accepted.
     */
    public static function isOpen(?array $request): bool
    {
        return $request !== null && $request['status'] === self::QUOTED
            && ($request['expires_at'] === null || $request['expires_at'] > gmdate('Y-m-d H:i:s'));
    }

    public static function number(array $request): string
    {
        return sprintf('Q%06d', (int) $request['request_id']);
    }

    /**
     * Runs an admin answer on the row read FOR UPDATE; false (committed, nothing changed) when gone or ordered.
     */
    private function answer(int $requestId, callable $write): bool
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $status = $connection->fetchOne(
                $connection->select()->from($this->resource->getTableName(self::TABLE), ['status'])
                    ->where('request_id = ?', $requestId)->forUpdate(true)
            );
            if ($status === false || $status === null || $status === self::ORDERED) {
                $connection->commit();

                return false;
            }
            $write($connection);
            $connection->commit();

            return true;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
