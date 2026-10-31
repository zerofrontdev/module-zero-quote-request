<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Controller\View;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Zero\QuoteRequest\Model\Accept as AcceptQuote;
use Zero\QuoteRequest\Model\Requests;

/**
 * Accept a quote (form key checked by core's CSRF validation): the cart
 * becomes its lines at the quoted prices; on to the cart.
 */
class Accept implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Requests $requests,
        private readonly AcceptQuote $accept,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute()
    {
        $token = $this->request->getParam('token');
        $token = is_scalar($token) ? (string) $token : '';
        $quoteRequest = $this->requests->byToken($token);
        try {
            if (!$quoteRequest) {
                throw new LocalizedException(__('This quote does not exist.'));
            }
            $this->accept->execute($quoteRequest);
            $this->messageManager->addSuccessMessage(__('Your cart now holds quote %1, at its prices.', Requests::number($quoteRequest)));

            return $this->redirectFactory->create()->setPath('checkout/cart');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->redirectFactory->create()->setPath('quotes/view', ['_query' => ['token' => $token]]);
    }
}
