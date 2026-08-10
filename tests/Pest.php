<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\Fulfillment\Actions\RecordShipment;
use Liberu\Ecommerce\Fulfillment\Actions\RequestFulfillment;
use Liberu\Ecommerce\Fulfillment\Actions\TransitionShipment;
use Liberu\Ecommerce\Fulfillment\Data\FulfillmentLineInput;
use Liberu\Ecommerce\Fulfillment\Data\FulfillmentRequestData;
use Liberu\Ecommerce\Fulfillment\Data\FulfillmentRequestInput;
use Liberu\Ecommerce\Fulfillment\Data\ShipmentData;
use Liberu\Ecommerce\Fulfillment\Data\ShipmentInput;
use Liberu\Ecommerce\Fulfillment\Data\ShipmentLineInput;
use Liberu\Ecommerce\Fulfillment\Enums\ShipmentStatus;
use Liberu\Ecommerce\Fulfillment\Livewire\Tests\Fixtures\FixtureOrder;
use Liberu\Ecommerce\Fulfillment\Models\Shipment;
use Liberu\PackageTestbench\PackageTestCase;
use Liberu\PackageTestbench\TestUser;
use Liberu\PackageTestbench\UsesTestUser;

/*
 * `UsesTestUser`, which brings `RefreshDatabase` with it.
 *
 * Every surface in this package is about "your own parcels", and there is no
 * guest half of that question — a parcel is addressed to a named person and
 * carries the string that identifies it to a carrier. So there is a users table
 * here and almost nothing runs without one.
 *
 * The `beforeEach` builds the host-shaped order table this suite answers
 * ownership from, and points the package at it. Both are the deployment's job in
 * production; here they are two lines, and the test that unsets the second is the
 * one that proves the default is closed rather than open.
 */
uses(PackageTestCase::class, UsesTestUser::class)
    ->beforeEach(function (): void {
        if (! Schema::hasTable('fixture_orders')) {
            Schema::create('fixture_orders', function (Blueprint $table): void {
                $table->id();
                $table->string('number')->unique();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
            });
        }

        ownership();
    })
    ->in(__DIR__);

/**
 * A customer id belonging to somebody who is not, and cannot be, the actor.
 *
 * Nine million, and it matters. `TestUser::factory()` issues ids from 1 upwards,
 * and a "stranger's" order filed against id 2 becomes the actor's own the moment
 * a second user is created in the same test — which makes an authorization
 * assertion pass for a reason that has nothing to do with authorization. This
 * range cannot collide with one.
 */
const STRANGER = 9000001;

/** An order number that was never minted. */
const UNMINTED = 'ORD-000000000000';

/**
 * Point the package at the table that records who owns an order.
 *
 * Null is the shipped default, and it means every read refuses: the domain module
 * this package presents holds no column naming a shopper, so a deployment that
 * has not answered the ownership question gets a surface that shows nobody
 * anything.
 */
function ownership(?string $model = FixtureOrder::class): void
{
    config()->set('fulfillment-livewire.order.model', $model);
}

/** Somebody to be, signed in the way the framework does it. */
function shopper(PackageTestCase $test): TestUser
{
    $user = TestUser::factory()->create();

    $test->actingAs($user);

    return $user;
}

/**
 * An order belonging to somebody, in the deployment's own table.
 *
 * `customerId` is passed explicitly on every call in this suite. A default of
 * "the signed-in user" would make it possible to write a test about somebody
 * else's order without noticing that it is about your own.
 *
 * Ids start at nine million for the same reason the stranger's does, and because
 * they are also the ids the fulfillment tables hold — an order id that collided
 * with a fixture row somewhere else would make a read pass for the wrong reason.
 */
function orderFor(?int $customerId): FixtureOrder
{
    static $sequence = 0;

    $id = 9_000_200 + $sequence++;

    return FixtureOrder::query()->create([
        'id' => $id,
        // Shaped like the reference the orders domain mints — hex from the
        // CSPRNG, and deliberately carrying no trace of the row id, so a test
        // asserting that the id is absent from the markup cannot pass or fail on
        // the number instead.
        'number' => 'ORD-'.strtoupper(bin2hex(random_bytes(6))),
        'customer_id' => $customerId,
    ]);
}

/**
 * What the warehouse was asked to send for that order, through the domain's own
 * action rather than by writing rows.
 *
 * The returned value carries each line's id, which is what a caller holds on to.
 * A `hasMany` collection carries no ordering and indexing into one is a test
 * asserting against whatever the database felt like returning.
 *
 * @param  list<int>  $quantities
 */
function demandFor(FixtureOrder $order, array $quantities = [1]): FulfillmentRequestData
{
    static $sequence = 0;

    $lines = [];

    foreach ($quantities as $index => $quantity) {
        $lines[] = new FulfillmentLineInput(
            orderLineId: 9_000_500 + $sequence++,
            quantity: $quantity,
            name: 'Merino Crew '.($index + 1),
            sku: 'MC-'.($index + 1),
        );
    }

    return new RequestFulfillment()->handle(new FulfillmentRequestInput(
        orderId: $order->id,
        lines: $lines,
        orderNumber: $order->number,
        teamId: 9_000_007,
        destination: ['line1' => '1 Warehouse Way', 'city' => 'Leeds', 'postcode' => 'LS1 1AA'],
    ))->request;
}

/**
 * A parcel, recorded the way the host records one.
 *
 * `$lines` is order line id => quantity, which is the entire payload the module
 * boundary rests on: two integers.
 *
 * @param  array<int, int>  $lines
 */
function parcelFor(
    FixtureOrder $order,
    array $lines,
    bool $dispatched = false,
    ?string $carrier = null,
    ?string $tracking = null,
    ?array $destination = null,
): ShipmentData {
    static $sequence = 0;

    $inputs = [];

    foreach ($lines as $orderLineId => $quantity) {
        $inputs[] = new ShipmentLineInput(orderLineId: $orderLineId, quantity: $quantity);
    }

    return new RecordShipment()->handle(new ShipmentInput(
        orderId: $order->id,
        shipmentKey: 'fixture-parcel-'.$sequence++,
        lines: $inputs,
        carrier: $carrier,
        service: $carrier === null ? null : 'next-day',
        trackingNumber: $tracking,
        destination: $destination,
        dispatchedAt: $dispatched ? now()->toIso8601String() : null,
    ))->shipment;
}

/** Move a parcel on, through the domain's own state machine. */
function moveParcel(ShipmentData $parcel, ShipmentStatus $to): void
{
    $shipment = Shipment::query()->where('reference', $parcel->reference)->firstOrFail();

    new TransitionShipment()->handle($shipment, $to);
}
