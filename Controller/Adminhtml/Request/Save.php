<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Controller\Adminhtml\Request;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Zero\QuoteRequest\Model\Config;
use Zero\QuoteRequest\Model\Mailer;
use Zero\QuoteRequest\Model\Requests;

/**
 * Answers a request: `do=quote` (a price per line, a note, valid for N
 * days; emailed with the link) or `do=decline` (with the note).
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Zero_QuoteRequest::requests';

    public function __construct(
        Context $context,
        private readonly Requests $requests,
        private readonly Mailer $mailer,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        try {
            $request = $this->requests->get($id) ?? throw new LocalizedException(__('The request no longer exists.'));
            if ($request['status'] === Requests::ORDERED) {
                throw new LocalizedException(__('This request is already ordered.'));
            }
            $answer = trim((string) $this->getRequest()->getParam('answer'));
            if ($this->getRequest()->getParam('do') === 'decline') {
                if (!$this->requests->decline($id, $answer)) {
                    throw new LocalizedException(__('This request is already ordered.'));
                }
                $this->messageManager->addSuccessMessage(__('The request is declined.'));
            } else {
                $prices = [];
                foreach ((array) $this->getRequest()->getParam('price', []) as $itemId => $price) {
                    if (is_numeric($price) && (float) $price >= 0) {
                        $prices[(int) $itemId] = (float) $price;
                    }
                }
                if (!$prices) {
                    throw new LocalizedException(__('Enter a price for at least one line.'));
                }
                $days = (int) $this->getRequest()->getParam('days') ?: $this->config->validDays((int) $request['store_id']);
                // An order placed since the page was opened wins: the request is read again, locked.
                if (!$this->requests->quote($id, $prices, $answer, gmdate('Y-m-d 23:59:59', time() + $days * 86400))) {
                    throw new LocalizedException(__('This request is already ordered.'));
                }
                $this->mailer->quoted($this->requests->get($id));
                $this->messageManager->addSuccessMessage(__('The quote is sent to %1.', $request['email']));
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('zero_quote/request/index', ['id' => $id]);
    }
}
