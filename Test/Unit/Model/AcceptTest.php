<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Test\Unit\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Store\Model\Store;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Zero\QuoteRequest\Model\Accept;
use Zero\QuoteRequest\Model\Accepting;
use Zero\QuoteRequest\Model\Requests;

/**
 * A quote is accepted only in the currency it was priced in, refused before
 * the cart loses a line; a request without a currency (older rows) is not
 * checked.
 */
// The cart is a partial mock (its real setData/getData); it carries no expectations.
#[AllowMockObjectsWithoutExpectations]
class AcceptTest extends TestCase
{
    /** @var list<string> removeItem / save / items calls */
    private array $log = [];

    public function testAQuoteInAnotherCurrencyIsRefusedBeforeTheCartChanges(): void
    {
        try {
            $this->accept()->execute($this->request('EUR'), $this->quote('USD'));
            self::fail('another currency must be refused');
        } catch (LocalizedException $e) {
            self::assertSame('This quote is in EUR. Switch the store\'s currency to EUR, then accept it.', $e->getMessage());
        }

        self::assertSame([], $this->log, 'no line removed, no item read, nothing saved');
    }

    public function testARequestWithoutCurrencyIsAccepted(): void
    {
        $quote = $this->quote('USD');

        $this->accept()->execute($this->request(null), $quote);

        self::assertSame(['removeItem 3', 'items 12', 'save'], $this->log);
        self::assertSame(12, $quote->getData('zero_quote_request_id'));
    }

    public function testTheSameCurrencyIsAccepted(): void
    {
        $this->accept()->execute($this->request('USD'), $this->quote('USD'));

        self::assertSame(['removeItem 3', 'items 12', 'save'], $this->log);
    }

    private function accept(): Accept
    {
        $carts = $this->createStub(CartRepositoryInterface::class);
        $carts->method('save')->willReturnCallback(function (): void {
            $this->log[] = 'save';
        });
        $requests = $this->createStub(Requests::class);
        $requests->method('items')->willReturnCallback(function (int $id) {
            $this->log[] = "items $id";

            return [];
        });

        return new Accept(
            $this->createStub(CheckoutSession::class),
            $carts,
            $this->createStub(ProductRepositoryInterface::class),
            $requests,
            new Accepting()
        );
    }

    private function request(?string $currency): array
    {
        return [
            'request_id' => '12', 'status' => Requests::QUOTED, 'expires_at' => null, 'store_id' => '1', 'currency' => $currency,
        ];
    }

    private function quote(string $currency): Quote
    {
        $store = $this->createStub(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn($currency);
        $line = $this->createStub(Item::class);
        $line->method('getId')->willReturn(3);
        $quote = $this->createPartialMock(Quote::class, ['getStore', 'getStoreId', 'getAllVisibleItems', 'removeItem', 'collectTotals', 'getId']);
        $quote->method('getStore')->willReturn($store);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getAllVisibleItems')->willReturn([$line]);
        $quote->method('removeItem')->willReturnCallback(function ($id) use ($quote) {
            $this->log[] = "removeItem $id";

            return $quote;
        });
        $quote->method('collectTotals')->willReturnSelf();
        $quote->method('getId')->willReturn(77);

        return $quote;
    }
}
