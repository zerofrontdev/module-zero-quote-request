<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Controller\Request;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Validator\EmailAddress;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Core\Model\Captcha\Check as CaptchaCheck;
use Zero\QuoteRequest\Model\Config;
use Zero\QuoteRequest\Model\Lines;
use Zero\QuoteRequest\Model\Mailer;
use Zero\QuoteRequest\Model\Requests;

/**
 * A quote request is sent (form key checked by core's CSRF validation):
 * `source=cart` (the cart's lines; then to the request's page) or
 * `source=product` (the product form's fields, with HTMX; answered with a
 * "thanks" in the form's place). The store and the customer get an email.
 */
class Save implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Lines $lines,
        private readonly Requests $requests,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly RedirectFactory $redirectFactory,
        private readonly RawFactory $rawFactory,
        private readonly LayoutInterface $layout,
        private readonly ManagerInterface $messageManager,
        private readonly CaptchaCheck $captchaCheck
    ) {
    }

    public function execute()
    {
        $fromProduct = $this->request->getParam('source') === 'product';
        $store = $this->storeManager->getStore();
        try {
            if (!$this->config->isEnabled((int) $store->getId())) {
                throw new LocalizedException(__('Quote requests are off.'));
            }
            $this->captchaCheck->verify($this->request, 'zero_quote_request');
            $name = $this->request->getParam('name');
            $name = trim(is_scalar($name) ? (string) $name : '');
            $email = $this->request->getParam('email');
            $email = trim(is_scalar($email) ? (string) $email : '');
            if ($name === '' || !(new EmailAddress())->isValid($email)) {
                throw new LocalizedException(__('Enter your name and a valid email.'));
            }
            if ($fromProduct) {
                $product = $this->productRepository->getById((int) $this->request->getParam('product'), false, (int) $store->getId());
                $lines = $this->lines->fromProduct($product, $this->request->getParams());
            } else {
                $lines = $this->lines->fromCart($this->checkoutSession->getQuote());
            }
            if (!$lines) {
                throw new LocalizedException(__('There is nothing to ask a quote for.'));
            }
            [$phone, $company, $comment] = array_map(
                static fn ($value) => trim(is_scalar($value) ? (string) $value : ''),
                [$this->request->getParam('phone'), $this->request->getParam('company'), $this->request->getParam('comment')]
            );
            $quoteRequest = $this->requests->create([
                'store_id' => (int) $store->getId(),
                'customer_id' => $this->customerSession->isLoggedIn() ? (int) $this->customerSession->getCustomerId() : null,
                'name' => mb_substr($name, 0, 255),
                'email' => $email,
                'phone' => mb_substr($phone, 0, 64),
                'company' => mb_substr($company, 0, 255),
                'comment' => mb_substr($comment, 0, 4000),
                'currency' => (string) $store->getCurrentCurrencyCode(),
            ], $lines);
            $this->mailer->newRequest($quoteRequest);
        } catch (LocalizedException $e) {
            return $fromProduct ? $this->fragment(['error' => $e->getMessage()]) : $this->back($e->getMessage());
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            return $fromProduct ? $this->fragment(['error' => (string) __('This product is no longer available.')]) : $this->back('');
        }

        if ($fromProduct) {
            return $this->fragment(['sent' => $quoteRequest]);
        }
        $this->messageManager->addSuccessMessage(__('Your request %1 is sent. We will email you the quote.', Requests::number($quoteRequest)));

        return $this->redirectFactory->create()->setPath('quotes/view', ['_query' => ['token' => $quoteRequest['token']]]);
    }

    private function fragment(array $data)
    {
        $html = $this->layout->createBlock(\Magento\Framework\View\Element\Template::class)
            ->setTemplate('Zero_QuoteRequest::product-sent.phtml')
            ->addData($data)
            ->toHtml();

        return $this->rawFactory->create()->setHeader('Cache-Control', 'no-store', true)->setContents($html);
    }

    private function back(string $message)
    {
        if ($message !== '') {
            $this->messageManager->addErrorMessage($message);
        }

        return $this->redirectFactory->create()->setPath('quotes/request');
    }
}
