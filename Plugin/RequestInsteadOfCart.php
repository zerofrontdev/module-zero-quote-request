<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Plugin;

use Magento\Catalog\Block\Product\View;
use Magento\Framework\View\Element\Template;
use Zero\QuoteRequest\Model\Config;

/**
 * On a Price on Request product's page, Add to Cart becomes "Request a
 * price" (its form posts the product form's fields with HTMX).
 *
 * The plugin only picks the main control: it hands the request form to the
 * add-to-cart block as `main_control_html`, and the Zero theme's
 * addtocart.phtml renders it in place of the qty and Add to Cart button,
 * keeping the secondary actions (Add to Wish List, Compare) next to it. A
 * template that does not know `main_control_html` (another theme) renders
 * Add to Cart as usual, and then the whole block's output is replaced, as
 * before.
 */
class RequestInsteadOfCart
{
    public const MAIN_CONTROL = 'main_control_html';

    /** The simple/grouped block, the one under the options (configurable, custom options), the bundle summary's. */
    private const ADD_TO_CART = ['product.info.addtocart', 'product.info.addtocart.additional', 'product.info.addtocart.bundle'];

    /** The request form's own marker (Zero_QuoteRequest::product.phtml). */
    private const MARKER = 'data-quote-product';

    public function __construct(private readonly Config $config)
    {
    }

    public function beforeToHtml(View $subject): void
    {
        if ($this->applies($subject)) {
            $subject->setData(self::MAIN_CONTROL, $this->requestForm($subject));
        }
    }

    public function afterToHtml(View $subject, string $html): string
    {
        if (!$this->applies($subject) || str_contains($html, self::MARKER)) {
            return $html;
        }

        return (string) $subject->getData(self::MAIN_CONTROL) ?: $this->requestForm($subject);
    }

    private function applies(View $subject): bool
    {
        return in_array($subject->getNameInLayout(), self::ADD_TO_CART, true) && $this->config->isQuoteOnly($subject->getProduct());
    }

    private function requestForm(View $subject): string
    {
        return $subject->getLayout()->createBlock(Template::class)
            ->setTemplate('Zero_QuoteRequest::product.phtml')
            ->setData('product', $subject->getProduct())
            ->toHtml();
    }
}
