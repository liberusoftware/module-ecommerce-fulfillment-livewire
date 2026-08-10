<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\Fulfillment\Livewire\Components\OrderShipments;
use Liberu\Ecommerce\Fulfillment\Livewire\FulfillmentLivewireServiceProvider;
use Liberu\Ecommerce\Fulfillment\Livewire\Pages\ShipmentsPage;
use Liberu\Ecommerce\Fulfillment\Livewire\Support\ShopperContext;
use Livewire\Livewire;

it('answers to its namespaced component names', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    demandFor($order);

    // Both directions of the registration, and the second is the one that costs
    // an afternoon when it is missing: Livewire 4 resolves a class component's
    // name before it consults the explicit registry, and returns null for a
    // `namespace::name` unless something answers for missing components. A
    // namespace map would not do here either — this package has two class
    // namespaces behind one Livewire namespace, because a reusable component and
    // a routable page are different things.
    foreach (array_keys(new FulfillmentLivewireServiceProvider(app())->aliases()) as $alias) {
        Livewire::test($alias, ['number' => $order->number])->assertOk();
    }
});

it('reports each component under the name it was registered as', function () {
    $aliases = new FulfillmentLivewireServiceProvider(app())->aliases();

    expect($aliases)->toBe([
        FulfillmentLivewireServiceProvider::NAMESPACE.'::shipments' => OrderShipments::class,
        FulfillmentLivewireServiceProvider::NAMESPACE.'::shipments-page' => ShipmentsPage::class,
    ]);

    // The namespace drops the technology and keeps the bounded context: the
    // Filament and API flavours for this domain answer to the same one, and a
    // theme that has overridden a view should not have to know which of them
    // rendered it.
    expect(FulfillmentLivewireServiceProvider::NAMESPACE)->not->toContain('livewire');
});

it('publishes its views, translations and configuration under one name', function () {
    $groups = ServiceProvider::$publishGroups;

    foreach (['-views', '-translations', '-config'] as $suffix) {
        expect($groups)->toHaveKey(FulfillmentLivewireServiceProvider::NAMESPACE.$suffix);
    }

    // A theme overrides a view by publishing it; a deployment that wants its own
    // wording rarely wants its own markup as well, so the two publish separately.
    expect(config('fulfillment-livewire.routes.returns'))->toBeNull()
        ->and(config('fulfillment-livewire.order.number_column'))->toBe('number')
        ->and(config('fulfillment-livewire.order.customer_column'))->toBe('customer_id');
});

it('resolves one shopper context, and lets it be replaced', function () {
    expect(app(ShopperContext::class))->toBe(app(ShopperContext::class));

    // Not final, and a singleton, because the ownership question is the one thing
    // a deployment may have to answer in code — and when it does, every read in
    // the package follows, since nothing else here asks who the shopper is.
    expect(new ReflectionClass(ShopperContext::class)->isFinal())->toBeFalse();
});

it('treats an unregistered route name as no link at all', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    $demand = demandFor($order);
    parcelFor($order, [$demand->lines[0]->orderLineId => 1], dispatched: true);

    config()->set('fulfillment-livewire.routes.returns', 'a.route.nobody.registered');

    // A `#` href is a control that announces itself as a link and then does
    // nothing. Routes belong to the application composing this package, so a name
    // it has not registered is treated as none.
    $html = Livewire::test(OrderShipments::class, ['number' => $order->number])->assertOk()->html();

    expect($html)->toContain(__('module-ecommerce-fulfillment::fulfillment.returns.explain'))
        ->and($html)->not->toContain('data-parcels-returns-link');

    Route::get('/returns', fn (): string => 'returns')->name('a.route.nobody.registered');

    expect(Livewire::test(OrderShipments::class, ['number' => $order->number])->html())
        ->toContain('data-parcels-returns-link');
});
