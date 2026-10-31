<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Zero\QuoteRequest\Model\OrderGuard;
use Zero\QuoteRequest\Model\Requests;
use Zero\QuoteRequest\Observer\CheckQuoteBeforeSubmit;
use Zero\QuoteRequest\Observer\ReopenOnCancel;
use Zero\QuoteRequest\Test\Unit\Fake\QuoteRequestTable;

/**
 * A cancelled order did not consume its quote: the request it claimed is
 * quoted again (not when past its date, P6-4); other orders' claims stay.
 * checkout_submit_before only checks, it claims nothing.
 */
class ReopenOnCancelTest extends TestCase
{
    use QuoteRequestTable;

    public function testACancelledOrdersQuoteIsQuotedAgain(): void
    {
        $this->row(12, Requests::ORDERED, '100000042', self::future());
        $this->row(13, Requests::ORDERED, '100000050', self::future());

        (new ReopenOnCancel($this->requests()))->execute($this->event($this->order('100000042')));

        self::assertSame([Requests::QUOTED, null], [$this->table[12]['status'], $this->table[12]['order_increment_id']]);
        self::assertSame([Requests::ORDERED, '100000050'], [$this->table[13]['status'], $this->table[13]['order_increment_id']]);
    }

    public function testAnExpiredQuoteStaysOrdered(): void
    {
        $this->row(12, Requests::ORDERED, '100000042', self::past());

        (new ReopenOnCancel($this->requests()))->execute($this->event($this->order('100000042')));

        self::assertSame(Requests::ORDERED, $this->table[12]['status']);
    }

    public function testNoOrderNoQuery(): void
    {
        (new ReopenOnCancel($this->requests()))->execute(new Observer(['event' => new Event([])]));

        self::assertSame([], $this->dbLog);
    }

    public function testTheSubmitCheckOnlyChecks(): void
    {
        $quote = $this->createStub(Quote::class);
        $checked = [];
        $guard = $this->createStub(OrderGuard::class);
        $guard->method('check')->willReturnCallback(function (Quote $q) use (&$checked): void {
            $checked[] = $q;
        });

        (new CheckQuoteBeforeSubmit($guard))->execute(new Observer(['event' => new Event(['quote' => $quote])]));
        (new CheckQuoteBeforeSubmit($guard))->execute(new Observer(['event' => new Event([])]));

        self::assertSame([$quote], $checked);
    }

    private function order(string $incrementId): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getIncrementId')->willReturn($incrementId);

        return $order;
    }

    private function event(Order $order): Observer
    {
        return new Observer(['event' => new Event(['order' => $order])]);
    }
}
