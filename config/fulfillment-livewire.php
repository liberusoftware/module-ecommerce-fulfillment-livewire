<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Whose order is it
    |--------------------------------------------------------------------------
    |
    | This is the whole of this package's security model, and it is here rather
    | than in the code because **the domain module this package presents does not
    | know who a customer is.** A parcel is filed against an order id and a team
    | id; there is no column on any of its four tables that names a shopper. So
    | the question "is this order yours" cannot be answered inside this package,
    | and a package that answered it anyway would be guessing at the one question
    | that must not be guessed.
    |
    | The deployment answers it, by naming the model that owns orders and the two
    | columns on it. The class is a string resolved at call time and never
    | imported: a presentation package that named another module's model in a
    | `use` statement would be an import of that module, and this package requires
    | only its own domain.
    |
    | Leave `model` unset and every read refuses. That is the deliberate default —
    | closed, not open — and it means a fresh install shows a shopper nothing
    | until somebody has said where ownership is recorded. See
    | `docs/adoption.md` §3 for the one line, and for the alternative: a
    | deployment whose customers are not its users rebinds
    | `Support\ShopperContext` and answers the question in code instead.
    |
    | `number_column` is the order's **public reference**, not its primary key. An
    | incrementing id in a customer-facing URL is an enumeration of everybody
    | else's orders, which is the reason the orders domain mints a number at all.
    |
    */

    'order' => [

        'model' => env('FULFILLMENT_LIVEWIRE_ORDER_MODEL'),

        'number_column' => env('FULFILLMENT_LIVEWIRE_ORDER_NUMBER_COLUMN', 'number'),

        'customer_column' => env('FULFILLMENT_LIVEWIRE_ORDER_CUSTOMER_COLUMN', 'customer_id'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Where a carrier's own tracking page lives
    |--------------------------------------------------------------------------
    |
    | Keyed by the carrier string the host recorded on the parcel — whatever its
    | own integration calls that carrier. There is no default map and no default
    | entry, because a package that shipped one would have an opinion about which
    | carriers exist, and it would have to be released the day a merchant signs
    | with somebody else. The domain module holds no carrier name either.
    |
    | Each value is a URL template containing `:tracking`. A template that is not
    | an `https://` URL containing that placeholder is ignored rather than
    | rendered, so a misconfiguration produces a tracking number with no link
    | instead of a control that goes somewhere unexpected.
    |
    | The link is rendered with `rel="noreferrer"`. The carrier already knows the
    | tracking number — it issued it — but it has no business learning the URL of
    | the page the shopper came from.
    |
    |     'tracking_urls' => [
    |         'the-name-your-integration-uses' => 'https://example.test/track/:tracking',
    |     ],
    |
    */

    'tracking_urls' => [],

    /*
    |--------------------------------------------------------------------------
    | Routes this package links to
    |--------------------------------------------------------------------------
    |
    | Route names, because routes belong to the application composing this
    | package. An unregistered name is treated as no link at all — a `#` href is
    | a control that announces itself as a link and then does nothing.
    |
    | `returns` is where a shopper is sent when something has already arrived and
    | they want to send it back. **There is no cancellation on this surface and
    | there is no void.** A parcel that has been dispatched has been reported
    | outward as fulfilled, and getting those goods back is a physical workflow
    | with a refund decision attached; it belongs to the returns module, which is
    | not built yet. Until it is, this is a human. Leave it unset and the sentence
    | is still said, without a link.
    |
    | `order` receives the order's number, and turns the parcel page's heading
    | into a way back to the order it is about.
    |
    */

    'routes' => [

        'returns' => env('FULFILLMENT_LIVEWIRE_RETURNS_ROUTE'),

        'order' => env('FULFILLMENT_LIVEWIRE_ORDER_ROUTE'),

    ],

];
