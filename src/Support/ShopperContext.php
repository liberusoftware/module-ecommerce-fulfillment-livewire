<?php

namespace Liberu\Ecommerce\Fulfillment\Livewire\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Whose parcels these are — decided entirely by the server, and refused until a
 * deployment has said where ownership is recorded.
 *
 * ## Why this class is bigger here than on the order surface
 *
 * The order surface for this fleet can answer ownership itself: an order carries
 * the customer it was filed against, so "your orders" is a column. **A parcel
 * does not.** The four tables of the domain module this package presents hold an
 * order id, an order number, a team id and a store id, and nothing that names a
 * shopper — deliberately, because a parcel is a fact about goods leaving a
 * warehouse and the customer relationship lives on the order.
 *
 * So this package cannot answer "is this order yours", and the interesting
 * decision is what to do about that. It does not guess, it does not fall back to
 * the order number alone — knowing a reference is not owning the thing it names —
 * and it does not reach into another module for the answer, which would be an
 * import this package is not allowed to have. It asks the deployment, through
 * {@see orderIdFor()}, and **refuses everything until the deployment answers**.
 *
 * A closed default is the only safe one here. An open default would be a package
 * that shows a stranger's parcels to anybody who has not finished configuring it.
 *
 * ## Two ways to answer it
 *
 * Configuration, for the ordinary case where a customer is a user and orders live
 * in a table with a public number on it. `FULFILLMENT_LIVEWIRE_ORDER_MODEL` takes
 * the fully qualified name of whichever Eloquent class records an order in this
 * deployment; `docs/adoption.md` spells out the value for the fleet's own.
 *
 * The class is a string resolved at call time and is never named here, so this
 * package still requires only its own domain module — and a package that named
 * another one in a `use` statement would install into exactly one application.
 *
 * Or code, for a deployment whose customers are not its users — a CRM contact
 * keyed separately from the login, an ownership rule with a household in it:
 *
 *     $this->app->singleton(ShopperContext::class, fn () => new HostShopperContext());
 *
 * which is why this class is not final. Every read in the package follows,
 * because nothing else here asks who the shopper is.
 */
class ShopperContext
{
    /**
     * The signed-in shopper, or null for everybody else.
     *
     * A guest has no parcels, and that is an answer rather than a gap. A parcel
     * is addressed to a named person at a postal address and carries the string
     * that identifies it to a carrier; there is nothing a guest could present
     * that would make one theirs. An email address is the obvious candidate and
     * exactly the wrong one — knowing an address is not proving you own it.
     *
     * Every component checks this **before** anything else happens, so a signed
     * out visitor causes no lookup at all and the answer they get cannot depend
     * on whether the number they hold is real.
     */
    public function customerId(): ?int
    {
        $id = Auth::id();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * The id of the order this number names, **if it belongs to this customer**.
     *
     * Null for a number that names nothing and null for a number that names
     * somebody else's order — one answer for both, because the difference
     * between them is information about somebody else's order, and it is
     * precisely the information that turns a guessed number into a confirmed
     * one.
     *
     * The customer id is a parameter rather than something this method reads for
     * itself. It is what makes the signature the ownership question instead of a
     * lookup: an override cannot forget to scope on the actor without deleting an
     * argument it was handed, and the caller has already refused to call this at
     * all when there is no actor.
     *
     * The default resolves the configured model at call time and asks it for one
     * value. Both clauses are bound: `where('customer_id', $customerId)` with an
     * integer can never become `is null`, which is what would list precisely the
     * orphan orders belonging to no account.
     */
    public function orderIdFor(string $orderNumber, int $customerId): ?int
    {
        if ($orderNumber === '') {
            return null;
        }

        $class = config('fulfillment-livewire.order.model');

        // Unconfigured is a refusal, not an error. A deployment that has not said
        // where ownership is recorded gets a surface that shows nobody anything,
        // which is the failure that is safe to have in production for an
        // afternoon.
        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            return null;
        }

        $model = new $class();

        if (! $model instanceof Model) {
            return null;
        }

        $id = $model->newQuery()
            ->where($this->column('number_column', 'number'), $orderNumber)
            ->where($this->column('customer_column', 'customer_id'), $customerId)
            ->value($model->getKeyName());

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * A column name from configuration, falling back rather than querying a
     * column called "".
     */
    private function column(string $key, string $default): string
    {
        $column = config('fulfillment-livewire.order.'.$key);

        return is_string($column) && $column !== '' ? $column : $default;
    }
}
