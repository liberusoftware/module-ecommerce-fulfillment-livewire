{{-- Functional and theme-ready: structure and labels only. A theme publishes this
     view and owns the classes, tokens and layout. What it must not drop is the
     accessible plumbing — the live region, the button's text, the wire:keys, the
     <time datetime> — which is behaviour, not decoration.

     Three rules a published copy must keep:

     1. **The only state word on this page is `$this->state($parcel)`**, which is
        keyed by the domain's own enum. Progress is counters, not a state: an
        order can have goods gone, goods packed, goods called off and goods
        unpicked at the same instant, and no single word says that.
     2. **Nothing here writes.** The one control re-reads. There is no cancel
        button, because a parcel that has left cannot be un-sent by anybody and
        one that has not is the warehouse's to call off — and there is no void.
     3. **The tracking number stays here.** Not in a link to this site, not in a
        query string, not in the live region. The one link it may appear in is the
        carrier's own, from host configuration, and it carries rel="noreferrer" so
        the carrier does not learn which page the shopper came from. --}}
<div data-parcels>
    @if (! $this->signedIn())
        {{-- No lookup has been performed, so the answer does not depend on
             whether the number is real. --}}
        <p data-parcels-sign-in>{{ __('module-ecommerce-fulfillment::fulfillment.sign_in') }}</p>
    @else
        <h2>{{ __('module-ecommerce-fulfillment::fulfillment.order_heading', ['number' => $number]) }}</h2>

        {{-- One region, because there is one thing that can change: whether the
             page has been re-read. It never carries a tracking number — a live
             region is read out loud. --}}
        <p role="status" aria-live="polite">
            <span wire:loading data-parcels-loading>{{ __('module-ecommerce-fulfillment::fulfillment.loading') }}</span>
            <span data-parcels-announcement>{{ $announcement }}</span>
        </p>

        <h3>{{ __('module-ecommerce-fulfillment::fulfillment.progress.heading') }}</h3>

        <p data-parcels-progress>{{ $this->progressSentence() }}</p>

        {{-- A real button, so it is reachable and operable from the keyboard with
             no JavaScript of this package's own. Its label is its text. --}}
        <button type="button" wire:click="recheck" data-parcels-recheck>
            {{ __('module-ecommerce-fulfillment::fulfillment.recheck') }}
        </button>

        <h3>{{ __('module-ecommerce-fulfillment::fulfillment.parcel.heading') }}</h3>

        @php($parcels = $this->parcels())

        @if ($parcels === [])
            <p data-parcels-empty>{{ __('module-ecommerce-fulfillment::fulfillment.parcel.none') }}</p>
        @else
            {{-- One order, several parcels, several carriers, several days. The
                 list is the whole reason this surface exists: a single "your
                 order has been sent" line is the wrong shape. --}}
            <ol>
                @foreach ($parcels as $index => $parcel)
                    {{-- Keyed on the public reference, never the row id. Keyed so
                         focus survives a re-read. --}}
                    <li wire:key="parcel-{{ $parcel->reference }}" data-parcel="{{ $parcel->reference }}">
                        <h4>{{ __('module-ecommerce-fulfillment::fulfillment.parcel.label', ['position' => $index + 1, 'total' => count($parcels)]) }}</h4>

                        <p>
                            <span data-parcel-state>{{ $this->state($parcel) }}</span>
                            <span data-parcel-items>{{ $this->contents($parcel) }}</span>
                        </p>

                        <p>
                            <span>{{ __('module-ecommerce-fulfillment::fulfillment.parcel.reference') }}</span>
                            <span data-parcel-reference>{{ $parcel->reference }}</span>
                        </p>

                        @if ($parcel->carrier)
                            {{-- A string the host recorded. This package knows no
                                 carrier's name. --}}
                            <p>
                                <span>{{ __('module-ecommerce-fulfillment::fulfillment.parcel.carrier') }}</span>
                                <span data-parcel-carrier>{{ $parcel->carrier }}</span>

                                @if ($parcel->service)
                                    <span>{{ __('module-ecommerce-fulfillment::fulfillment.parcel.service') }}</span>
                                    <span data-parcel-service>{{ $parcel->service }}</span>
                                @endif
                            </p>
                        @endif

                        @if ($parcel->dispatchedAt)
                            <time datetime="{{ $parcel->dispatchedAt }}" data-parcel-dispatched>
                                {{ __('module-ecommerce-fulfillment::fulfillment.parcel.dispatched', ['date' => $this->on($parcel->dispatchedAt)]) }}
                            </time>
                        @endif

                        @if ($parcel->deliveredAt)
                            <time datetime="{{ $parcel->deliveredAt }}" data-parcel-delivered>
                                {{ __('module-ecommerce-fulfillment::fulfillment.parcel.delivered', ['date' => $this->on($parcel->deliveredAt)]) }}
                            </time>
                        @endif

                        @if ($parcel->cancelledAt)
                            <time datetime="{{ $parcel->cancelledAt }}" data-parcel-cancelled>
                                {{ __('module-ecommerce-fulfillment::fulfillment.parcel.cancelled', ['date' => $this->on($parcel->cancelledAt)]) }}
                            </time>
                        @endif

                        {{-- Evidence, shown to the shopper who owns this parcel
                             and rendered nowhere else. Absent entirely until the
                             goods have left, because before that it identifies
                             nothing anybody can look up. --}}
                        @php($tracking = $this->tracking($parcel))
                        @if ($tracking)
                            <p>
                                <span>{{ __('module-ecommerce-fulfillment::fulfillment.tracking.label') }}</span>
                                <span data-parcel-tracking>{{ $tracking }}</span>
                            </p>

                            @php($trackingUrl = $this->trackingUrl($parcel))
                            @if ($trackingUrl)
                                {{-- The carrier issued this number, so following
                                     the link tells it nothing it does not know —
                                     but rel="noreferrer" keeps it from learning
                                     the address of the page the shopper was on. --}}
                                <a href="{{ $trackingUrl }}" rel="noreferrer noopener" target="_blank" data-parcel-tracking-link>
                                    {{ __('module-ecommerce-fulfillment::fulfillment.tracking.link') }}
                                </a>
                            @endif
                        @endif

                        {{-- A parcel line is an order line id and a quantity. The
                             name is on the line of demand, and this is where the
                             two are put back together for a reader who wants
                             words. --}}
                        <p>{{ __('module-ecommerce-fulfillment::fulfillment.parcel.contents') }}</p>

                        <ul>
                            @foreach ($parcel->lines as $line)
                                <li wire:key="parcel-{{ $parcel->reference }}-line-{{ $line->id }}" data-parcel-line>
                                    <span data-parcel-line-quantity>{{ __('module-ecommerce-fulfillment::fulfillment.parcel.quantity', ['count' => $line->quantity]) }}</span>
                                    <span data-parcel-line-name>{{ $this->itemName($line->orderLineId) }}</span>
                                </li>
                            @endforeach
                        </ul>

                        {{-- This parcel's own destination, which is allowed to
                             differ from the address on the order and from the
                             other parcels': rerouting one box does not rewrite
                             what the invoice says. --}}
                        @if ($parcel->destination)
                            <p>{{ __('module-ecommerce-fulfillment::fulfillment.parcel.destination') }}</p>
                            <address data-parcel-destination>
                                @foreach ($parcel->destination as $part)
                                    @if (is_scalar($part) && (string) $part !== '')
                                        <span>{{ $part }}</span>
                                    @endif
                                @endforeach
                            </address>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- Named, not offered. There is no control on this page that could get
             sent goods back, because there is no such operation in this domain —
             and a button that opened a support form while looking like a
             cancellation would be the worst of both. --}}
        @if ($this->anythingSent())
            <h3>{{ __('module-ecommerce-fulfillment::fulfillment.returns.heading') }}</h3>

            <p data-parcels-returns>
                {{ __('module-ecommerce-fulfillment::fulfillment.returns.explain') }}

                @php($returns = $this->link('returns'))
                @if ($returns)
                    <a href="{{ $returns }}" data-parcels-returns-link>{{ __('module-ecommerce-fulfillment::fulfillment.returns.link') }}</a>
                @endif
            </p>
        @endif
    @endif
</div>
