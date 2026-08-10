<?php

namespace Liberu\Ecommerce\Fulfillment\Livewire\Pages;

use Illuminate\Contracts\View\View;
use Liberu\Ecommerce\Fulfillment\Livewire\Concerns\ShowsOwnParcels;
use Liberu\Ecommerce\Fulfillment\Livewire\FulfillmentLivewireServiceProvider;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The routable page for one order's parcels: one `<h1>`, then the parcels.
 *
 * It declares no layout. Routes, layouts, navigation and middleware belong to the
 * application composing this package, and a package naming a layout view would
 * only install into an application that happens to have one by that name.
 *
 * The route parameter is untrusted input, and this page is what refuses it — not
 * the route definition, and not the component it composes. It asks the ownership
 * question itself in `mount()`, because a page that renders its heading before
 * its child 404s has already told somebody that a number names something.
 *
 * A signed-out visitor is **not** 404'd. Nothing is looked up for them at all, so
 * the answer cannot depend on whether the number is real; they are shown the
 * invitation to sign in that the component below would show them anyway.
 *
 * **Put `auth` on this route** all the same. The page works without it, but a
 * shopper following a link from a dispatch email after their session expired
 * would rather be sent to the sign-in form than to a page telling them to find
 * one.
 */
class ShipmentsPage extends Component
{
    use ShowsOwnParcels;

    /** @see ShowsOwnParcels for why this is locked and why the lock is the second control. */
    #[Locked]
    public string $number = '';

    public function mount(string $number): void
    {
        $this->number = $number;

        if ($this->shopper()->customerId() !== null && $this->ownedOrderId($number) === null) {
            abort(404);
        }
    }

    #[Title('Your parcels')]
    public function render(): View
    {
        return view(FulfillmentLivewireServiceProvider::NAMESPACE.'::livewire.pages.shipments');
    }
}
