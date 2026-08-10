# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this package adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-10

First release. Livewire 4 storefront parcel tracking for
`liberusoftware/ecommerce-fulfillment`.

### Added

- `module-ecommerce-fulfillment::shipments` — every parcel raised against one of
  the shopper's own orders, with progress, evidence and per-parcel destinations.
- `module-ecommerce-fulfillment::shipments-page` — the routable page.
- `Support\ShopperContext` — the one place the ownership question is asked,
  answerable by configuration or by rebinding the class.
- Progress built from the domain's reservation and dispatch counters, in six
  sentences and no state words.
- Parcel states rendered from the domain's own enum, asserted to be the same set.
- A carrier tracking link built from a host-configured template, validated before
  it is rendered and carrying `rel="noreferrer"`.
- Theme-overridable views, translations and configuration.

### Decided

- **Ownership is answered by the deployment and refused until it is.** The domain
  module holds no column naming a shopper, so this package asks rather than
  guesses, and its unconfigured state shows nobody anything.
- **A stranger's order number and one that was never minted get the same 404.** A
  signed-out visitor gets the invitation to sign in instead, so the response
  cannot be used to find out whether an order exists.
- **The tracking number is rendered to the parcel's owner once the goods have
  left, and appears nowhere else** — not as a property, not in the URL, not in the
  live region, not in a log.
- **Read-only.** There is no shopper-caused write in this domain; the one action
  re-reads. There is no cancellation and no void, and a shopper asking for sent
  goods back is pointed at the returns route rather than offered a button.
- **No carrier name and no address on the internet anywhere in `src/`.**

[0.1.0]: https://github.com/liberusoftware/module-ecommerce-fulfillment-livewire/releases/tag/0.1.0
