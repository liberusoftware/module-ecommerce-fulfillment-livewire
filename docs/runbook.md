# Runbook

What to do when this surface says something surprising. Everything here is a read
problem or a configuration problem: this package writes nothing, so it cannot be
the cause of a wrong number — only of a wrong sentence about one.

---

## Every shopper gets a 404, including for their own orders

The ownership question has not been answered. That is the shipped default, and it
is closed rather than open on purpose.

```bash
php artisan tinker
>>> config('fulfillment-livewire.order.model');
```

Null means no deployment ever set `FULFILLMENT_LIVEWIRE_ORDER_MODEL`. See
[`adoption.md`](adoption.md) §3.

If it is set, check the three things in order:

```php
$context = app(\Liberu\Ecommerce\Fulfillment\Livewire\Support\ShopperContext::class);
$context->orderIdFor('ORD-...', $customerId);   // null is the refusal
```

1. Does the class exist and is it an Eloquent model? Anything else is refused
   rather than raised, so a typo looks exactly like "not yours".
2. Are `FULFILLMENT_LIVEWIRE_ORDER_NUMBER_COLUMN` and
   `..._CUSTOMER_COLUMN` the actual column names on that model?
3. Is the value in the URL the order's **public number** and not its id? The
   package looks orders up by number deliberately.

## One shopper gets a 404 and everybody else is fine

Almost always the correct answer: the number is not theirs. This surface answers a
stranger's number and a number that was never minted identically, and it does so
on purpose.

Before assuming a bug, check who the order is actually filed against:

```php
Order::query()->where('number', $number)->value('customer_id');
```

If the order genuinely belongs to the account and the page still refuses, the
customer id the storefront signs people in as is not the one the orders table
records. That is the case `ShopperContext` exists to be overridden for.

## The page says "has not reached the warehouse yet" and the goods have shipped

The order has no fulfillment request. Something recorded a dispatch outside the
domain module, or the request was never raised.

```php
app(\Liberu\Ecommerce\Fulfillment\Queries\FulfillmentQuery::class)->forOrder($orderId);
```

Null there means this module was never asked for anything. Raising the request is
the host's job — see the domain module's runbook — and this page will show it as
soon as it exists.

## The counts look wrong

Nothing on this page computes a count. Every number comes from the domain's read
model, including both derived ones, so a wrong number here is a wrong number
there:

```
remaining     = quantity − committed − cancelled
packed        = committed − dispatched
```

Take it to the domain module's runbook. The one thing worth checking first is
which of the two counters moved: `committed` is a reservation and is *allowed* to
fall when a parcel that never left is cancelled; `dispatched` is a fact and a fall
in it is a data problem, not a workflow.

## A shopper wants a dispatched parcel cancelled

There is no such operation, in this package or in the domain module, and adding a
button is not the fix. The parcel is in the world and the quantity has already
been reported outward as fulfilled.

Two real situations, and neither is a cancellation:

- **It arrives and they want to send it back.** A return. Point
  `FULFILLMENT_LIVEWIRE_RETURNS_ROUTE` at your returns page — the sentence is
  already on the page, it is only the link that is missing.
- **It never actually left.** Then it was never dispatched, and what happened is a
  mis-recorded dispatch. That is a data correction made by whoever made the
  mistake, under the domain module's runbook, not a domain operation anybody
  publishes an action for.

## The tracking number is not showing

In order:

1. **Has the parcel left?** It is deliberately absent until the state says the
   goods have gone. Before then it identifies nothing a carrier can answer for.
2. **Did the host record one?** It is optional on the domain's input, and a retry
   that learned the number later does not overwrite the first call's value — that
   trade-off is in the domain module's own docs.

## The tracking link is not showing, but the number is

The template is missing or was rejected. It must be keyed by the **exact** carrier
string on the parcel, must start with `https://`, and must contain `:tracking`.

```php
$parcel = app(\Liberu\Ecommerce\Fulfillment\Queries\FulfillmentQuery::class)->byReference('SHP-...');
$parcel->carrier;                                  // the key to use
config('fulfillment-livewire.tracking_urls');      // what is configured
```

A rejected template is silent by design: a link that goes somewhere unexpected is
worse than no link.

## A parcel shows a destination that is not the order's address

Working as intended, and worth leaving visible. A shipment carries **its own**
destination; rerouting one box to a neighbour, a work address or a locker changes
where that box goes and rewrites nothing else. A second parcel on the same order
may go somewhere different again.

## Nothing appears at all where the component is composed

Check, in order:

1. `MODULES_ENABLED` names **both** modules.
2. The component is addressed by its full alias,
   `module-ecommerce-fulfillment::shipments`. This package registers a missing
   component resolver rather than a namespace map, because it has two class
   namespaces behind one Livewire namespace.
3. The `number` passed in is the order's public number.

## Someone reports parcel data in a log

This package logs nothing. If a tracking number or a destination is in a log line,
it came from somewhere else — check the domain module's telemetry setting first
(`FULFILLMENT_TELEMETRY`), which is off by default and which excludes both by
design, then anything in the host that serialises a read model.

Note that the domain's read model leaves the tracking number out of its array
form for exactly this reason, so a log line containing one was written by
something that reached for the property deliberately.
