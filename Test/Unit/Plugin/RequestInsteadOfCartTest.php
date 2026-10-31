<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Test\Unit\Plugin;

use Magento\Catalog\Block\Product\View;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zero\QuoteRequest\Model\Config;
use Zero\QuoteRequest\Plugin\RequestInsteadOfCart;

/**
 * A Price on Request product's add-to-cart block gets the request form as its
 * main control (`main_control_html`): a template that renders it keeps its
 * own output (and so the wish list and compare buttons); one that does not
 * is replaced by the form, as before. Other products and blocks are left alone.
 */
#[AllowMockObjectsWithoutExpectations]
class RequestInsteadOfCartTest extends TestCase
{
    private const FORM = '<div id="zero-quote-product" data-quote-product>form</div>';

    /** @return array<string, array{string}> */
    public static function addToCartBlocks(): array
    {
        return [
            'simple, grouped' => ['product.info.addtocart'],
            'configurable, custom options' => ['product.info.addtocart.additional'],
            'bundle' => ['product.info.addtocart.bundle'],
        ];
    }

    #[DataProvider('addToCartBlocks')]
    public function testAQuoteOnlyProductsBlockGetsTheRequestFormAsItsMainControl(string $name): void
    {
        $block = $this->block($name, true);

        $this->plugin()->beforeToHtml($block);

        self::assertSame(self::FORM, $block->getData(RequestInsteadOfCart::MAIN_CONTROL));
    }

    public function testATemplateThatRendersTheMainControlKeepsItsOutput(): void
    {
        $block = $this->block('product.info.addtocart', true);
        $html = '<div class="box-tocart">' . self::FORM . '<button class="action towishlist"></button></div>';

        self::assertSame($html, $this->plugin()->afterToHtml($block, $html));
    }

    public function testATemplateThatIgnoresTheMainControlIsReplacedByTheForm(): void
    {
        $block = $this->block('product.info.addtocart', true);
        $plugin = $this->plugin();
        $plugin->beforeToHtml($block);

        self::assertSame(self::FORM, $plugin->afterToHtml($block, '<button id="product-addtocart-button"></button>'));
    }

    public function testWithoutTheBeforeStepTheIgnoringTemplateIsStillReplacedByTheForm(): void
    {
        $block = $this->block('product.info.addtocart.bundle', true);

        self::assertSame(self::FORM, $this->plugin()->afterToHtml($block, '<button class="tocart"></button>'));
    }

    public function testOtherProductsKeepAddToCart(): void
    {
        $block = $this->block('product.info.addtocart', false);
        $plugin = $this->plugin();
        $plugin->beforeToHtml($block);

        self::assertNull($block->getData(RequestInsteadOfCart::MAIN_CONTROL));
        self::assertSame('<button></button>', $plugin->afterToHtml($block, '<button></button>'));
    }

    public function testOtherBlocksAreLeftAlone(): void
    {
        $block = $this->block('zero.product.sticky.addtocart', true);
        $plugin = $this->plugin();
        $plugin->beforeToHtml($block);

        self::assertNull($block->getData(RequestInsteadOfCart::MAIN_CONTROL));
        self::assertSame('<div></div>', $plugin->afterToHtml($block, '<div></div>'));
    }

    private function plugin(): RequestInsteadOfCart
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);

        return new RequestInsteadOfCart(new Config($scopeConfig));
    }

    private function block(string $name, bool $quoteOnly): View
    {
        $product = $this->createPartialMock(Product::class, ['getStoreId']);
        $product->method('getStoreId')->willReturn(1);
        $product->setData('zero_quote_only', $quoteOnly ? '1' : '0');

        $form = $this->createPartialMock(Template::class, ['setTemplate', 'toHtml']);
        $form->method('setTemplate')->willReturnSelf();
        $form->method('toHtml')->willReturn(self::FORM);
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('createBlock')->willReturn($form);

        $block = $this->createPartialMock(View::class, ['getNameInLayout', 'getProduct', 'getLayout']);
        $block->method('getNameInLayout')->willReturn($name);
        $block->method('getProduct')->willReturn($product);
        $block->method('getLayout')->willReturn($layout);

        return $block;
    }
}
