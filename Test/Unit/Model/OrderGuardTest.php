<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\Option;
use Magento\Store\Model\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zero\QuoteRequest\Model\OrderGuard;
use Zero\QuoteRequest\Model\Requests;
use Zero\QuoteRequest\Observer\GuardQuoteOnly;

/**
 * A cart's quote lines can be ordered only while their request is open, in
 * this store and currency, shipped to one address, and every line still at
 * its quoted price; a cart without quote lines asks nothing. The cart
 * checked is remembered for the claim at place() until forgotten.
 */
class OrderGuardTest extends TestCase
{
    /** @var array<int, array|null> request id => row */
    private array $rows = [];
    /** @var array<int, list<array>> request id => its items */
    private array $items = [];
    /** @var list<int> get() calls */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->rows[12] = ['request_id' => '12', 'status' => Requests::QUOTED, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'store_id' => '1', 'currency' => 'USD'];
        $this->items[12] = [['item_id' => '5', 'quoted_price' => '25.0000'], ['item_id' => '6', 'quoted_price' => '3.2500']];
    }

    public function testAnOpenQuoteAtItsPricesPasses(): void
    {
        $quote = $this->quote([$this->line(12, 5, 25.0), $this->line(12, 6, 3.25), $this->plainLine()]);
        $guard = $this->guard();

        $guard->check($quote);

        self::assertSame([12], $this->asked);
        self::assertSame([12], $guard->requestIds($quote));
    }

    public function testAnOrderedQuoteIsRefused(): void
    {
        $this->rows[12]['status'] = Requests::ORDERED;

        $this->assertRefused('Quote Q000012 has already been ordered. Remove its lines from your cart to check out.', $this->quote([$this->line(12, 5, 25.0)]));
    }

    #[DataProvider('closedRequests')]
    public function testAnExpiredOrDeclinedQuoteIsRefused(?string $status, ?string $expiresAt): void
    {
        $this->rows[12] = $status === null ? null : ['status' => $status, 'expires_at' => $expiresAt] + $this->rows[12];

        $this->assertRefused('Quote Q000012 can no longer be ordered. Remove its lines from your cart to check out.', $this->quote([$this->line(12, 5, 25.0)]));
    }

    public static function closedRequests(): array
    {
        return [
            'expired' => [Requests::QUOTED, gmdate('Y-m-d H:i:s', time() - 60)],
            'declined' => [Requests::DECLINED, null],
            'not answered yet' => [Requests::NEW, null],
            'gone' => [null, null],
        ];
    }

    public function testAChangedPriceIsRefused(): void
    {
        $this->assertRefused('Quote Q000012 has changed since you accepted it. Accept it again to check out.', $this->quote([$this->line(12, 5, 30.0)]));
    }

    public function testALineWhoseRequestItemIsGoneOrUnpricedIsRefused(): void
    {
        $this->assertRefused('Quote Q000012 has changed since you accepted it. Accept it again to check out.', $this->quote([$this->line(12, 99, 25.0)]));

        $this->items[12][0]['quoted_price'] = null;
        $this->assertRefused('Quote Q000012 has changed since you accepted it. Accept it again to check out.', $this->quote([$this->line(12, 5, 0.0)]));
    }

    public function testAPriceWithinARoundingHairPasses(): void
    {
        $this->guard()->check($this->quote([$this->line(12, 5, 25.00004)]));

        self::assertSame([12], $this->asked);
    }

    public function testAnotherStoreIsRefused(): void
    {
        $this->assertRefused('Open this quote in the store it was made for.', $this->quote([$this->line(12, 5, 25.0)], storeId: 2));
    }

    public function testMultishippingIsRefused(): void
    {
        $this->assertRefused(
            'Quote Q000012 ships to one address: check out with one shipping address.',
            $this->quote([$this->line(12, 5, 25.0)], data: ['is_multi_shipping' => 1])
        );
    }

    public function testACartSwitchedToAnotherCurrencyIsRefused(): void
    {
        $this->rows[12]['currency'] = 'EUR';

        $this->assertRefused(
            'Quote Q000012 is in EUR. Switch the store\'s currency to EUR to order it.',
            $this->quote([$this->line(12, 5, 25.0)], data: ['quote_currency_code' => 'USD'])
        );
    }

    public function testWithoutACartCurrencyTheStoresIsCompared(): void
    {
        $this->rows[12]['currency'] = 'EUR';
        $this->guard()->check($this->quote([$this->line(12, 5, 25.0)], storeCurrency: 'EUR'));

        $this->assertRefused('Quote Q000012 is in EUR. Switch the store\'s currency to EUR to order it.', $this->quote([$this->line(12, 5, 25.0)], storeCurrency: 'USD'));
    }

    public function testARequestWithoutCurrencyIsNotChecked(): void
    {
        $this->rows[12]['currency'] = null;

        $this->guard()->check($this->quote([$this->line(12, 5, 25.0)], data: ['quote_currency_code' => 'GBP']));

        self::assertSame([12], $this->asked);
    }

    public function testACartWithoutQuoteLinesAsksNothing(): void
    {
        $guard = $this->guard();
        $quote = $this->quote([$this->plainLine(), $this->line(0, 5, 25.0), $this->brokenLine()]);

        $guard->check($quote);

        self::assertSame([], $guard->requestIds($quote));
        self::assertSame([], $this->asked);
    }

    public function testLinesOfTwoQuotesAreBothChecked(): void
    {
        $this->rows[15] = ['request_id' => '15'] + $this->rows[12];
        $this->items[15] = [['item_id' => '8', 'quoted_price' => '10.0000']];
        $this->rows[15]['status'] = Requests::ORDERED;
        $quote = $this->quote([$this->line(12, 5, 25.0), $this->line(15, 8, 10.0)]);

        self::assertSame([12, 15], $this->guard()->requestIds($quote));
        $this->assertRefused('Quote Q000015 has already been ordered. Remove its lines from your cart to check out.', $quote);
    }

    public function testTheCheckedCartIsRememberedUntilForgotten(): void
    {
        $guard = $this->guard();
        $quote = $this->quote([$this->line(12, 5, 25.0)]);

        self::assertNull($guard->checked(77));
        $guard->check($quote);
        self::assertSame($quote, $guard->checked(77));
        $guard->forget(77);
        self::assertNull($guard->checked(77));
    }

    private function assertRefused(string $message, Quote $quote): void
    {
        try {
            $this->guard()->check($quote);
            self::fail('refused: ' . $message);
        } catch (LocalizedException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    private function guard(): OrderGuard
    {
        $requests = $this->createStub(Requests::class);
        $requests->method('get')->willReturnCallback(function (int $id) {
            $this->asked[] = $id;

            return $this->rows[$id] ?? null;
        });
        $requests->method('items')->willReturnCallback(fn (int $id) => $this->items[$id] ?? []);

        return new OrderGuard($requests, new Json());
    }

    private function quote(array $items, int $storeId = 1, array $data = [], string $storeCurrency = 'USD'): Quote
    {
        $store = $this->createStub(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn($storeCurrency);
        $quote = $this->createStub(Quote::class);
        $quote->method('getId')->willReturn(77);
        $quote->method('getStoreId')->willReturn($storeId);
        $quote->method('getStore')->willReturn($store);
        $quote->method('getAllVisibleItems')->willReturn($items);
        $quote->method('getData')->willReturnCallback(static fn ($key = '') => $data[$key] ?? null);

        return $quote;
    }

    private function line(int $requestId, int $itemId, float $price): Item
    {
        return $this->item(json_encode(['request_id' => $requestId, 'item_id' => $itemId, 'qty' => 1]), $price);
    }

    private function plainLine(): Item
    {
        $item = $this->createStub(Item::class);
        $item->method('getOptionByCode')->willReturn(null);

        return $item;
    }

    private function brokenLine(): Item
    {
        return $this->item('{not json', 25.0);
    }

    private function item(string $value, float $price): Item
    {
        $option = $this->createStub(Option::class);
        $option->method('getValue')->willReturn($value);
        $item = $this->createStub(Item::class);
        $item->method('getOptionByCode')->willReturnCallback(static fn ($code) => $code === GuardQuoteOnly::OPTION ? $option : null);
        $item->method('getData')->willReturnCallback(static fn ($key = '') => $key === 'original_custom_price' ? $price : null);

        return $item;
    }
}
