# Ecommerce Fulfillment — Livewire

Livewire 4 storefront parcel tracking for
[`liberusoftware/ecommerce-fulfillment`](https://github.com/liberusoftware/module-ecommerce-fulfillment).

"Where is my parcel", for the shopper whose parcel it is.

---

## What it is

One order ships in as many parcels as the goods need, on as many carriers, on as
many days. That cardinality is why the domain module exists — a column on an
order can only ever hold the *first* answer — and it is why this package exists
too: **a single "your order has been sent" line is the wrong shape.** Two of five
items went yesterday, one is packed and waiting, one was called off, one has not
been picked, and the shopper looking at that needs four sentences and a list of
parcels, not one word.

Two components, and no more:

| Component | |
| --- | --- |
| `module-ecommerce-fulfillment::shipments` | Every parcel for one of the shopper's own orders, with progress, evidence and destinations. |
| `module-ecommerce-fulfillment::shipments-page` | The routable page: one `<h1>`, then the above. |

## It is read-only, and that is a domain fact rather than a scope decision

**There is no shopper-caused write anywhere in this domain.** Raising the demand,
recording a parcel, moving one between states and releasing a line are all staff
or host operations, every one of them keyed on an idempotency key the caller
supplies. None of them is a button, and none of them is here.

In particular there is **no cancellation and no void**:

- A parcel that has not left can be called off — by the warehouse that packed it,
  which is the only party that knows whether it has been picked.
- A parcel that *has* left cannot be called off by anybody. It is in the world,
  somebody has it, and the quantity has already been reported outward as
  fulfilled; writing down that goods which physically left never left is how a
  counter ends up disagreeing with a warehouse in the direction that ships free
  stock.
- Goods coming back after that is a **return**, which is a different module
  ([#906](https://github.com/liberusoftware/ecommerce-laravel/issues/906)) and not
  built yet. So when something has been sent, this package says so and names the
  returns route. It does not draw a button that would have to lie about what
  pressing it did.

The one action on the surface, `recheck`, re-reads what the shopper could already
see. A parcel moves when a warehouse says so, which is a different request on a
different day.

## Whose parcels these are

**This is the whole security model, and it needs one line of configuration before
the package shows anybody anything.**

The domain module holds an order id, an order number, a team and a store. It
holds **nothing that names a shopper** — deliberately, because a parcel is a fact
about goods leaving a warehouse and the customer relationship lives on the order.
So this package cannot answer "is this order yours", and it does not guess:

```dotenv
FULFILLMENT_LIVEWIRE_ORDER_MODEL="Liberu\Ecommerce\Orders\Models\Order"
```

That names the model with the public order number and the customer column on it.
It is a **string resolved at call time and never imported**, so this package still
requires only its own domain module. A deployment whose customers are not its
users answers the question in code instead, by rebinding
`Support\ShopperContext`; see [`docs/adoption.md`](docs/adoption.md) §3.

Until it is answered, every read refuses. A closed default is the only safe one
here — an open one would be a package that shows a stranger's parcels to anybody
who has not finished configuring it.

What that buys, and what is asserted in `tests/Feature/IdentityTest.php`:

- The actor is resolved **first**, and the ownership question is not asked without
  one. A signed-out visitor causes no lookup at all.
- An order's row id — which every read in the domain module is keyed on — never
  arrives from a browser. It is what the server resolved from a public number
  *and* an authenticated actor together, or it is not resolved at all.
- Somebody else's order number and a number that was never minted get **the same
  404**, with no distinguishing message. The difference between them is
  information about somebody else's order.
- A signed-out visitor gets the invitation to sign in rather than a 404, so the
  response cannot be used to find out whether an order exists.
- Every public property is `#[Locked]`, nothing is bound to the URL, and the lock
  is the *second* control: a stranger's number resolves to nothing whichever way
  it arrives, including ways a browser does not have.

## The tracking number

It is evidence. It identifies a parcel to a carrier, and anybody holding it can
ask that carrier where a named person's goods are.

The domain module keeps it out of its read model's array form, out of its
telemetry, out of every query it publishes, out of its idempotency hash and off
the model's default serialisation, and reaches for it explicitly at the one
surface that renders it to the person it belongs to. **That surface is this one**,
so:

- it is shown to the shopper who owns the parcel, **once the goods have left** —
  before that it identifies nothing anybody can look up, and putting it on the
  page early costs a screenshot in exchange for nothing;
- it is not a component property, so it never travels to the browser and back as
  state;
- it is not bound to the URL, so it is not in a query string, a browser history,
  an access log or a `Referer` header;
- it is never in the live region, because a live region is read out loud;
- nothing in this package logs.

A link to the carrier's own tracking page is **host configuration**, keyed by the
carrier string the host recorded:

```php
'tracking_urls' => [
    'the-name-your-integration-uses' => 'https://example.test/track/:tracking',
],
```

There is **no carrier name anywhere in this package**, and no default entry. One
that shipped a map would have an opinion about which carriers exist and would
need a release the day a merchant signs with somebody else. A template that is not
an `https` URL with the placeholder in it is ignored rather than rendered, and the
link carries `rel="noreferrer"` — the carrier issued the number, but it has no
business learning the address of the page the shopper came from.

## Progress is counters, never a status

The domain keeps two numbers and the difference between them is the design:

| | May fall | |
| --- | --- | --- |
| `committed` | **yes** | A reservation. Cancelling a parcel that never left puts the goods back on the shelf. |
| `dispatched` | **no** | A fact. It is what was reported outward as fulfilled. |

There is no status on a line of demand, because a line of five can be two gone,
one packed, one called off and one unpicked at the same instant. So the sentence a
shopper reads is built from those numbers and no word in it is a state. The four
state words that *do* appear belong to a parcel, and they are keyed by the
domain's own enum, so a fifth cannot be invented in a translation file.

## Install

The domain module is tagged on GitHub and not on Packagist, so the **host** adds
both repositories to its own `composer.json` — Composer honours `repositories`
only from the root manifest:

```jsonc
"repositories": [
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-fulfillment" },
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-fulfillment-livewire" }
]
```

```bash
composer require liberusoftware/ecommerce-fulfillment-livewire
```

Installing is not enabling. The package ships no `extra.laravel.providers`, so
Composer boots nothing; the host's module manager registers what a deployment
names:

```dotenv
MODULES_ENABLED=ecommerce-fulfillment,ecommerce-fulfillment-livewire
```

## Route

```php
use Liberu\Ecommerce\Fulfillment\Livewire\Pages\ShipmentsPage;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/orders/{number}/parcels', ShipmentsPage::class)->name('storefront.parcels');
});
```

```dotenv
FULFILLMENT_LIVEWIRE_ORDER_ROUTE=storefront.order
FULFILLMENT_LIVEWIRE_RETURNS_ROUTE=storefront.returns
```

An unregistered route name is treated as no link at all, so a deployment that has
not written its returns page yet gets the sentence without a dead `#` href.

Or compose the component directly under an order page you already have:

```blade
<livewire:module-ecommerce-fulfillment::shipments :number="$order->number" />
```

It re-asks the ownership question itself. A parent that has already answered it
does not make this one trust the answer.

## What is not here

- **No cancellation, no void, no "mark as delivered".** See above; the runbook
  covers the one real correction — a dispatch recorded that did not happen.
- **No search, no filter, no sort.** There is nothing to search over on one
  order's parcels, and a search term persists into a query string.
- **No carrier integration.** Rate shopping, labels and transit estimates are
  Shipping ([#915](https://github.com/liberusoftware/ecommerce-laravel/issues/915)).
- **No money.** What carriage cost is the merchant's figure, not a charge to the
  customer; what the customer paid is a line on the order.
- **No listener.** This package renders and changes nothing, so it subscribes to
  nothing. The host wires the domain's dispatch event to the order line counters —
  that listener is in the domain module's own README.

## Documentation

- [`docs/domain.md`](docs/domain.md) — the decisions and the arguments for them.
- [`docs/adoption.md`](docs/adoption.md) — installing, enabling, routing, and the
  ownership question.
- [`docs/runbook.md`](docs/runbook.md) — what to do when the page says something
  surprising.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
