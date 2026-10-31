<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Controller\View;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\View\Result\PageFactory;
use Zero\QuoteRequest\Model\Requests;

/**
 * /quotes/view?token=...: a request, its status and (once answered) the
 * quote, with Accept. The token is the key: whoever has the link sees it.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly RequestInterface $request,
        private readonly Requests $requests
    ) {
    }

    public function execute()
    {
        $tokenParam = $this->request->getParam('token');
        $quoteRequest = $this->requests->byToken(is_scalar($tokenParam) ? (string) $tokenParam : '');
        if (!$quoteRequest) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Quote %1', Requests::number($quoteRequest)));
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');

        return $page;
    }
}
