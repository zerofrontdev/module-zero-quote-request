<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Zero\QuoteRequest\Observer\GuardQuoteOnly;

/**
 * Whether a cart's quote lines can still be ordered. The lines carrying the
 * zero_quote_request option are the source of truth (not
 * quote.zero_quote_request_id): for each request they name, the request must
 * be open, in this store and currency, shipped to one address, and every line
 * still at its quoted price. Read-only; the claim is Plugin\ClaimQuoteOnPlace.
 */
class OrderGuard
{
    private const EPSILON = 0.0001;

    /** @var array<int, Quote> The carts checked in this request, by id */
    private array $checked = [];

    public function __construct(
        private readonly Requests $requests,
        private readonly Json $json
    ) {
    }

    /**
     * @return list<int> The quote requests the cart's lines come from
     */
    public function requestIds(Quote $quote): array
    {
        return array_keys($this->lines($quote));
    }

    /**
     * @throws LocalizedException When a quote in the cart can't be ordered
     */
    public function check(Quote $quote): void
    {
        if ($quote->getId()) {
            $this->checked[(int) $quote->getId()] = $quote;
        }
        foreach ($this->lines($quote) as $requestId => $lines) {
            $request = $this->requests->get($requestId);
            $number = Requests::number($request ?? ['request_id' => $requestId]);
            if ($request !== null && $request['status'] === Requests::ORDERED) {
                throw new LocalizedException(__('Quote %1 has already been ordered. Remove its lines from your cart to check out.', $number));
            }
            if (!Requests::isOpen($request)) {
                throw new LocalizedException(__('Quote %1 can no longer be ordered. Remove its lines from your cart to check out.', $number));
            }
            if ((int) $request['store_id'] !== (int) $quote->getStoreId()) {
                throw new LocalizedException(__('Open this quote in the store it was made for.'));
            }
            if ($quote->getData('is_multi_shipping')) {
                throw new LocalizedException(__('Quote %1 ships to one address: check out with one shipping address.', $number));
            }
            $currency = (string) ($request['currency'] ?? '');
            if ($currency !== '' && $currency !== $this->currency($quote)) {
                throw new LocalizedException(__('Quote %1 is in %2. Switch the store\'s currency to %2 to order it.', $number, $currency));
            }
            $quoted = [];
            foreach ($this->requests->items($requestId) as $row) {
                $quoted[(int) $row['item_id']] = $row['quoted_price'];
            }
            foreach ($lines as [$item, $line]) {
                $price = $quoted[(int) ($line['item_id'] ?? 0)] ?? null;
                if ($price === null || abs((float) $item->getData('original_custom_price') - (float) $price) > self::EPSILON) {
                    throw new LocalizedException(__('Quote %1 has changed since you accepted it. Accept it again to check out.', $number));
                }
            }
        }
    }

    /**
     * The cart instance check() saw in this request, so the claim at place()
     * works on the cart being placed without loading it again.
     */
    public function checked(int $quoteId): ?Quote
    {
        return $this->checked[$quoteId] ?? null;
    }

    /** Drops the remembered cart (once the claim has it). */
    public function forget(int $quoteId): void
    {
        unset($this->checked[$quoteId]);
    }

    /**
     * @return array<int, list<array{0: Quote\Item, 1: array}>> By request id: the cart line and its option
     */
    private function lines(Quote $quote): array
    {
        $byRequest = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $option = $item->getOptionByCode(GuardQuoteOnly::OPTION);
            if (!$option) {
                continue;
            }
            try {
                $line = $this->json->unserialize((string) $option->getValue());
            } catch (\InvalidArgumentException) {
                continue;
            }
            $requestId = is_array($line) ? (int) ($line['request_id'] ?? 0) : 0;
            if ($requestId > 0) {
                $byRequest[$requestId][] = [$item, $line];
            }
        }

        return $byRequest;
    }

    private function currency(Quote $quote): string
    {
        return (string) ($quote->getData('quote_currency_code') ?: $quote->getStore()->getCurrentCurrencyCode());
    }
}
