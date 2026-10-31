# Changelog: zerofront/module-zero-quote-request

## 1.3.6

- An accepted quote is ordered once: the order claims the quote when it is placed (from the storefront, REST or the admin), so of two carts placing at once only one wins and the other is told the quote has already been ordered.
- The order is refused, before gift cards or points are spent, when the quote was ordered, declined or expired, belongs to another store or currency, the cart ships to several addresses, or a line's price differs from the quote.
- If placing fails, the claim is released; a cancelled order, or an unpaid redirect order whose cart is restored, makes the quote available again; an ordered request can't be answered again in the admin.
- Accepting a quote works only in the currency it was priced in: switched to another, the customer is asked to switch back.
- On a Price on Request product page only Add to Cart and its quantity are swapped for the request form; Add to Wish List and Compare stay. A theme that ignores `main_control_html` still gets the whole block replaced, as before.
- Product cards, widgets, the wish list, the Recently Ordered sidebar and Bought Together link such products to their request form instead of offering a direct add (`zero.quote.card` block).
- `setup:upgrade` adds an index on `zero_quote_request.order_increment_id`.

Earlier releases (1.3.5 and before): see the Git tags.
