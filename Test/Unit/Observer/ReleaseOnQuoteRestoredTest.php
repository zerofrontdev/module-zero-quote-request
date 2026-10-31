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
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;
use Zero\QuoteRequest\Model\Requests;
use Zero\QuoteRequest\Observer\ReleaseOnQuoteRestored;
use Zero\QuoteRequest\Test\Unit\Fake\QuoteRequestTable;

/**
 * zero_checkout_quote_restored (W1-R2, P6-4): the cart of an unpaid
 * redirect order is back, so the quote THAT order claimed is quoted again;
 * another order's claim is untouched, a repeated event changes nothing more,
 * and an order that was paid meanwhile keeps its quote.
 */
class ReleaseOnQuoteRestoredTest extends TestCase
{
    use QuoteRequestTable;

    public function testTheClaimingOrdersQuoteIsQuotedAgain(): void
    {
        $this->row(12, Requests::ORDERED, '100000042', self::future());

        $this->observer()->execute($this->event($this->order('100000042', 0.0)));

        self::assertSame(Requests::QUOTED, $this->table[12]['status']);
        self::assertNull($this->table[12]['order_increment_id']);
    }

    public function testOnlyTheClaimingOrderReleasesIt(): void
    {
        // The customer paid order 43 meanwhile from a second cart; the restore is for the old order 42.
        $this->row(12, Requests::ORDERED, '100000043', self::future());

        $this->observer()->execute($this->event($this->order('100000042', 0.0)));

        self::assertSame([Requests::ORDERED, '100000043'], [$this->table[12]['status'], $this->table[12]['order_increment_id']]);
        self::assertSame(0, $this->updates()[0][4], 'no row changed');
    }

    public function testARepeatedEventIsSafe(): void
    {
        $this->row(12, Requests::ORDERED, '100000042', self::future());
        $observer = $this->observer();
        $observer->execute($this->event($this->order('100000042', 0.0)));
        // Another order claims it between two polls of the same return.
        self::assertTrue($this->requests()->claim(12, '100000044'));

        $observer->execute($this->event($this->order('100000042', 0.0)));

        self::assertSame([Requests::ORDERED, '100000044'], [$this->table[12]['status'], $this->table[12]['order_increment_id']]);
    }

    public function testAPaidOrderKeepsItsQuote(): void
    {
        $this->row(12, Requests::ORDERED, '100000042', self::future());

        $this->observer()->execute($this->event($this->order('100000042', 49.0)));

        self::assertSame(Requests::ORDERED, $this->table[12]['status']);
        self::assertSame([], $this->updates(), 'not even asked');
    }

    public function testAnExpiredQuoteStaysOrdered(): void
    {
        $this->row(12, Requests::ORDERED, '100000042', self::past());

        $this->observer()->execute($this->event($this->order('100000042', 0.0)));

        self::assertSame(Requests::ORDERED, $this->table[12]['status']);
    }

    public function testAnEventWithoutAnOrderDoesNothing(): void
    {
        $this->observer()->execute(new Observer(['event' => new Event(['quote' => $this->createStub(Quote::class)])]));

        self::assertSame([], $this->dbLog);
    }

    private function observer(): ReleaseOnQuoteRestored
    {
        return new ReleaseOnQuoteRestored($this->requests());
    }

    private function order(string $incrementId, float $paid): OrderInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getBaseTotalPaid')->willReturn($paid);

        return $order;
    }

    private function event(OrderInterface $order): Observer
    {
        return new Observer(['event' => new Event(['order' => $order, 'quote' => $this->createStub(Quote::class)])]);
    }
}
