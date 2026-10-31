<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\ViewModel;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Zero\QuoteRequest\Model\Config;
use Zero\QuoteRequest\Model\Lines;
use Zero\QuoteRequest\Model\Requests;

/**
 * The storefront's quote pages: the request form (the cart's lines, your
 * details), a request by its link, a customer's requests.
 */
class Storefront implements ArgumentInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly RequestInterface $request,
        private readonly Requests $requests,
        private readonly Lines $lines,
        private readonly Config $config,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function cartButton(): bool
    {
        return $this->config->cartButton() && $this->checkoutSession->getQuote()->getItemsCount() > 0
            && !$this->checkoutSession->getQuote()->getData('zero_quote_request_id');
    }

    /**
     * @return array{name: string, email: string}
     */
    public function customer(): array
    {
        if (!$this->customerSession->isLoggedIn()) {
            return ['name' => '', 'email' => ''];
        }
        $customer = $this->customerSession->getCustomer();

        return ['name' => trim($customer->getFirstname() . ' ' . $customer->getLastname()), 'email' => (string) $customer->getEmail()];
    }

    public function cartLines(): array
    {
        return $this->lines->fromCart($this->checkoutSession->getQuote());
    }

    public function current(): ?array
    {
        $tokenParam = $this->request->getParam('token');
        return $this->requests->byToken(is_scalar($tokenParam) ? (string) $tokenParam : '');
    }

    public function items(array $request): array
    {
        return $this->requests->items((int) $request['request_id']);
    }

    public function mine(): array
    {
        return $this->customerSession->isLoggedIn() ? $this->requests->all('', '', (int) $this->customerSession->getCustomerId(), 100) : [];
    }

    public function isOpen(array $request): bool
    {
        return Requests::isOpen($request);
    }

    public function number(array $request): string
    {
        return Requests::number($request);
    }

    public function status(array $request): string
    {
        if ($request['status'] === Requests::QUOTED && !Requests::isOpen($request)) {
            return (string) __('Expired');
        }

        return (string) match ($request['status']) {
            Requests::NEW => __('Waiting for our quote'),
            Requests::QUOTED => __('Quoted'),
            Requests::ORDERED => __('Ordered'),
            Requests::DECLINED => __('Declined'),
            default => $request['status'],
        };
    }

    public function money(?float $amount, ?array $request = null): string
    {
        if ($amount === null) {
            return '';
        }

        return $this->priceCurrency->format($amount, false, 2, null, $request['currency'] ?? null);
    }
}
