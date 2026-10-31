# Zero_QuoteRequest

Request a quote, and price on request.

- **Price on Request** (a product attribute, Catalog > Products): the price
  is hidden wherever Magento shows it (product page, listings, widgets; not in
  structured data, analytics or reward points either), and Add to Cart
  becomes "Ask us for a price": quantity, options, name, email, a comment,
  sent with HTMX. Only Add to Cart and its qty are swapped (simple, virtual,
  downloadable, grouped, configurable, bundle): the plugin
  (`Plugin\RequestInsteadOfCart`) hands the form to the add-to-cart block as
  `main_control_html`, and the Zero theme's `addtocart.phtml` keeps Add to
  Wish List and Compare next to it (no shortcut buttons such as PayPal, no
  requisition list). A theme whose template ignores `main_control_html`
  gets the whole block replaced by the form. Such a product reaches the
  cart only through an accepted quote. On product cards (category/search
  list, related/upsell/cross-sell, catalog and recently viewed/compared
  widgets, compare, wish list) the
  Zero theme shows "Request a Price", a link to that form
  (`#zero-quote-product`), instead of Add to Cart: the `zero.quote.card`
  block (`ViewModel\Card`, same rule as the cart's guard), rendered per card
  as the badges. Bought Together lists it with "Choose options". The wish
  list's "Add All to Cart" says that such items are not added (the cart
  refuses them, they stay in the list) and is hidden when every salable
  item is one; the "Recently Ordered" sidebar gives them "Request a Price"
  instead of a checkbox (`Card::quoteOnlyIds()`), and the demo stores'
  product components the same instead of their direct add.
- **From the cart**: "Buying in bulk? Request a quote" under the checkout
  button (Stores > Configuration > Zero > Quote Requests to hide it) opens
  `/quotes/request`: the cart's lines, your details, a comment.
- **Answering** (Sales > Quote Requests): a price per line (in the currency
  asked in), a note, valid for N days; **Send Quote** emails the customer the
  link, or **Decline**.
- **Accepting**: the link (`/quotes/view?token=...`, also in My Account > My
  Quotes) shows the quote; **Accept and Check Out** makes the cart the
  quote's lines at its prices and quantities (they stay so). Only in the
  currency it was priced in: switched to another, accepting asks to switch
  back.
- **Ordered once**: the order placed from the cart claims the quote (an
  around plugin on `OrderManagementInterface::place()`, so the theme,
  GraphQL, REST and the admin alike): one conditional UPDATE, so of two carts
  placing at once exactly one wins and the other is told "already been
  ordered". The cart's quote lines decide which quotes (merged carts: all of
  them or none). Before anything is spent on the order (gift cards, points,
  at `checkout_submit_before`) and again at placing, the order is refused when
  the quote is ordered, declined, expired, in another store or currency, the
  cart ships to several addresses, or a line's price differs from the quote
  (accept it again). If placing fails, the claim is released. A cancelled
  order quotes it again (if not past its date), and so does a still-unpaid
  redirect order whose cart is restored (Zero_Checkout's
  `zero_checkout_quote_restored`, matched by the order's number). A paid order
  keeps it. An ordered request can't be answered again in the admin.
  Accepted risk: a restored order stays pending; if its payment still lands
  late after another order has claimed the quote again, the quote is ordered
  twice (nothing flags it; check the request's order against the paid one).
  MSI's reservation plugin wraps outside the claim, so a refused order leaves
  a reservation and its compensation row (net zero) in `inventory_reservation`.
- **Emails**: a new request to the store (New Requests Go To), "we got it"
  and "your quote" to the customer (templates under Marketing > Email
  Templates).

Statuses: new, quoted (until its date), ordered, declined. Tables:
`zero_quote_request`, `zero_quote_request_item`; `quote.zero_quote_request_id`.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-quote-request
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-core`, `zerofront/module-zero-seo` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## Tests

`dev/tests/zero-e2e/tests/quote-request.spec.ts` (`ZERO_QUOTE_ONLY_PRODUCT`),
`quote-request-admin.spec.ts` (ask, quote, accept, order).

## Disabling or removing

Its data lives in its own tables (`zero_quote_request`, `zero_quote_request_item`). With the module's code still in
place, `bin/magento module:disable Zero_QuoteRequest` followed by `setup:upgrade`
**drops those tables** (Magento's declarative schema), and enabling the module
again brings them back empty. To keep the data, switch the feature off in
Stores > Configuration where it has a setting, or back up first:
`bin/magento setup:upgrade --safe-mode=1` dumps the data before anything is
dropped (`--data-restore=1` puts it back). Deleting the module's code instead
leaves the tables in place.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
