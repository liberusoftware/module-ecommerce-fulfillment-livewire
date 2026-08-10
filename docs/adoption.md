# Adoption

Installing this package, enabling it, routing it, and the one decision a
deployment has to make before it shows anybody anything.

---

## 1. Install

The domain module this package presents is tagged on GitHub and not published on
Packagist, so a composition adds both repositories to **its own**
`composer.json`. Composer honours `repositories` only from the root manifest, so
this package declaring one does not help you — it works only for this package's
own CI, where this package is root.

```jsonc
"repositories": [
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-fulfillment" },
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-fulfillment-livewire" }
]
```

```bash
composer require liberusoftware/ecommerce-fulfillment-livewire
```

## 2. Enable

Installing is not enabling. The package ships no `extra.laravel.providers`, so
Composer boots nothing. The host's `ModuleManagerServiceProvider` globs
`config('modules.paths')` for `*/module.json` and registers only the modules named
in `MODULES_ENABLED`:

```dotenv
MODULES_ENABLED=ecommerce-fulfillment,ecommerce-fulfillment-livewire
```

Both. This package presents the domain module; enabling it alone gives you
components with no tables behind them.

## 3. Answer the ownership question

**Nothing works until this is done, and that is deliberate.**

The domain module records a parcel against an order id, an order number, a team
and a store. It records **nothing that names a shopper**. So this package cannot
tell whether an order is yours, and it does not guess — it asks the deployment,
and refuses every read until the deployment has answered.

### The ordinary answer: name the model

```dotenv
FULFILLMENT_LIVEWIRE_ORDER_MODEL="Liberu\Ecommerce\Orders\Models\Order"
```

That is the fleet's own orders module. Any Eloquent class works, as long as it has
a column carrying the order's **public number** and a column carrying the customer
it is filed against:

```dotenv
FULFILLMENT_LIVEWIRE_ORDER_NUMBER_COLUMN=number
FULFILLMENT_LIVEWIRE_ORDER_CUSTOMER_COLUMN=customer_id
```

The class is a string resolved at call time and is never imported by this package,
which is what keeps it installable into an application whose orders live somewhere
else.

**Use the public number, never the primary key.** An incrementing id in a
customer-facing URL is an enumeration of everybody else's orders, which is the
reason an order has a number at all.

### The other answer: rebind the context

A deployment whose customers are not its users — a CRM contact keyed separately
from the login, an ownership rule with a household or an organisation in it —
answers in code. In a service provider:

```php
use Liberu\Ecommerce\Fulfillment\Livewire\Support\ShopperContext;

$this->app->singleton(ShopperContext::class, fn () => new class extends ShopperContext
{
    public function customerId(): ?int
    {
        return Auth::user()?->contact_id;
    }

    public function orderIdFor(string $orderNumber, int $customerId): ?int
    {
        return Order::query()
            ->where('number', $orderNumber)
            ->where('contact_id', $customerId)   // scope on the actor, always
            ->value('id');
    }
});
```

Every read in the package follows, because nothing else here asks who the shopper
is.

Two rules for an override:

1. **Use the customer id you were handed.** It is a parameter rather than
   something the method looks up so that scoping on the actor is impossible to
   forget without deleting an argument.
2. **Return null for "not theirs" and for "does not exist" alike.** The caller
   turns both into the same 404. Distinguishing them tells a caller which order
   numbers exist.

You cannot leak to a signed-out visitor by getting this wrong: the actor is
resolved first and your method is not called at all without one.

## 4. Route

```php
use Liberu\Ecommerce\Fulfillment\Livewire\Pages\ShipmentsPage;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/orders/{number}/parcels', ShipmentsPage::class)->name('storefront.parcels');
});
```

Then tell the package the names of the routes it links to:

```dotenv
FULFILLMENT_LIVEWIRE_ORDER_ROUTE=storefront.order
FULFILLMENT_LIVEWIRE_RETURNS_ROUTE=storefront.returns
```

An unregistered name is treated as no link at all, so a deployment that has not
written its returns page yet gets the sentence without a dead `#` href.

### `auth` on the route

The page works without it — a signed-out visitor is shown an invitation to sign in
and no query runs — but a shopper following a link from a dispatch email after
their session expired would rather be sent to the sign-in form than to a page
telling them to find one.

### Or compose the component

```blade
<livewire:module-ecommerce-fulfillment::shipments :number="$order->number" />
```

It re-asks the ownership question itself. A parent that has already answered it
does not make this one trust the answer.

## 5. Carrier tracking links

Publish the config and fill in the map, keyed by whatever your own integration
calls each carrier — which is the same string it recorded on the parcel:

```bash
php artisan vendor:publish --tag=module-ecommerce-fulfillment-config
```

```php
'tracking_urls' => [
    'your-carrier-key' => 'https://track.example.test/:tracking',
],
```

There is no default map. A package that shipped one would have an opinion about
which carriers exist, and would need a release the day you signed with somebody
else. A template that is not an `https` URL containing `:tracking` is ignored, so a
typo costs a link rather than producing one that goes somewhere unexpected.

## 6. Themes and wording

```bash
php artisan vendor:publish --tag=module-ecommerce-fulfillment-views
php artisan vendor:publish --tag=module-ecommerce-fulfillment-translations
```

The views ship structure and labels and no styling. A published copy owns the
classes and the layout; what it must not drop is the accessible plumbing — the
live region, the button's text, the `wire:key`s and the `<time datetime>` — which
is behaviour rather than decoration.

Three rules a published view must keep, each asserted in this package's suite:

1. **The only state word is the parcel's**, keyed by the domain's enum. A fifth
   one would be this package publishing a state the domain refused.
2. **Nothing writes.** There is no cancel control, because there is no such
   operation in this domain.
3. **The tracking number stays on the page.** Not in a link to your own site, not
   in a query string, not in the live region.

## 7. What this package does not wire for you

**The dispatch listener.** The domain module publishes `ShipmentDispatched`
carrying order line ids and quantities, and the **host** subscribes to it and
calls the orders module's counter action. That listener is in the domain module's
own README and adoption guide. This package renders and changes nothing, so it
subscribes to nothing — a presentation package reacting to a domain event would be
a second place that decision lives.

**Returns.** There is no returns module yet
([#906](https://github.com/liberusoftware/ecommerce-laravel/issues/906)). Until
there is, point `FULFILLMENT_LIVEWIRE_RETURNS_ROUTE` at whatever page your support
team wants a shopper to land on.

## 8. Upgrading

`0.1.0` is the first release. The two component aliases and
`Support\ShopperContext`'s two methods are the public surface; a change to either
will be a minor version and a `CHANGELOG.md` entry.
