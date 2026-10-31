<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Plugin;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Psr\Log\LoggerInterface;
use Zero\QuoteRequest\Model\OrderGuard;
use Zero\QuoteRequest\Model\Requests;

/**
 * An accepted quote can be ordered once: the order being placed claims every
 * quote its cart's lines come from (checked again, then one conditional
 * UPDATE each), before Magento places it, the one point the theme, GraphQL,
 * REST and the admin share. All or none: a lost claim releases the ones
 * already taken and refuses the order; a failed placement releases them all.
 * Wraps outside Zero_NetTerms (sortOrder 10 < 20), so a refused quote never
 * charges a company's credit.
 */
class ClaimQuoteOnPlace
{
    public function __construct(
        private readonly CartRepositoryInterface $carts,
        private readonly OrderGuard $guard,
        private readonly Requests $requests,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws LocalizedException When a quote in the cart can't be ordered
     */
    public function aroundPlace(OrderManagementInterface $subject, callable $proceed, OrderInterface $order): OrderInterface
    {
        $quoteId = (int) $order->getQuoteId();
        $quote = $this->quote($quoteId);
        $ids = $quote ? $this->guard->requestIds($quote) : [];
        if (!$ids) {
            return $proceed($order);
        }
        try {
            $this->guard->check($quote);
        } finally {
            // check() remembers the cart again; a long-running process must not keep every cart it placed.
            $this->guard->forget($quoteId);
        }
        $incrementId = (string) $order->getIncrementId();
        $claimed = [];
        try {
            foreach ($ids as $requestId) {
                if (!$this->requests->claim($requestId, $incrementId)) {
                    throw new LocalizedException(__(
                        'Quote %1 has already been ordered. Remove its lines from your cart to check out.',
                        Requests::number(['request_id' => $requestId])
                    ));
                }
                $claimed[] = $requestId;
            }

            return $proceed($order);
        } catch (\Throwable $e) {
            // A lost or failed claim, or a failed placement: every claim this order took goes back.
            $this->release($claimed, $incrementId);
            throw $e;
        }
    }

    /**
     * The cart being placed: the instance checkout_submit_before checked, or
     * the repository's (its cached instance in the same request).
     */
    private function quote(int $quoteId): ?Quote
    {
        if ($quoteId <= 0) {
            return null;
        }
        $quote = $this->guard->checked($quoteId);
        $this->guard->forget($quoteId);
        if ($quote !== null) {
            return $quote;
        }
        try {
            $quote = $this->carts->get($quoteId);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $quote instanceof Quote ? $quote : null;
    }

    /**
     * @param int[] $requestIds
     */
    private function release(array $requestIds, string $incrementId): void
    {
        foreach ($requestIds as $requestId) {
            try {
                $this->requests->release($requestId, $incrementId);
            } catch (\Throwable $e) {
                $this->logger->critical(sprintf(
                    'Zero_QuoteRequest: order %s was not placed and quote request %d stays claimed: %s',
                    $incrementId,
                    $requestId,
                    $e->getMessage()
                ));
            }
        }
    }
}
