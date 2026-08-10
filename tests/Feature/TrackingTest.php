<?php

use Liberu\Ecommerce\Fulfillment\Enums\ShipmentStatus;
use Liberu\Ecommerce\Fulfillment\Livewire\Components\OrderShipments;
use Livewire\Livewire;

/*
 * A tracking number is evidence.
 *
 * It identifies a parcel to a carrier: anybody holding it can ask that carrier
 * where a named person's goods are, and when. The domain module treats it
 * accordingly — out of its read model's array form, out of its telemetry, out of
 * every query it publishes, out of its idempotency hash, and `$hidden` on the
 * model — and reaches for it explicitly at the one surface that renders it to the
 * person it belongs to.
 *
 * That surface is this one, so this file is where the decision is written down:
 * it is shown to the owner, once the goods have left, and it goes nowhere else.
 * Not into a property, so it never travels to the browser and back as component
 * state. Not into the URL, so not into a query string, a browser history, an
 * access log or a `Referer` header. Not into the live region, because a live
 * region is read out loud. Nothing here logs.
 */

const TRACKING = 'ZZ 9999 8888 7777 GB';

const HAULIER = 'haulier-one';

/** An order of this shopper's with one dispatched parcel carrying a tracking number. */
function tracked(mixed $test, ?string $carrier = HAULIER, ?string $tracking = TRACKING): array
{
    $user = shopper($test);

    $order = orderFor($user->getKey());
    $demand = demandFor($order, [2]);
    $parcel = parcelFor($order, [$demand->lines[0]->orderLineId => 1], dispatched: true, carrier: $carrier, tracking: $tracking);

    return [$order, $parcel];
}

/** Whether the page drew a link to a carrier's own tracking page. */
function trackingLinked(string $number): bool
{
    return str_contains(Livewire::test(OrderShipments::class, ['number' => $number])->html(), 'data-parcel-tracking-link');
}

it('shows the tracking number to the shopper whose parcel it is', function () {
    [$order] = tracked($this);

    Livewire::test(OrderShipments::class, ['number' => $order->number])
        ->assertOk()
        ->assertSee(TRACKING);
});

it('does not show a tracking number until the goods have actually left', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    $demand = demandFor($order, [2]);
    parcelFor($order, [$demand->lines[0]->orderLineId => 1], carrier: HAULIER, tracking: TRACKING);

    // A label may exist before a carrier has the goods, and a number that
    // identifies nothing anybody can look up buys the shopper nothing in exchange
    // for being on the page and in every screenshot of it. `hasLeft()` is the
    // domain's own line between a reservation and a fact.
    Livewire::test(OrderShipments::class, ['number' => $order->number])
        ->assertOk()
        ->assertDontSee(TRACKING);
});

it('keeps the tracking number out of everything the page says out loud', function () {
    [$order] = tracked($this);

    $component = Livewire::test(OrderShipments::class, ['number' => $order->number])->call('recheck');

    // The live region is announced verbatim into a screen reader and is the one
    // string on this page that is read without being looked at. It says that the
    // page was re-read, and nothing else.
    expect($component->get('announcement'))->toBe(__('module-ecommerce-fulfillment::fulfillment.rechecked'))
        ->not->toContain(TRACKING);

    // And it is not component state either: there is no property carrying it, so
    // it is not in the snapshot that travels to the browser and back.
    $properties = array_filter(
        new ReflectionClass(OrderShipments::class)->getProperties(),
        fn (ReflectionProperty $property): bool => $property->isPublic(),
    );

    foreach ($properties as $property) {
        expect(strtolower($property->getName()))->not->toContain('track');
    }
});

it('links to the carrier only where the deployment said where that is', function () {
    [$order, $parcel] = tracked($this);

    // No configuration, no link. This package knows no carrier's name and no
    // carrier's URL shape; one that did would have to be released the day a
    // merchant signs with somebody else.
    Livewire::test(OrderShipments::class, ['number' => $order->number])->assertOk()->assertSee(TRACKING);

    expect(trackingLinked($order->number))->toBeFalse();

    config()->set('fulfillment-livewire.tracking_urls', [HAULIER => 'https://example.test/track/:tracking']);

    $html = Livewire::test(OrderShipments::class, ['number' => $order->number])->html();

    // Encoded, because a tracking number is a string a carrier chose the shape of
    // and this one has spaces in it.
    expect($html)->toContain('https://example.test/track/'.rawurlencode(TRACKING))
        // The carrier issued the number, so following the link tells it nothing
        // it does not know — but it has no business learning the address of the
        // page the shopper was on.
        ->and($html)->toContain('rel="noreferrer noopener"')
        ->and($parcel->carrier)->toBe(HAULIER);
});

it('ignores a tracking template that is not one', function () {
    [$order] = tracked($this);

    // Folded into one test rather than a dataset. A dataset row is built before
    // the application boots, and everything here needs a database.
    $refused = [
        // No placeholder: one link that went to the same page for every parcel.
        'https://example.test/track',
        // Not https, and the scheme is the part that decides what following a
        // link does at all. A misconfiguration should cost a link, never produce
        // a control that goes somewhere unexpected.
        'http://example.test/track/:tracking',
        'javascript:alert(:tracking)',
        ':tracking',
        '',
    ];

    foreach ($refused as $template) {
        config()->set('fulfillment-livewire.tracking_urls', [HAULIER => $template]);

        expect(trackingLinked($order->number))->toBeFalse();
    }

    // A value that is not a string, and a whole map that is not a map.
    config()->set('fulfillment-livewire.tracking_urls', [HAULIER => 42]);
    expect(trackingLinked($order->number))->toBeFalse();

    config()->set('fulfillment-livewire.tracking_urls', 'https://example.test/track/:tracking');
    expect(trackingLinked($order->number))->toBeFalse();

    // And a template for a carrier this parcel is not on is not this parcel's.
    config()->set('fulfillment-livewire.tracking_urls', ['somebody-else' => 'https://example.test/track/:tracking']);
    expect(trackingLinked($order->number))->toBeFalse();
});

it('offers no link at all for a parcel with no carrier and no number', function () {
    [$order] = tracked($this, carrier: null, tracking: null);

    config()->set('fulfillment-livewire.tracking_urls', [HAULIER => 'https://example.test/track/:tracking']);

    expect(trackingLinked($order->number))->toBeFalse();
});

it('stops showing a tracking number for a parcel that never left', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    $demand = demandFor($order, [2]);
    $parcel = parcelFor($order, [$demand->lines[0]->orderLineId => 1], carrier: HAULIER, tracking: TRACKING);

    moveParcel($parcel, ShipmentStatus::Cancelled);

    // Cancelled is not "has left". The goods went back on the shelf and the
    // reservation was released; there is nothing for the shopper to track and
    // nothing for the number to identify.
    Livewire::test(OrderShipments::class, ['number' => $order->number])
        ->assertOk()
        ->assertDontSee(TRACKING)
        ->assertSee(__('module-ecommerce-fulfillment::fulfillment.state.cancelled'));
});
