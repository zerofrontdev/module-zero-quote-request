<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Test\Unit\Model;

use Magento\Catalog\Helper\Product\Configuration\ConfigurationInterface;
use Magento\Catalog\Helper\Product\ConfigurationPool;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\QuoteRequest\Model\Lines;

class LinesTest extends TestCase
{
    private ConfigurationPool&MockObject $pool;
    private Lines $lines;

    protected function setUp(): void
    {
        $this->pool = $this->createMock(ConfigurationPool::class);
        $this->lines = new Lines($this->pool);
    }

    public function testACartLineKeepsItsOptionsWithoutHtmlAndTheBuyRequestWithoutPageFields(): void
    {
        $configuration = $this->createMock(ConfigurationInterface::class);
        $configuration->method('getOptions')->willReturn([
            ['label' => 'Size', 'value' => 'M'],
            ['label' => 'Engraving', 'print_value' => '<b>Anna</b>'],
        ]);
        $this->pool->method('getByProductType')->with('simple')->willReturn($configuration);
        $quote = $this->quoteWith($this->item(['form_key' => 'x', 'uenc' => 'y', 'qty' => 2, 'super_attribute' => [93 => 5]]));

        $line = $this->lines->fromCart($quote)[0];

        self::assertSame(
            [['label' => 'Size', 'value' => 'M'], ['label' => 'Engraving', 'value' => 'Anna']],
            $line['options']
        );
        self::assertSame(['qty' => 2, 'super_attribute' => [93 => 5]], $line['buy_request']);
        self::assertSame(['product_id' => 11, 'sku' => 'HOOD-1', 'name' => 'Mona Hoodie', 'qty' => 2.0, 'price' => 49.5], [
            'product_id' => $line['product_id'],
            'sku' => $line['sku'],
            'name' => $line['name'],
            'qty' => $line['qty'],
            'price' => $line['price'],
        ]);
    }

    public function testACartLineStillComesBackWhenItsOptionsCannotBeRead(): void
    {
        $this->pool->method('getByProductType')->willThrowException(new \InvalidArgumentException('no helper'));

        $line = $this->lines->fromCart($this->quoteWith($this->item([])))[0];

        self::assertSame([], $line['options']);
        self::assertSame('HOOD-1', $line['sku']);
    }

    public function testAProductFormBecomesOneLineWithAtLeastOneItemAndNoPageFields(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getId')->willReturn(11);
        $product->method('getSku')->willReturn('HOOD-1');
        $product->method('getName')->willReturn('Mona Hoodie');

        $lines = $this->lines->fromProduct($product, ['form_key' => 'x', 'email' => 'a@b.c', 'qty' => '0', 'color' => 'red']);

        self::assertCount(1, $lines);
        self::assertSame(1.0, $lines[0]['qty']);
        self::assertSame(['qty' => 1.0, 'color' => 'red'], $lines[0]['buy_request']);
        self::assertNull($lines[0]['price']);
    }

    public function testAConfigurableLineNamesTheChosenOption(): void
    {
        $type = new class {
            public function getConfigurableAttributesAsArray(): array
            {
                return [['attribute_id' => 93, 'label' => 'Size', 'values' => [
                    ['value_index' => 4, 'label' => 'S'],
                    ['value_index' => 5, 'label' => 'M'],
                ]]];
            }
        };
        $product = $this->createMock(Product::class);
        $product->method('getTypeId')->willReturn('configurable');
        $product->method('getTypeInstance')->willReturn($type);

        $line = $this->lines->fromProduct($product, ['super_attribute' => [93 => '5'], 'qty' => 3])[0];

        self::assertSame([['label' => 'Size', 'value' => 'M']], $line['options']);
        self::assertSame(3.0, $line['qty']);
    }

    private function item(array $buyRequest): Item&MockObject
    {
        $item = $this->createMock(Item::class);
        $item->method('getProductType')->willReturn('simple');
        $item->method('getName')->willReturn('Mona Hoodie');
        $item->method('getQty')->willReturn(2);
        $item->method('getCalculationPrice')->willReturn(49.5);
        $item->method('getProduct')->willReturn(new DataObject(['id' => 11, 'sku' => 'HOOD-1']));
        $item->method('getBuyRequest')->willReturn(new DataObject($buyRequest));

        return $item;
    }

    private function quoteWith(Item $item): Quote
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getAllVisibleItems')->willReturn([$item]);

        return $quote;
    }
}
