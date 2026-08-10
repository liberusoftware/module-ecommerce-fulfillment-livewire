{{-- The page owns the <h1>, so the heading list is the page outline: one h1, then
     the parcels at h2, their sections at h3 and each parcel at h4.

     The page has already asked the ownership question in `mount()` and 404'd if
     the number names nothing of this shopper's, so this heading is never rendered
     above a child that is about to refuse — a page that draws its heading first
     has already told somebody that a number names something. --}}
<div>
    <h1>{{ __('module-ecommerce-fulfillment::fulfillment.heading') }}</h1>

    @php($order = $this->link('order', ['number' => $number]))
    @if ($order)
        <a href="{{ $order }}" data-parcels-order-link>{{ __('module-ecommerce-fulfillment::fulfillment.back_to_order', ['number' => $number]) }}</a>
    @endif

    <livewire:module-ecommerce-fulfillment::shipments :number="$number" />
</div>
