<?php

namespace Liberu\Ecommerce\Fulfillment\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\Fulfillment\Livewire\Components\OrderShipments;
use Liberu\Ecommerce\Fulfillment\Livewire\Pages\ShipmentsPage;
use Liberu\Ecommerce\Fulfillment\Livewire\Support\ShopperContext;
use Livewire\Livewire;

/**
 * Registers this package's bounded Livewire namespace and the one class that
 * answers whose order this is.
 *
 * Registered by `ModuleManagerServiceProvider` from `module.json`, never by
 * Composer discovery — the package ships no `extra.laravel.providers`, so
 * installing it boots nothing until a deployment names the module in
 * `MODULES_ENABLED`.
 *
 * **Nothing here subscribes to anything.** This package renders, and it causes no
 * change to anything at all: there is no shopper-caused write in this domain. The
 * place a storefront is tempted to add a listener — a dispatch notification — is
 * the host's, because the event belongs to the domain module and a presentation
 * package reacting to it would be a second place that decision lives.
 *
 * Aliases are explicit rather than discovered. A directory scan resolves whatever
 * happens to be on disk, so moving a class or adding one would silently change a
 * public interface; this list *is* the interface, and changing it is a diff
 * somebody reviews.
 */
class FulfillmentLivewireServiceProvider extends ServiceProvider
{
    /**
     * The one namespace this package owns, for components, views and
     * translations alike. It drops the `-livewire` suffix and keeps the
     * ownership prefix: it names the bounded context, not the technology
     * presenting it, and the other presentation flavours for this domain answer
     * to the same one.
     */
    public const NAMESPACE = 'module-ecommerce-fulfillment';

    /**
     * The package's public component surface.
     *
     * @var array<string, class-string>
     */
    private const COMPONENTS = [
        'shipments' => OrderShipments::class,
        'shipments-page' => ShipmentsPage::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fulfillment-livewire.php', 'fulfillment-livewire');

        // A singleton, and swappable. The domain module holds no column naming a
        // shopper, so ownership is a question only the deployment can answer;
        // rebinding this class answers it in code instead of in configuration,
        // and every read in the package follows, because nothing else here asks
        // who the shopper is.
        $this->app->singleton(ShopperContext::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', self::NAMESPACE);
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', self::NAMESPACE);

        $aliases = $this->aliases();

        // Two halves of the same registration, and both are needed.
        //
        // `component()` is the name a class reports as — what a rendered
        // component calls itself, and what `Livewire::test(SomeClass::class)`
        // resolves back to.
        //
        // `resolveMissingComponent()` is the other direction, and it is the one
        // that costs an afternoon if it is missing. Livewire 4's
        // `Finder::resolveClassComponentClassName()` returns null for a
        // `namespace::name` *before* it consults the explicit registry, so
        // `component()` alone never answers one. `addNamespace()` does answer,
        // but it maps one Livewire namespace onto exactly one class namespace —
        // and this package deliberately has two, `Components\` and `Pages\`,
        // because a reusable component and a routable page are different things.
        foreach ($aliases as $alias => $component) {
            Livewire::component($alias, $component);
        }

        Livewire::resolveMissingComponent(
            static fn (string $name): ?string => $aliases[$name] ?? null,
        );

        // Publishing views is how a theme overrides one without forking the
        // package. Translations publish separately, because a deployment that
        // wants its own wording rarely wants its own markup as well.
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/'.self::NAMESPACE),
        ], self::NAMESPACE.'-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/'.self::NAMESPACE),
        ], self::NAMESPACE.'-translations');

        $this->publishes([
            __DIR__.'/../config/fulfillment-livewire.php' => config_path('fulfillment-livewire.php'),
        ], self::NAMESPACE.'-config');
    }

    /**
     * The component table, keyed by the fully qualified alias.
     *
     * @return array<string, class-string>
     */
    public function aliases(): array
    {
        $aliases = [];

        foreach (self::COMPONENTS as $alias => $component) {
            $aliases[self::NAMESPACE.'::'.$alias] = $component;
        }

        return $aliases;
    }
}
