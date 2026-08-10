<?php

use Liberu\Ecommerce\Fulfillment\Livewire\Components\OrderShipments;
use Liberu\Ecommerce\Fulfillment\Livewire\Pages\ShipmentsPage;
use Livewire\Livewire;

/*
 * The plumbing a theme is not allowed to drop.
 *
 * These views ship structure and labels and no styling, on the assumption that a
 * deployment publishes and restyles them. What survives that is what is asserted
 * here: the heading outline, the live region, a real control with real text, and
 * a machine-readable date next to every human-readable one.
 */

/** A page of parcels for a signed-in shopper, rendered. */
function accessiblePage(mixed $test): string
{
    $user = shopper($test);

    $order = orderFor($user->getKey());
    $demand = demandFor($order, [2]);
    parcelFor($order, [$demand->lines[0]->orderLineId => 1], dispatched: true, carrier: 'haulier-one', tracking: 'AAA111');

    config()->set('fulfillment-livewire.tracking_urls', ['haulier-one' => 'https://example.test/track/:tracking']);

    return Livewire::test(ShipmentsPage::class, ['number' => $order->number])->assertOk()->html();
}

it('announces what changed in a region a screen reader will read', function () {
    $html = accessiblePage($this);

    // Polite, because nothing on a read-only surface is urgent enough to
    // interrupt somebody mid-sentence — and there is no refusal here to be
    // assertive about, because there is nothing to refuse.
    expect($html)->toContain('role="status"')
        ->and($html)->toContain('aria-live="polite"');
});

it('gives the one control a real element and real text', function () {
    $html = accessiblePage($this);

    preg_match_all('/<button[^>]*>(.*?)<\/button>/s', $html, $buttons);

    expect($buttons[0])->toHaveCount(1);

    foreach ($buttons[0] as $index => $button) {
        // A real button, so it is in the tab order and answers to Enter and Space
        // without a line of this package's JavaScript. Its accessible name is its
        // text, which is why the text may be reworded and may not be removed.
        expect($button)->toContain('type="button"')
            ->and(trim(strip_tags($buttons[1][$index])))->not->toBe('');
    }

    // No fields, because nothing here is filled in. A surface with no input is
    // the strongest version of "every field is labelled".
    expect($html)->not->toMatch('/<(?:input|select|textarea)\b/i');
});

it('keeps one heading outline down the page', function () {
    $html = accessiblePage($this);

    preg_match_all('/<h([1-6])\b/', $html, $levels);

    $levels = array_map('intval', $levels[1]);

    // One h1, on the page and not in the component, so a deployment can compose
    // the component under a heading of its own without two of them.
    expect(array_count_values($levels)[1] ?? 0)->toBe(1)
        ->and($levels[0])->toBe(1);

    // And no level is skipped on the way down, which is what makes heading
    // navigation an outline rather than a list.
    $deepest = 1;

    foreach ($levels as $level) {
        expect($level)->toBeLessThanOrEqual($deepest + 1);

        $deepest = max($deepest, $level);
    }
});

it('gives every link somewhere to go and something to say', function () {
    $html = accessiblePage($this);

    preg_match_all('/<a\s([^>]*)>(.*?)<\/a>/s', $html, $links);

    expect($links[0])->not->toBeEmpty();

    foreach ($links[1] as $index => $attributes) {
        // Never a `#` href. A link that announces itself as a link and then does
        // nothing is worse than no link, and this package treats an unregistered
        // route name as no link at all.
        expect($attributes)->toMatch('/href="[^"#][^"]*"/')
            ->and(trim(strip_tags($links[2][$index])))->not->toBe('');
    }
});

it('puts a machine-readable date beside every human-readable one', function () {
    $html = accessiblePage($this);

    preg_match_all('/<time\s([^>]*)>/', $html, $times);

    expect($times[0])->not->toBeEmpty();

    foreach ($times[1] as $attributes) {
        expect($attributes)->toMatch('/datetime="[^"]+"/');
    }
});

it('says the same thing to a shopper with no parcels as it does to one with some', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());

    // The empty state is a sentence, not an absence. A page that renders a
    // heading and then nothing leaves a screen reader user with no way of telling
    // "nothing yet" from "something went wrong".
    $html = Livewire::test(OrderShipments::class, ['number' => $order->number])->assertOk()->html();

    expect(trim(strip_tags($html)))->not->toBe('');
});
