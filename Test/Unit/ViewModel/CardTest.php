<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Test\Unit\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Zero\QuoteRequest\Model\Config;
use Zero\QuoteRequest\ViewModel\Card;

/**
 * A product card shows "Request a Price" (a link to the product page's request
 * form) for a Price on Request product, by the same rule as GuardQuoteOnly:
 * the flag set and quote requests on.
 */
class CardTest extends TestCase
{
    public function testAQuoteOnlyProductGetsTheRequestLink(): void
    {
        self::assertTrue($this->card(true)->isQuoteOnly($this->product(['zero_quote_only' => '1'])));
    }

    public function testOtherProductsKeepAddToCart(): void
    {
        self::assertFalse($this->card(true)->isQuoteOnly($this->product(['zero_quote_only' => '0'])));
        self::assertFalse($this->card(true)->isQuoteOnly($this->product([])));
        self::assertFalse($this->card(true)->isQuoteOnly(null));
    }

    public function testQuoteRequestsOffKeepAddToCart(): void
    {
        self::assertFalse($this->card(false)->isQuoteOnly($this->product(['zero_quote_only' => '1'])));
    }

    public function testTheLinkOpensTheProductPagesRequestForm(): void
    {
        $card = $this->card(true);

        self::assertSame(
            'https://example.test/lamp.html#zero-quote-product',
            $card->url($this->product([], 'https://example.test/lamp.html'))
        );
        self::assertSame(
            'https://example.test/lamp.html?a=1#zero-quote-product',
            $card->url($this->product([], 'https://example.test/lamp.html?a=1#reviews'))
        );
    }

    public function testFormUrlPointsAProductUrlAtTheRequestForm(): void
    {
        self::assertSame('https://example.test/lamp.html#zero-quote-product', Card::formUrl('https://example.test/lamp.html'));
        self::assertSame('https://example.test/lamp.html#zero-quote-product', Card::formUrl('https://example.test/lamp.html#reviews'));
        self::assertSame('#zero-quote-product', Card::formUrl(''));
    }

    /**
     * The "Recently Ordered" sidebar has ids only: the flag is read per id in
     * the current store, each id once, and ids that are not products skipped.
     */
    public function testQuoteOnlyIdsReadsTheFlagOncePerIdInTheCurrentStore(): void
    {
        $reads = [];
        $resource = $this->createStub(ProductResource::class);
        $resource->method('getAttributeRawValue')->willReturnCallback(
            static function ($id, $attribute, $store) use (&$reads) {
                $reads[] = [$id, $attribute, $store];
                return ['5' => '1', '7' => '0', '9' => '1'][(string) $id] ?? false;
            }
        );

        $ids = $this->card(true, $resource, 3)->quoteOnlyIds(['5', 7, 9, 5, 0, 'x']);

        self::assertSame([5, 9], $ids);
        self::assertSame(
            [[5, 'zero_quote_only', 3], [7, 'zero_quote_only', 3], [9, 'zero_quote_only', 3]],
            $reads,
            'no read for a repeat, 0 or a non-number'
        );
    }

    public function testQuoteOnlyIdsWithQuoteRequestsOffReadsNothing(): void
    {
        $resource = $this->createMock(ProductResource::class);
        $resource->expects(self::never())->method('getAttributeRawValue');

        self::assertSame([], $this->card(false, $resource)->quoteOnlyIds([5, 9]));
    }

    public function testQuoteOnlyIdsOfNoIdsIsEmpty(): void
    {
        $resource = $this->createMock(ProductResource::class);
        $resource->expects(self::never())->method('getAttributeRawValue');

        self::assertSame([], $this->card(true, $resource)->quoteOnlyIds([]));
    }

    private function card(bool $enabled, ?ProductResource $resource = null, int $storeId = 1): Card
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path, $scope = null, $store = null) => $path === 'zero_quote_request/general/enabled'
                && $enabled && ($store === null || $store === $storeId)
        );
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn((string) $storeId);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new Card(new Config($scopeConfig), $resource ?? $this->createStub(ProductResource::class), $storeManager);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function product(array $data, string $url = ''): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getData')->willReturnCallback(static fn ($key = '') => $data[$key] ?? null);
        $product->method('getStoreId')->willReturn(1);
        $product->method('getProductUrl')->willReturn($url);

        return $product;
    }
}
