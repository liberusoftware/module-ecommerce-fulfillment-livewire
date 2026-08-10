<?php

/*
 * What this package is allowed to know about, proved rather than asserted.
 *
 * A presentation package presents exactly one domain module. It may reach that
 * module's published values and queries and nothing else — not a sibling commerce
 * module, not the application composing it, and not the outside world.
 *
 * Every check here is a **text grep over `src/`**, which is the thing worth
 * knowing about it: a docblock naming what this package does not import counts as
 * importing it, and a test that spells out a forbidden token puts that token in
 * the repository in order to go looking for it. So each assertion is written as
 * *what is allowed*, and the prose is worded around the names rather than through
 * them.
 */

/** @return array<string, string> every PHP file this package ships, by path. */
function packageSources(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $files;
}

/** @return array<string, mixed> */
function manifest(string $name): array
{
    $json = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/'.$name), true);

    return is_array($json) ? $json : [];
}

it('mentions one commerce module, and it is the one it presents', function () {
    preg_match_all('/Liberu\\\\Ecommerce\\\\(\w+)/', implode("\n", packageSources()), $mentioned);

    // Written as "every commerce namespace mentioned is this one", not as a list
    // of siblings to avoid. The package's own namespace sits under the domain
    // module's, so there is exactly one right answer and it is the same string
    // for both.
    expect(array_unique($mentioned[1]))->toBe(['Fulfillment']);
});

it('reaches the application it is installed into for nothing at all', function () {
    foreach (packageSources() as $path => $source) {
        expect($source)->not->toMatch('/(?:use|new|extends|implements)\s+App\\\\/', $path);
    }

    // The one class this package needs from the host — the model that records who
    // owns an order — is a string in configuration, resolved at call time. A
    // package that named it would install into exactly one application.
    expect(config('fulfillment-livewire.order.model'))->toBeString();
});

it('knows no carrier and no address on the internet', function () {
    foreach (packageSources() as $path => $source) {
        // A carrier is a string the host recorded and a tracking URL is a
        // template the host configured. Written generally — nothing in this
        // package addresses a host on the internet — rather than as a list of
        // carrier names, because a test that greps for seventeen brands is a file
        // containing seventeen brands.
        expect($source)->not->toMatch('#https?://[a-zA-Z0-9]#', $path);
    }

    // And the shipped map is empty: a default entry would be an opinion about
    // which carriers exist, and a release the day a merchant signs with somebody
    // else.
    expect(config('fulfillment-livewire.tracking_urls'))->toBe([]);
});

it('requires exactly the one package its manifest names', function () {
    $composer = manifest('composer.json');
    $module = manifest('module.json');

    $required = array_filter(
        array_keys($composer['require'] ?? []),
        fn (string $package): bool => str_starts_with($package, 'liberusoftware/'),
    );

    // The module manifest and the Composer manifest say the same thing, and there
    // is one entry: the domain module this package presents. Anything else in
    // this list would be a sibling domain, which is the import the fleet does not
    // have.
    expect(array_values($required))->toHaveCount(1)
        ->and(array_keys($module['requires']['packages'] ?? []))->toEqualCanonicalizing(array_values($required))
        // In `require-dev` as well, because the suite cannot run without it and a
        // consumer's tree should not depend on this package's dev tree.
        ->and($composer['require-dev'] ?? [])->toHaveKeys(array_values($required))
        // And Composer honours `repositories` only from the root manifest, so the
        // entry here works for this package's own CI and the host must add its
        // own. `docs/adoption.md` says so.
        ->and($composer['repositories'] ?? [])->not->toBeEmpty();
});

it('boots nothing on install', function () {
    $composer = manifest('composer.json');

    // Installing is not enabling. The host's module manager globs for
    // `module.json` and registers only what a deployment named.
    expect($composer['extra']['laravel']['providers'] ?? [])->toBe([])
        ->and($composer['version'])->toBe(manifest('module.json')['version'])
        ->and($composer['version'])->toMatch('/^\d+\.\d+\.\d+$/')
        ->and($composer['extra']['liberu']['name'])->toBe(manifest('module.json')['name']);
});
