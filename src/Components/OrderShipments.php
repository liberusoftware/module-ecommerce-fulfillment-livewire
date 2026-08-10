<?php

namespace Liberu\Ecommerce\Fulfillment\Livewire\Components;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Liberu\Ecommerce\Fulfillment\Data\FulfillmentRequestData;
use Liberu\Ecommerce\Fulfillment\Data\ShipmentData;
use Liberu\Ecommerce\Fulfillment\Livewire\Concerns\ShowsOwnParcels;
use Liberu\Ecommerce\Fulfillment\Livewire\FulfillmentLivewireServiceProvider;
use Liberu\Ecommerce\Fulfillment\Queries\FulfillmentQuery;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Where the shopper's parcels are, for one of their own orders.
 *
 * ## Why this is a surface at all
 *
 * One order ships in as many parcels as the goods need, on as many carriers, on
 * as many days. That cardinality is the reason the domain module exists — a
 * column on an order can only ever hold the first answer — and it is the reason
 * this component exists too: **a single "your order has been sent" line is the
 * wrong shape.** Two of five items went yesterday on one carrier, one is packed
 * and waiting, one was called off and one has not been picked, and a shopper
 * looking at that needs to see four sentences, not one word.
 *
 * ## Nothing here writes
 *
 * There is no shopper-caused write anywhere in this domain. Raising the demand,
 * recording a parcel, moving one between states and releasing a line are all
 * staff or host operations, every one of them keyed on something the caller
 * supplies and none of them a button. So this component publishes exactly one
 * action, {@see recheck()}, which re-reads what the same shopper could already
 * see and changes nothing.
 *
 * **In particular there is no cancellation.** A pending parcel can be called off
 * — by the warehouse, which packed it — and a dispatched one cannot be called off
 * by anybody, because it is in the world and the quantity has already been
 * reported outward as fulfilled. There is no void. A shopper asking for goods
 * back after they have arrived is asking for a return, which is a different
 * module and not built yet, so the answer names that route instead of drawing a
 * button that would have to lie.
 *
 * ## Progress is counters, never a status
 *
 * The domain keeps two numbers and the difference between them is the whole
 * design: `committed` is a **reservation** and falls when a parcel that never
 * left is cancelled, `dispatched` is a **fact** and never falls. There is no
 * status on a line of demand because a line of five can be two gone, one packed,
 * one cancelled and one unpicked at the same instant. So the sentence a shopper
 * reads is built here out of those numbers, and no word in it is a state.
 *
 * The four state words that *do* appear belong to a parcel, they are the four the
 * domain publishes, and they are keyed by its own enum so a fifth cannot be
 * invented in a translation file.
 */
class OrderShipments extends Component
{
    use ShowsOwnParcels;

    /**
     * The order's public number — the handle a link hands the browser.
     *
     * Locked, and the lock is the second control rather than the first. The
     * first is that this value is worth nothing on its own: it is turned into an
     * order id only by {@see ShowsOwnParcels::ownedOrderId()}, which asks the
     * deployment whether this signed-in shopper owns it, so a swapped number
     * resolves to nothing whichever way it arrives. The lock is what stops the
     * swap being attempted at all, and what keeps the next person from adding a
     * `#[Url]` to it.
     */
    #[Locked]
    public string $number = '';

    /**
     * What just happened, in words, for the live region.
     *
     * `#[Locked]` because it is announced verbatim into a screen reader, and a
     * string the browser can set is a string an attacker can put in a shopper's
     * ear on a page about goods they have paid for. **No tracking number ever
     * goes in here**: a live region is read aloud, and this one says only that
     * the page has been re-read.
     */
    #[Locked]
    public string $announcement = '';

    private ?int $orderId = null;

    private bool $read = false;

    private ?FulfillmentRequestData $demand = null;

    /** @var list<ShipmentData>|null */
    private ?array $parcels = null;

    public function mount(string $number): void
    {
        $this->number = $number;
    }

    /**
     * The live region lasts exactly one render.
     *
     * It announces a *change*. Carrying the previous sentence into the next
     * request either says nothing new or says it again at a moment when it is no
     * longer true.
     */
    public function hydrate(): void
    {
        $this->announcement = '';
    }

    public function signedIn(): bool
    {
        return $this->shopper()->customerId() !== null;
    }

    /**
     * Read it all again.
     *
     * The one action on this surface, and it writes nothing: a parcel moves when
     * a warehouse says so, which is a different request on a different day, and
     * a shopper watching a page has no way of knowing it has happened. This is
     * the button that answers "has it moved yet".
     */
    public function recheck(): void
    {
        $this->read = false;
        $this->demand = null;
        $this->parcels = null;

        $this->announce($this->say('rechecked'));
    }

    /**
     * What this order asked the warehouse for, or null if it has asked for
     * nothing yet.
     *
     * Null is not a 404. The order is this shopper's — that has already been
     * established — and an order nobody has handed to a warehouse yet is a real
     * and ordinary state with a sentence of its own. A 404 here would tell a
     * shopper their own order does not exist.
     */
    public function demand(): ?FulfillmentRequestData
    {
        $this->load();

        return $this->demand;
    }

    /**
     * Every parcel for this order, oldest first.
     *
     * @return list<ShipmentData>
     */
    public function parcels(): array
    {
        $this->load();

        return $this->parcels ?? [];
    }

    /**
     * How much of this order has moved, counted off the lines of demand.
     *
     * `packed` is `committed − dispatched`: goods in a box that has not gone.
     * `remaining` is `quantity − committed − cancelled`: goods nobody has put in
     * a box. Both derived counts come from the domain's read model rather than
     * being recomputed here, so there is one answer to each in the fleet.
     *
     * @return array{ordered: int, sent: int, packed: int, cancelled: int, remaining: int}
     */
    public function progress(): array
    {
        $totals = ['ordered' => 0, 'sent' => 0, 'packed' => 0, 'cancelled' => 0, 'remaining' => 0];

        foreach ($this->demand()?->lines ?? [] as $line) {
            $totals['ordered'] += $line->quantity;
            $totals['sent'] += $line->dispatchedQuantity;
            $totals['packed'] += $line->undispatchedQuantity();
            $totals['cancelled'] += $line->cancelledQuantity;
            $totals['remaining'] += $line->remainingQuantity();
        }

        return $totals;
    }

    /**
     * How far the order has got, in one sentence and no state words.
     *
     * The branching is here rather than in the view because it is a decision, and
     * a decision in a Blade template is a decision a theme silently changes when
     * it publishes the file. What a theme owns is the markup around this sentence
     * and the wording inside it.
     */
    public function progressSentence(): string
    {
        if ($this->demand() === null) {
            return $this->say('progress.not_requested');
        }

        $counts = $this->progress();

        if ($counts['ordered'] === 0) {
            return $this->say('progress.nothing');
        }

        if ($counts['sent'] === $counts['ordered']) {
            return $this->say('progress.all_sent');
        }

        // Nothing left to pack and nothing waiting in a box: whatever did not go
        // was called off. Saying "everything has been sent" here would be true of
        // the parcels and false of the order.
        if ($counts['remaining'] === 0 && $counts['packed'] === 0) {
            return $counts['sent'] > 0
                ? $this->say('progress.settled', $counts)
                : $this->say('progress.all_cancelled');
        }

        if ($counts['sent'] > 0) {
            return $this->say('progress.part_sent', $counts);
        }

        if ($counts['packed'] > 0) {
            return $this->say('progress.packed', $counts);
        }

        return trans_choice(
            FulfillmentLivewireServiceProvider::NAMESPACE.'::fulfillment.progress.none_sent',
            $counts['remaining'],
            $counts,
        );
    }

    /**
     * A parcel's state, in the deployment's words.
     *
     * Keyed by the domain's own enum value, so the only states this package can
     * render are the four it publishes. A fifth word here would be this package
     * inventing a state the domain refused — and the one it refused by name is
     * the one somebody always wants: there is no "void".
     */
    public function state(ShipmentData $parcel): string
    {
        return $this->say('state.'.$parcel->status->value);
    }

    /** What is in this parcel, as a count. */
    public function contents(ShipmentData $parcel): string
    {
        return trans_choice(
            FulfillmentLivewireServiceProvider::NAMESPACE.'::fulfillment.parcel.items',
            $parcel->totalQuantity(),
            ['count' => $parcel->totalQuantity()],
        );
    }

    /**
     * The name of the thing a parcel line refers to.
     *
     * A parcel line carries an order line id and a quantity — two integers, which
     * is the whole payload the module boundary rests on. The name lives on the
     * line of demand, and this is where the two are put back together, for the
     * one reader who wants words rather than ids.
     */
    public function itemName(int $orderLineId): ?string
    {
        return $this->demand()?->line($orderLineId)?->name;
    }

    /**
     * The tracking number, for the shopper who owns this parcel, or null.
     *
     * ## Where it is, and everywhere it is not
     *
     * A tracking number is evidence: it identifies this parcel to a carrier, and
     * anybody holding it can ask that carrier where a named person's goods are.
     * The domain keeps it out of its own read model's `toArray()`, out of its
     * telemetry, out of every query, out of its idempotency hash and off the
     * model's default serialisation, and reaches for it explicitly only here —
     * the one surface that renders it to the person it belongs to.
     *
     * So this package shows it, and puts it nowhere else. It is not a property,
     * so it never travels to the browser and back as component state. It is not
     * bound to the URL, so it is not in a query string, a browser history, an
     * access log or a `Referer` header. It is not in the live region, so it is
     * not read aloud. Nothing here logs.
     *
     * ## Only once the parcel has left
     *
     * Before dispatch it identifies nothing a shopper can look up — a label may
     * exist, but the carrier has not been handed the goods — so showing it early
     * puts the value on a screenshot in exchange for nothing. `hasLeft()` is the
     * domain's own line between a reservation and a fact.
     */
    public function tracking(ShipmentData $parcel): ?string
    {
        if (! $parcel->status->hasLeft()) {
            return null;
        }

        return $parcel->trackingNumber === '' ? null : $parcel->trackingNumber;
    }

    /**
     * The carrier's own tracking page for this parcel, or null.
     *
     * Built from a template the **host** configured against the carrier string
     * the host recorded. This package knows no carrier's name and no carrier's
     * URL shape; a package that did would have to be released the day a merchant
     * signs with somebody else, and it would be carrying an opinion about which
     * carriers are worth naming.
     *
     * A template that is not an `https` URL with a `:tracking` placeholder in it
     * is ignored rather than rendered. A misconfiguration should produce a
     * tracking number with no link, never a control that goes somewhere
     * unexpected.
     */
    public function trackingUrl(ShipmentData $parcel): ?string
    {
        $tracking = $this->tracking($parcel);

        if ($tracking === null || $parcel->carrier === null || $parcel->carrier === '') {
            return null;
        }

        $templates = config('fulfillment-livewire.tracking_urls');
        $template = is_array($templates) ? ($templates[$parcel->carrier] ?? null) : null;

        if (! is_string($template) || ! str_starts_with($template, 'https://') || ! str_contains($template, ':tracking')) {
            return null;
        }

        return str_replace(':tracking', rawurlencode($tracking), $template);
    }

    /**
     * When a parcel reached a state, for a `<time>` element's text.
     *
     * The read model publishes ISO 8601 strings, which are the right thing to
     * carry and the wrong thing to read.
     */
    public function on(?string $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp)->toFormattedDateString();
    }

    /**
     * Whether anything has actually left, which is when the returns sentence
     * becomes relevant.
     *
     * Goods that have not gone can still be called off — by the warehouse, on the
     * phone. Goods that have gone cannot be un-sent by anybody, and asking for
     * them back is a return.
     */
    public function anythingSent(): bool
    {
        foreach ($this->parcels() as $parcel) {
            if ($parcel->status->hasLeft()) {
                return true;
            }
        }

        return false;
    }

    public function render(): View
    {
        return view(FulfillmentLivewireServiceProvider::NAMESPACE.'::livewire.order-shipments');
    }

    protected function announce(string $message): void
    {
        $this->announcement = $message;
    }

    /**
     * Both reads, once, behind the ownership question.
     *
     * The order id is never something a browser supplied: it is what the server
     * resolved from a public number and an authenticated actor together. A number
     * that names nothing and a number that names somebody else's order are the
     * same 404, because the difference between them is information about somebody
     * else's order.
     */
    private function load(): void
    {
        if ($this->read) {
            return;
        }

        $orderId = $this->orderId ??= $this->ownedOrderId($this->number);

        if ($orderId === null) {
            abort(404);
        }

        $query = app(FulfillmentQuery::class);

        $this->demand = $query->forOrder($orderId);
        $this->parcels = $query->shipmentsForOrder($orderId);
        $this->read = true;
    }
}
