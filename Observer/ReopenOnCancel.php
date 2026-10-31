<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zero\QuoteRequest\Model\Requests;

/**
 * order_cancel_after: a cancelled order did not consume its quote, so the
 * request it claimed is quoted again (if not past its date).
 */
class ReopenOnCancel implements ObserverInterface
{
    public function __construct(private readonly Requests $requests)
    {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if ($order) {
            $this->requests->reopen((string) $order->getIncrementId());
        }
    }
}
