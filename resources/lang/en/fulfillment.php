<?php

return [

    'heading' => 'Your parcels',

    'order_heading' => 'Parcels for order :number',

    'back_to_order' => 'Back to order :number',

    'loading' => 'Working…',

    'sign_in' => 'Sign in to see where your parcels are.',

    'recheck' => 'Check again',

    'rechecked' => 'Checked just now.',

    /*
     * The four states the domain publishes, and there are four keys here for that
     * reason. `tests/Feature/ParcelTest.php` asserts this list and the domain's
     * own enum cases are the same set, so a fifth display string fails there
     * rather than never.
     *
     * The one somebody always asks for is the one that is missing. There is no
     * "void": a parcel that has been sent has been reported outward as fulfilled,
     * and a word whose meaning depends on whether the goods came back or never
     * left is not a state.
     */
    'state' => [
        'pending' => 'Packed, waiting to be collected',
        'dispatched' => 'On its way',
        'delivered' => 'Delivered',
        'cancelled' => 'Called off before it left',
    ],

    /*
     * Progress, in counters. None of these is a state word, and that is the
     * point: an order can have goods gone, goods in a box that has not gone,
     * goods called off and goods not yet picked, all at the same instant.
     */
    'progress' => [
        'heading' => 'Progress',
        'not_requested' => 'This order has not reached the warehouse yet. Nothing has been packed.',
        'nothing' => 'There is nothing on this order to send.',
        'all_sent' => 'Everything on this order has been sent.',
        'part_sent' => ':sent of :ordered items sent. :packed packed and waiting, :remaining still to pack.',
        'packed' => ':packed of :ordered items are packed and waiting to be collected.',
        'none_sent' => '{1}:remaining item is still to be packed.|[2,*]:remaining items are still to be packed.',
        'settled' => ':sent of :ordered items sent. The remaining :cancelled were called off.',
        'all_cancelled' => 'Nothing on this order was sent.',
    ],

    'parcel' => [
        'heading' => 'Parcels',
        'none' => 'Nothing has been packed for this order yet.',
        // Several parcels, several carriers, several days: a numbered list is the
        // shape of the answer, and "parcel 2 of 3" is how a shopper says it.
        'label' => 'Parcel :position of :total',
        'reference' => 'Parcel reference',
        'items' => '{0}No items|{1}:count item|[2,*]:count items',
        'carrier' => 'Carrier',
        'service' => 'Service',
        'dispatched' => 'Sent :date',
        'delivered' => 'Delivered :date',
        'cancelled' => 'Called off :date',
        'contents' => 'What is in it',
        'quantity' => ':count ×',
        'destination' => 'Going to',
    ],

    /*
     * Evidence, shown to the person it belongs to and put nowhere else. It is not
     * in the query string, not in the live region, not in a log, and not on this
     * page at all until the goods have actually left.
     */
    'tracking' => [
        'label' => 'Tracking number',
        'link' => 'Track this parcel with the carrier',
    ],

    /*
     * The answer to "can I have this back", which is not a button.
     *
     * A parcel that has been sent cannot be un-sent. Getting goods back after
     * they have arrived is a physical workflow with a refund decision attached,
     * it belongs to a module that is not built yet, and a control here would have
     * to lie about what pressing it did.
     */
    'returns' => [
        'heading' => 'Something already arrived?',
        'explain' => 'Once a parcel has been sent it is on its way and cannot be called off. If you want to send something back, that is a return.',
        'link' => 'How to return something',
    ],

];
