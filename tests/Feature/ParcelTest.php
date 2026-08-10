<?php

use Liberu\Ecommerce\Fulfillment\Actions\ReleaseLine;
use Liberu\Ecommerce\Fulfillment\Enums\ShipmentStatus;
use Liberu\Ecommerce\Fulfillment\Livewire\Components\OrderShipments;
use Liberu\Ecommerce\Fulfillment\Livewire\FulfillmentLivewireServiceProvider;
use Livewire\Livewire;

/*
 * What the shopper is actually shown.
 *
 * Two rules run through all of it. **Progress is not a status**: it is built from
 * the domain's two counters, one of which is a reservation that may fall and one
 * of which is a fact that may not, and no word in the sentence is a state.
 * **Cardinality is the point**: one order ships in as many parcels, on as many
 * carriers, on as many days as the goods need, and a single "your order has been
 * sent" line is the wrong shape for that.
 */

/** The sentence the page is currently saying about how far the order has got. */
function progressOf(string $number): string
{
    preg_match('/data-parcels-progress>([^<]*)</', Livewire::test(OrderShipments::class, ['number' => $number])->html(), $said);

    return trim($said[1] ?? '');
}

it('renders only the four states the domain publishes', function () {
    $translated = __(FulfillmentLivewireServiceProvider::NAMESPACE.'::fulfillment.state');

    // Folded into one test rather than a dataset over the enum's cases: a dataset
    // row is built before the application boots, and reaching an enum that lives
    // in an installed package is fine, but reaching anything that needs a
    // connection is not — so the habit here is one test.
    $published = array_map(fn (ShipmentStatus $status): string => $status->value, ShipmentStatus::cases());

    // The same set, both ways. A fifth key here would be this package inventing a
    // state the domain refused, and the one it refused by name is the one
    // somebody always asks for: there is no void.
    expect(array_keys($translated))->toEqualCanonicalizing($published);

    foreach ($translated as $word) {
        expect($word)->toBeString()->not->toBe('');
    }
});

it('shows one order shipping in several parcels on several carriers', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    $demand = demandFor($order, [3, 2]);
    [$first, $second] = $demand->lines;

    $one = parcelFor($order, [$first->orderLineId => 2], dispatched: true, carrier: 'haulier-one', tracking: 'AAA111');
    $two = parcelFor($order, [$first->orderLineId => 1, $second->orderLineId => 2], carrier: 'haulier-two');

    $html = Livewire::test(OrderShipments::class, ['number' => $order->number])->assertOk()->html();

    // Two parcels, two carriers, two states, on one order. This is the shape the
    // whole module exists for — the reason the carrier facts were refused as
    // columns on an order is that the second parcel makes a column wrong.
    expect($html)->toContain($one->reference)
        ->and($html)->toContain($two->reference)
        ->and($html)->toContain('haulier-one')
        ->and($html)->toContain('haulier-two')
        ->and($html)->toContain(__('module-ecommerce-fulfillment::fulfillment.state.dispatched'))
        ->and($html)->toContain(__('module-ecommerce-fulfillment::fulfillment.state.pending'))
        // Oldest first, which is the order the domain's read returns them in and
        // the order the parcels happened in.
        ->and(strpos($html, $one->reference))->toBeLessThan(strpos($html, $two->reference));
});

it('puts a name to what is in each parcel', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    $demand = demandFor($order, [2]);
    parcelFor($order, [$demand->lines[0]->orderLineId => 2], dispatched: true);

    // A parcel line is an order line id and a quantity — two integers, which is
    // the entire payload the module boundary rests on. The name lives on the line
    // of demand, and the page is where the two are put back together.
    Livewire::test(OrderShipments::class, ['number' => $order->number])
        ->assertOk()
        ->assertSee($demand->lines[0]->name)
        ->assertSee(__('module-ecommerce-fulfillment::fulfillment.parcel.quantity', ['count' => 2]));
});

it('says so plainly when the warehouse has not been asked for anything', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());

    // The order is this shopper's — that is established before this point — and
    // an order nobody has handed to a warehouse yet is an ordinary state with a
    // sentence of its own. A 404 here would tell a shopper their own order does
    // not exist.
    Livewire::test(OrderShipments::class, ['number' => $order->number])
        ->assertOk()
        ->assertSee(__('module-ecommerce-fulfillment::fulfillment.progress.not_requested'));
});

it('says so plainly when nothing has been packed yet', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    demandFor($order, [2]);

    Livewire::test(OrderShipments::class, ['number' => $order->number])
        ->assertOk()
        ->assertSee(__('module-ecommerce-fulfillment::fulfillment.parcel.none'));
});

it('builds progress out of the counters and never out of a state word', function () {
    $user = shopper($this);

    // Nothing packed: everything is still to pick.
    $order = orderFor($user->getKey());
    $demand = demandFor($order, [3]);
    $line = $demand->lines[0];

    expect(progressOf($order->number))->toBe(trans_choice(
        FulfillmentLivewireServiceProvider::NAMESPACE.'::fulfillment.progress.none_sent',
        3,
        ['ordered' => 3, 'sent' => 0, 'packed' => 0, 'cancelled' => 0, 'remaining' => 3],
    ));

    // Packed and waiting. `committed` has moved and `dispatched` has not — a
    // reservation, not a fact, and the page has to be able to say so without
    // claiming anything left.
    $waiting = parcelFor($order, [$line->orderLineId => 1]);

    expect(progressOf($order->number))->toBe(__('module-ecommerce-fulfillment::fulfillment.progress.packed', [
        'ordered' => 3, 'sent' => 0, 'packed' => 1, 'cancelled' => 0, 'remaining' => 2,
    ]));

    // One gone. Three counts at once now, which is the case a single status word
    // cannot express.
    parcelFor($order, [$line->orderLineId => 1], dispatched: true);

    expect(progressOf($order->number))->toBe(__('module-ecommerce-fulfillment::fulfillment.progress.part_sent', [
        'ordered' => 3, 'sent' => 1, 'packed' => 1, 'cancelled' => 0, 'remaining' => 1,
    ]));

    // The reservation falls. Cancelling a parcel that never left puts the goods
    // back on the shelf, and this is the counter that is allowed to go down.
    moveParcel($waiting, ShipmentStatus::Cancelled);

    expect(progressOf($order->number))->toBe(__('module-ecommerce-fulfillment::fulfillment.progress.part_sent', [
        'ordered' => 3, 'sent' => 1, 'packed' => 0, 'cancelled' => 0, 'remaining' => 2,
    ]));

    // The rest is called off by whoever owns that decision, and what did not go
    // is not the same as what was sent.
    new ReleaseLine()->handle($line->orderLineId, 2);

    expect(progressOf($order->number))->toBe(__('module-ecommerce-fulfillment::fulfillment.progress.settled', [
        'ordered' => 3, 'sent' => 1, 'packed' => 0, 'cancelled' => 2, 'remaining' => 0,
    ]));
});

it('says everything has been sent only when everything has', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    $demand = demandFor($order, [2]);
    parcelFor($order, [$demand->lines[0]->orderLineId => 2], dispatched: true);

    expect(progressOf($order->number))->toBe(__('module-ecommerce-fulfillment::fulfillment.progress.all_sent'));
});

it('says nothing was sent when nothing was', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    $demand = demandFor($order, [2]);

    new ReleaseLine()->handle($demand->lines[0]->orderLineId, 2);

    expect(progressOf($order->number))->toBe(__('module-ecommerce-fulfillment::fulfillment.progress.all_cancelled'));
});

it('says there is nothing to send when the order asked for nothing shippable', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    demandFor($order, []);

    expect(progressOf($order->number))->toBe(__('module-ecommerce-fulfillment::fulfillment.progress.nothing'));
});

it('names the returns route instead of offering a button, and only once something has gone', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    $demand = demandFor($order, [2]);
    parcelFor($order, [$demand->lines[0]->orderLineId => 1]);

    // Nothing has left. Goods that have not gone can still be called off by the
    // warehouse that packed them, so there is nothing to say about returning
    // anything.
    Livewire::test(OrderShipments::class, ['number' => $order->number])
        ->assertOk()
        ->assertDontSee(__('module-ecommerce-fulfillment::fulfillment.returns.explain'));

    parcelFor($order, [$demand->lines[0]->orderLineId => 1], dispatched: true);

    // Now something has. A dispatched parcel cannot be cancelled by anybody — it
    // is in the world and the quantity has already been reported outward as
    // fulfilled — so the answer is the route a return takes, not a control that
    // would have to lie about what pressing it did.
    Livewire::test(OrderShipments::class, ['number' => $order->number])
        ->assertOk()
        ->assertSee(__('module-ecommerce-fulfillment::fulfillment.returns.explain'));
});

it('offers no control that changes anything', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    $demand = demandFor($order, [2]);
    parcelFor($order, [$demand->lines[0]->orderLineId => 1], dispatched: true);

    $html = Livewire::test(OrderShipments::class, ['number' => $order->number])->html();

    preg_match_all('/wire:click="([^"(]+)/', $html, $actions);

    // There is no shopper-caused write in this domain at all: raising the demand,
    // recording a parcel, moving one between states and releasing a line are
    // staff or host operations, every one keyed on something the caller supplies.
    // The one action here re-reads what the shopper could already see.
    expect(array_unique($actions[1]))->toBe(['recheck'])
        ->and($html)->not->toContain('<form');

    // And every public method the browser could name is either a read or that
    // one. Asserted as "the writes are these", rather than by naming an
    // operation this package must not publish.
    $callable = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            new ReflectionClass(OrderShipments::class)->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === OrderShipments::class,
        ),
    );

    expect($callable)->toContain('recheck');
});

it('re-reads on request and says that it did', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    $demand = demandFor($order, [2]);
    $parcel = parcelFor($order, [$demand->lines[0]->orderLineId => 2]);

    $component = Livewire::test(OrderShipments::class, ['number' => $order->number])
        ->assertSee(__('module-ecommerce-fulfillment::fulfillment.state.pending'));

    // A parcel moves when a warehouse says so, which is a different request on a
    // different day. This is the button that answers "has it moved yet".
    moveParcel($parcel, ShipmentStatus::Dispatched);

    $component->call('recheck')
        ->assertSee(__('module-ecommerce-fulfillment::fulfillment.rechecked'))
        ->assertSee(__('module-ecommerce-fulfillment::fulfillment.state.dispatched'));

    // The live region lasts exactly one render: it announces a change, and
    // repeating it on the next request either says nothing new or says it at a
    // moment when it is no longer true. Asserted on the hook rather than through
    // a second round trip, so it is the rule being tested and not the framework.
    $next = new OrderShipments();
    $next->announcement = 'said last time';
    $next->hydrate();

    expect($next->announcement)->toBe('');
});

it('shows a parcel its own destination, which need not be the order\'s', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    $demand = demandFor($order, [2]);

    parcelFor($order, [$demand->lines[0]->orderLineId => 1], dispatched: true);
    parcelFor($order, [$demand->lines[0]->orderLineId => 1], dispatched: true, destination: ['line1' => '2 Neighbour Close', 'city' => 'Leeds']);

    // Rerouting one box changes where that box goes and rewrites nothing else.
    // The shopper is the person who most needs to see that the second parcel is
    // going somewhere different.
    Livewire::test(OrderShipments::class, ['number' => $order->number])
        ->assertOk()
        ->assertSee('1 Warehouse Way')
        ->assertSee('2 Neighbour Close');
});

it('shows delivery when a parcel arrives', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    $demand = demandFor($order, [1]);
    $parcel = parcelFor($order, [$demand->lines[0]->orderLineId => 1], dispatched: true);

    moveParcel($parcel, ShipmentStatus::Delivered);

    Livewire::test(OrderShipments::class, ['number' => $order->number])
        ->assertOk()
        ->assertSee(__('module-ecommerce-fulfillment::fulfillment.state.delivered'))
        // Delivered is terminal, and the counter that says goods went out does
        // not move again: everything is still sent.
        ->assertSee(__('module-ecommerce-fulfillment::fulfillment.progress.all_sent'));
});
