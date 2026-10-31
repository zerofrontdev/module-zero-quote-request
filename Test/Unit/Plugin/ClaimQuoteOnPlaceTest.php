<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Test\Unit\Plugin;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\QuoteRequest\Model\OrderGuard;
use Zero\QuoteRequest\Model\Requests;
use Zero\QuoteRequest\Plugin\ClaimQuoteOnPlace;

/**
 * place(): every quote the cart's lines come from is checked again and
 * claimed before Magento places the order. All or none: a lost claim, a
 * claim that throws or a failed placement releases what this order took
 * and rethrows; a failed release is logged. The cart checked at
 * checkout_submit_before is used (no second load) and then forgotten.
 */
class ClaimQuoteOnPlaceTest extends TestCase
{
    /** @var list<string> check / claim / release / proceed / critical / forget, in order */
    private array $log = [];
    /** @var list<int> request ids the cart's lines come from */
    private array $ids = [12, 15];
    /** @var array<int, bool|\Throwable> claim() answer per request id (default true) */
    private array $claims = [];
    private ?\Throwable $checkFails = null;
    private ?\Throwable $releaseFails = null;
    private ?Quote $checked = null;
    private Quote $quote;
    /** @var list<int> CartRepositoryInterface::get() calls */
    private array $loaded = [];
    private bool $cartMissing = false;

    protected function setUp(): void
    {
        $this->quote = $this->createStub(Quote::class);
    }

    public function testClaimsBeforePlacing(): void
    {
        $order = $this->order();

        $placed = $this->plugin()->aroundPlace($this->subject(), $this->proceed(), $order);

        self::assertSame($order, $placed);
        self::assertSame(['forget 77', 'check', 'forget 77', 'claim 12 100000042', 'claim 15 100000042', 'proceed'], $this->log);
    }

    public function testALostClaimRefusesWithoutPlacing(): void
    {
        $this->ids = [12];
        $this->claims[12] = false;

        $this->assertRefused('Quote Q000012 has already been ordered. Remove its lines from your cart to check out.');
        self::assertSame(['claim 12 100000042'], $this->only('claim', 'release', 'proceed'));
    }

    public function testWhenTheSecondOfTwoQuotesIsTakenTheFirstIsReleased(): void
    {
        $this->claims[15] = false;

        $this->assertRefused('Quote Q000015 has already been ordered. Remove its lines from your cart to check out.');
        self::assertSame(['claim 12 100000042', 'claim 15 100000042', 'release 12 100000042'], $this->only('claim', 'release', 'proceed'));
    }

    public function testAClaimThatThrowsReleasesTheOnesTakenAndRethrows(): void
    {
        $failure = new \Zend_Db_Statement_Exception('Deadlock found when trying to get lock');
        $this->claims[15] = $failure;

        try {
            $this->plugin()->aroundPlace($this->subject(), $this->proceed(), $this->order());
            self::fail('the claim error must propagate');
        } catch (\Zend_Db_Statement_Exception $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame(['claim 12 100000042', 'claim 15 100000042', 'release 12 100000042'], $this->only('claim', 'release', 'proceed'));
    }

    public function testAFailedPlacementReleasesTheClaim(): void
    {
        $failure = new \RuntimeException('The NetTerms credit is short');

        try {
            $this->plugin()->aroundPlace($this->subject(), $this->proceed($failure), $this->order());
            self::fail('the placement error must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame(
            ['claim 12 100000042', 'claim 15 100000042', 'proceed', 'release 12 100000042', 'release 15 100000042'],
            $this->only('claim', 'release', 'proceed')
        );
    }

    public function testAFailedReleaseIsLoggedAndTheOriginalErrorStands(): void
    {
        $failure = new \RuntimeException('Placing failed');
        $this->releaseFails = new \RuntimeException('Lock wait timeout');

        try {
            $this->plugin()->aroundPlace($this->subject(), $this->proceed($failure), $this->order());
            self::fail('the placement error must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame([
            'critical Zero_QuoteRequest: order 100000042 was not placed and quote request 12 stays claimed: Lock wait timeout',
            'critical Zero_QuoteRequest: order 100000042 was not placed and quote request 15 stays claimed: Lock wait timeout',
        ], $this->only('critical'));
    }

    public function testARefusedCheckClaimsNothingAndTheCartIsForgotten(): void
    {
        $this->checkFails = new LocalizedException(__('Quote Q000012 has changed since you accepted it. Accept it again to check out.'));

        $this->assertRefused('Quote Q000012 has changed since you accepted it. Accept it again to check out.');
        self::assertSame([], $this->only('claim', 'release', 'proceed'));
        self::assertSame('forget 77', $this->log[array_key_last($this->log)], 'forgotten even when the check refuses');
    }

    public function testOrdersWithoutAQuotePassThrough(): void
    {
        $this->ids = [];
        $this->plugin()->aroundPlace($this->subject(), $this->proceed(), $this->order());

        $this->plugin()->aroundPlace($this->subject(), $this->proceed(), $this->order(0));

        $this->cartMissing = true;
        $this->plugin()->aroundPlace($this->subject(), $this->proceed(), $this->order());

        self::assertSame(['proceed', 'proceed', 'proceed'], $this->only('claim', 'check', 'proceed'));
        self::assertSame([77, 77], $this->loaded, 'quote id 0 is never loaded');
    }

    public function testTheCartCheckedBeforeSubmitIsUsedWithoutASecondLoad(): void
    {
        $this->checked = $this->quote;

        $this->plugin()->aroundPlace($this->subject(), $this->proceed(), $this->order());

        self::assertSame([], $this->loaded);
        self::assertContains('claim 12 100000042', $this->log);
    }

    public function testWithoutACheckedCartTheRepositorysIsLoadedOnce(): void
    {
        $this->plugin()->aroundPlace($this->subject(), $this->proceed(), $this->order());

        self::assertSame([77], $this->loaded);
    }

    private function assertRefused(string $message): void
    {
        try {
            $this->plugin()->aroundPlace($this->subject(), $this->proceed(), $this->order());
            self::fail('refused: ' . $message);
        } catch (LocalizedException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    private function plugin(): ClaimQuoteOnPlace
    {
        $carts = $this->createStub(CartRepositoryInterface::class);
        $carts->method('get')->willReturnCallback(function (int $id) {
            $this->loaded[] = $id;
            if ($this->cartMissing) {
                throw NoSuchEntityException::singleField('cartId', $id);
            }

            return $this->quote;
        });
        $guard = $this->createStub(OrderGuard::class);
        $guard->method('checked')->willReturnCallback(fn () => $this->checked);
        $guard->method('forget')->willReturnCallback(function (int $id): void {
            $this->log[] = "forget $id";
        });
        $guard->method('requestIds')->willReturnCallback(fn () => $this->ids);
        $guard->method('check')->willReturnCallback(function (): void {
            $this->log[] = 'check';
            if ($this->checkFails) {
                throw $this->checkFails;
            }
        });
        $requests = $this->createStub(Requests::class);
        $requests->method('claim')->willReturnCallback(function (int $id, string $incrementId) {
            $this->log[] = "claim $id $incrementId";
            $answer = $this->claims[$id] ?? true;
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        });
        $requests->method('release')->willReturnCallback(function (int $id, string $incrementId): void {
            $this->log[] = "release $id $incrementId";
            if ($this->releaseFails) {
                throw $this->releaseFails;
            }
        });
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('critical')->willReturnCallback(function ($message): void {
            $this->log[] = 'critical ' . $message;
        });

        return new ClaimQuoteOnPlace($carts, $guard, $requests, $logger);
    }

    private function proceed(?\Throwable $failure = null): callable
    {
        return function (OrderInterface $order) use ($failure): OrderInterface {
            $this->log[] = 'proceed';
            if ($failure) {
                throw $failure;
            }

            return $order;
        };
    }

    private function order(int $quoteId = 77): OrderInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getQuoteId')->willReturn($quoteId);
        $order->method('getIncrementId')->willReturn('100000042');

        return $order;
    }

    private function subject(): OrderManagementInterface
    {
        return $this->createStub(OrderManagementInterface::class);
    }

    /** @return list<string> the log entries of these kinds */
    private function only(string ...$kinds): array
    {
        return array_values(array_filter($this->log, static fn (string $entry) => in_array(strtok($entry, ' '), $kinds, true)));
    }
}
