<?php

use Liberu\Ecommerce\Fulfillment\Livewire\Components\OrderShipments;
use Liberu\Ecommerce\Fulfillment\Livewire\FulfillmentLivewireServiceProvider;
use Liberu\Ecommerce\Fulfillment\Livewire\Pages\ShipmentsPage;
use Liberu\Ecommerce\Fulfillment\Livewire\Support\ShopperContext;
use Liberu\Ecommerce\Fulfillment\Livewire\Tests\Fixtures\FixtureOrder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Whose parcels these are, and what stops them being somebody else's.
 *
 * This is a storefront: every component property travels to the browser and back
 * on every request, which makes every one of them an input written by a client.
 * The inventory below is asserted by reflection rather than described, because a
 * description is the thing that rots — a property added next year without an
 * attribute fails here rather than never.
 *
 * The shape of the answer is different here from the order surface, and the
 * difference is the whole design. An order carries the customer it belongs to, so
 * "your orders" can be a `where` clause. **A parcel carries nothing that names a
 * shopper** — the domain module holds an order id, an order number, a team and a
 * store — so ownership cannot be a clause on this package's queries. It is a
 * question, asked of the deployment, answered before the first read runs, and
 * refused by default.
 */

$components = fn (): array => array_values(new FulfillmentLivewireServiceProvider(app())->aliases());

/**
 * This package's own properties, not Livewire's.
 *
 * `getProperties()` walks the inheritance chain, and what the framework's base
 * class carries is the framework's business. The declaring class is what tells
 * the two apart, and traits are flattened into the component.
 *
 * @return array<int, ReflectionProperty>
 */
$ours = function (string $component): array {
    return array_values(array_filter(
        new ReflectionClass($component)->getProperties(),
        fn (ReflectionProperty $property): bool => ! str_starts_with($property->getDeclaringClass()->getName(), 'Livewire\\'),
    ));
};

/** @return array<string, string> every PHP file this package ships, by name. */
$sources = function (): array {
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[$file->getFilename()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $files;
};

it('carries no record identifier in any public surface', function () use ($components, $ours) {
    $found = [];

    foreach ($components() as $component) {
        foreach ($ours($component) as $property) {
            // Public only, because public is what a surface means here: Livewire
            // sends every public property to the browser and reads it back on the
            // next request. A private one never leaves the server — and the
            // resolved order id is deliberately private for exactly that reason,
            // since it is the thing the ownership question *returns* rather than
            // anything a client could hand in.
            if (! $property->isPublic()) {
                continue;
            }

            if (preg_match('/(^id$|Id$|customer|store|team)/i', $property->getName()) === 1) {
                $found[] = $component.'::$'.$property->getName();
            }
        }

        foreach (new ReflectionClass($component)->getMethod('mount')->getParameters() as $parameter) {
            if (preg_match('/(^id$|Id$|customer|store|team)/i', $parameter->getName()) === 1) {
                $found[] = $component.'::mount($'.$parameter->getName().')';
            }
        }
    }

    // Not "locked" — *absent*. An order's row id is what every read in the domain
    // module is keyed on, and it is the one thing that must never arrive from a
    // browser: it is resolved by the server, from a public number and an
    // authenticated actor together, or it is not resolved at all.
    expect($found)->toBe([]);
});

it('locks every public property, with no exceptions list', function () use ($components, $ours) {
    $unlocked = [];

    foreach ($components() as $component) {
        foreach ($ours($component) as $property) {
            if (! $property->isPublic() || $property->isStatic()) {
                continue;
            }

            if ($property->getAttributes(Locked::class) === []) {
                $unlocked[] = $component.'::$'.$property->getName();
            }
        }
    }

    // Every one, and there is nothing to argue about on a read-only surface:
    // there is no shopper-caused write anywhere in this domain, so there is no
    // property here that is the browser's to set. A writable public property on a
    // shopper-facing component is a client-controlled input whether or not
    // anybody meant it to be one.
    expect($unlocked)->toBe([]);
});

it('binds nothing at all to the URL', function () use ($components, $ours) {
    $bound = [];

    foreach ($components() as $component) {
        foreach ($ours($component) as $property) {
            if ($property->getAttributes(Url::class) !== []) {
                $bound[] = $component.'::$'.$property->getName();
            }
        }
    }

    // There is no filter, no sort and no search here, so nothing belongs in a
    // query string — and a query string is exactly where a value ends up in an
    // access log, a browser history and a `Referer` header. That matters more on
    // this surface than on most: the page is about a parcel whose tracking number
    // identifies it to a carrier.
    expect($bound)->toBe([]);
});

it('reads the domain only through values, and only ever keyed on the id ownership returned', function () use ($sources) {
    $source = implode("\n", $sources());

    // One entry point to the domain's reads, and every read through it takes the
    // id the ownership question resolved — not a number, not a reference, not
    // anything a browser sent. Written as "every argument is that variable"
    // rather than as a list of reads this package must not perform: spelling out
    // a forbidden name in an assertion puts that name in the repository in order
    // to go looking for it.
    preg_match_all('/\$query->\w+\(([^)]*)\)/', $source, $reads);

    expect($reads[1])->not->toBeEmpty()
        ->and(array_unique($reads[1]))->toBe(['$orderId']);

    // And nothing here holds an Eloquent model of the domain's. The read models
    // are what the domain publishes for exactly this, and a surface holding a
    // model holds its table name, its casts and its relations too.
    expect($source)->not->toContain('\\Models\\');
});

it('answers a stranger\'s order number exactly as it answers one that does not exist', function () {
    $user = shopper($this);

    $theirs = orderFor(STRANGER);
    $demand = demandFor($theirs);
    parcelFor($theirs, [$demand->lines[0]->orderLineId => 1], dispatched: true);

    $mine = orderFor($user->getKey());
    demandFor($mine);

    // Both 404, and the same 404. Livewire 4 answers with a Testable carrying
    // that status rather than by throwing, so the assertion is on the status.
    Livewire::test(OrderShipments::class, ['number' => $theirs->number])->assertStatus(404);
    Livewire::test(OrderShipments::class, ['number' => UNMINTED])->assertStatus(404);
    Livewire::test(OrderShipments::class, ['number' => ''])->assertStatus(404);

    // And the one that is mine renders, so the refusals above are about ownership
    // and not about the component being broken.
    Livewire::test(OrderShipments::class, ['number' => $mine->number])->assertOk();
});

it('gives a page the same answer as the component it composes', function () {
    $user = shopper($this);

    $theirs = orderFor(STRANGER);
    $mine = orderFor($user->getKey());

    // The page asks the ownership question itself in `mount()`. A page that
    // renders its heading and lets the child refuse has already told somebody
    // that a number names something.
    Livewire::test(ShipmentsPage::class, ['number' => $theirs->number])->assertStatus(404);
    Livewire::test(ShipmentsPage::class, ['number' => UNMINTED])->assertStatus(404);
    Livewire::test(ShipmentsPage::class, ['number' => $mine->number])->assertOk();
});

it('will not let the browser swap the order number it was handed', function () {
    $user = shopper($this);

    $mine = orderFor($user->getKey());
    demandFor($mine);
    $theirs = orderFor(STRANGER);

    // Asserted on the message rather than the class: Livewire has moved that
    // exception between namespaces across majors, and what this is about is the
    // refusal, not where the class lives.
    expect(fn () => Livewire::test(OrderShipments::class, ['number' => $mine->number])->set('number', $theirs->number))
        ->toThrow(Exception::class, 'Cannot update locked property: [number]');
});

it('would still find nothing if the lock were removed, because ownership is the control', function () {
    $user = shopper($this);

    $theirs = orderFor(STRANGER);
    $demand = demandFor($theirs);
    parcelFor($theirs, [$demand->lines[0]->orderLineId => 1], dispatched: true);

    // The lock stops the swap being attempted. This is the assertion that the
    // swap would not have worked anyway: the number is worth nothing until the
    // deployment says this signed-in shopper owns it, so a stranger's number
    // resolves to nothing whichever way it arrives — including this way, which is
    // not a way a browser has.
    $component = new OrderShipments();
    $component->number = $theirs->number;

    expect(fn (): mixed => $component->parcels())->toThrow(NotFoundHttpException::class)
        ->and($user->getKey())->not->toBe(STRANGER);
});

it('shows a guest the sign-in invitation rather than a 404 for a real number', function () {
    $order = orderFor(STRANGER);
    demandFor($order);

    // No lookup runs for a guest, so the answer cannot depend on whether the
    // number is real — which is the property that matters. A 404 for a real
    // number and a sign-in prompt for an invented one would be an oracle that
    // answers "does this order exist" to anybody who asks twice.
    Livewire::test(OrderShipments::class, ['number' => $order->number])
        ->assertOk()
        ->assertSee(__('module-ecommerce-fulfillment::fulfillment.sign_in'));

    Livewire::test(OrderShipments::class, ['number' => UNMINTED])
        ->assertOk()
        ->assertSee(__('module-ecommerce-fulfillment::fulfillment.sign_in'));

    Livewire::test(ShipmentsPage::class, ['number' => $order->number])->assertOk();
});

it('asks the deployment nothing at all when nobody is signed in', function () {
    $order = orderFor(STRANGER);

    // The actor is resolved *first*, and the ownership question is not asked
    // without one. That ordering is what keeps a carelessly overridden context —
    // one that forgot to use the customer id it was handed — from leaking to a
    // visitor who is not signed in, because nothing asks it anything.
    $spy = new class() extends ShopperContext
    {
        public int $asked = 0;

        public function orderIdFor(string $orderNumber, int $customerId): ?int
        {
            $this->asked++;

            return null;
        }
    };

    app()->instance(ShopperContext::class, $spy);

    Livewire::test(OrderShipments::class, ['number' => $order->number])->assertOk();
    Livewire::test(ShipmentsPage::class, ['number' => $order->number])->assertOk();

    expect($spy->asked)->toBe(0);
});

it('refuses everything while the deployment has not said where ownership is recorded', function () {
    $user = shopper($this);

    $mine = orderFor($user->getKey());
    demandFor($mine);

    // The shipped default. There is no column in this domain naming a shopper, so
    // an unconfigured install cannot answer the ownership question — and the
    // answer to a question it cannot answer is no. A package that defaulted the
    // other way would show a stranger's parcels to anybody who had not finished
    // configuring it.
    ownership(null);

    Livewire::test(OrderShipments::class, ['number' => $mine->number])->assertStatus(404);

    expect(app(ShopperContext::class)->orderIdFor($mine->number, (int) $user->getKey()))->toBeNull();

    // A configured class that is not a model, and one that does not exist, are
    // both refusals rather than errors.
    ownership('Not\\A\\Class\\At\\All');
    expect(app(ShopperContext::class)->orderIdFor($mine->number, (int) $user->getKey()))->toBeNull();

    ownership(ShopperContext::class);
    expect(app(ShopperContext::class)->orderIdFor($mine->number, (int) $user->getKey()))->toBeNull();
});

it('never matches an order that belongs to no account', function () {
    $user = shopper($this);

    $orphan = orderFor(null);
    demandFor($orphan);

    // `where('customer_id', null)` compiles to `is null`, which would answer with
    // precisely the orders belonging to nobody — the leak written as a tautology.
    // The customer id here is an integer the caller could not have made null, and
    // the empty number is refused before any query is built at all.
    Livewire::test(OrderShipments::class, ['number' => $orphan->number])->assertStatus(404);

    expect(app(ShopperContext::class)->orderIdFor($orphan->number, (int) $user->getKey()))->toBeNull()
        ->and(app(ShopperContext::class)->orderIdFor('', (int) $user->getKey()))->toBeNull();
});

it('addresses a parcel in its markup by the reference and never by the row id', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    $demand = demandFor($order, [2]);
    $parcel = parcelFor($order, [$demand->lines[0]->orderLineId => 1], dispatched: true);

    preg_match_all('/data-parcel="([^"]+)"/', Livewire::test(OrderShipments::class, ['number' => $order->number])->html(), $handles);

    // The handle a key is built from is the public reference the domain minted
    // from the CSPRNG. An incrementing id on a customer-facing page is an
    // enumeration of everybody's parcels, which is the entire argument that gave
    // a shipment a reference.
    expect($handles[1])->toBe([$parcel->reference])
        ->and($parcel->reference)->not->toBe((string) $parcel->id);
});

it('renders nothing about the customer or the team it scoped on', function () {
    $user = shopper($this);

    $order = orderFor($user->getKey());
    $demand = demandFor($order);
    parcelFor($order, [$demand->lines[0]->orderLineId => 1], dispatched: true);

    $html = Livewire::test(OrderShipments::class, ['number' => $order->number])->html();

    // The team a parcel belongs to is the merchant's internal tenancy, and the
    // customer id is the thing the whole page was scoped on. Neither is the
    // shopper's business and neither is on the page.
    expect($html)->not->toContain('customer')
        ->and($html)->not->toContain($user->email)
        ->and($html)->not->toContain('9000007')
        ->and($html)->not->toContain((string) $order->id);
});

it('lets a deployment answer the whole identity question for itself', function () {
    $order = orderFor(STRANGER);
    $demand = demandFor($order);
    parcelFor($order, [$demand->lines[0]->orderLineId => 1], dispatched: true);

    // A deployment whose customers are not its users — a CRM contact keyed
    // separately from the login — rebinds one class and every read follows,
    // because nothing else in this package asks who the shopper is or which order
    // is theirs. Configuration is the default, not the only way.
    shopper($this);
    ownership(null);

    app()->singleton(ShopperContext::class, fn (): ShopperContext => new class() extends ShopperContext
    {
        public function orderIdFor(string $orderNumber, int $customerId): ?int
        {
            $id = FixtureOrder::query()->where('number', $orderNumber)->value('id');

            return is_numeric($id) ? (int) $id : null;
        }
    });

    Livewire::test(OrderShipments::class, ['number' => $order->number])->assertOk()->assertSee($order->number);
});
