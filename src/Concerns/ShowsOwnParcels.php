<?php

namespace Liberu\Ecommerce\Fulfillment\Livewire\Concerns;

use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\Fulfillment\Livewire\FulfillmentLivewireServiceProvider;
use Liberu\Ecommerce\Fulfillment\Livewire\Support\ShopperContext;

/**
 * What every component here does before it does anything else: find out whether
 * this order is the signed-in shopper's, and stop if it is not.
 *
 * ## The actor is the first question, not a clause on a later one
 *
 * The order surface for this fleet narrows a query to the customer and then
 * applies the number to that builder. It can, because an order carries the
 * customer it belongs to. A parcel does not — the domain module this package
 * presents files a shipment against an order id and holds no column naming a
 * shopper — so there is no builder to narrow, and the ownership question has to
 * be asked and answered *before* the first read runs rather than folded into it.
 *
 * {@see ownedOrderId()} is that question, and it is the only way an order id
 * enters this package. It returns null unless somebody is signed in **and** the
 * deployment says the number they are holding names their own order; every
 * caller then does one of two things and nothing else — 404, or show the
 * invitation to sign in. No read of the domain happens on either path.
 *
 * So the identifier the domain's own reads are keyed on — an order's row id — is
 * never something a browser supplied. It is something the server resolved from a
 * public number *and* an authenticated actor, one call, both or neither.
 *
 * ## Not found and not yours are the same answer
 *
 * Both are null here and both are a 404 above. Telling them apart — "that order
 * exists but is not yours" — is how a caller learns which order numbers exist,
 * which is the enumeration a minted number is for.
 *
 * ## A signed-out visitor is not 404'd
 *
 * Nothing is looked up for them at all, so the answer cannot depend on whether
 * the number is real. A 404 for a real number and a sign-in prompt for an invented
 * one would be an oracle that answers "does this order exist" to anybody who asks
 * twice.
 */
trait ShowsOwnParcels
{
    /**
     * The id of the order this number names, if it is this shopper's.
     *
     * The actor is resolved first and the lookup does not happen without one.
     * That ordering is deliberate and is asserted: a deployment that overrode
     * ownership carelessly still cannot leak to a visitor who is not signed in,
     * because nothing asks it anything.
     */
    protected function ownedOrderId(string $orderNumber): ?int
    {
        $customerId = $this->shopper()->customerId();

        if ($customerId === null || $orderNumber === '') {
            return null;
        }

        return $this->shopper()->orderIdFor($orderNumber, $customerId);
    }

    protected function shopper(): ShopperContext
    {
        return app(ShopperContext::class);
    }

    /**
     * Where a link in these views goes, or null.
     *
     * Routes belong to the application composing this package. An unregistered
     * name is treated as none, because a `#` href is a control that announces
     * itself as a link and then does nothing.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function link(string $kind, array $parameters = []): ?string
    {
        $name = config('fulfillment-livewire.routes.'.$kind);

        if (! is_string($name) || $name === '' || ! Route::has($name)) {
            return null;
        }

        return route($name, $parameters);
    }

    /**
     * Shorthand for this package's translation namespace.
     *
     * @param  array<string, mixed>  $replace
     */
    protected function say(string $key, array $replace = []): string
    {
        return __(FulfillmentLivewireServiceProvider::NAMESPACE.'::fulfillment.'.$key, $replace);
    }
}
