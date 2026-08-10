# What this surface decides

The domain module argues about goods leaving a warehouse. This package argues
about one much narrower thing: **what a shopper is shown about goods that are
theirs, and what stops them being shown somebody else's.** Nothing here is a
summary of the code; it is the argument the code implements.

---

## 1. Identity, which is the whole job

### The problem this surface has and the order surface does not

The order surface for this fleet answers "your orders" with a `where` clause. It
can, because an order carries the customer it was filed against.

**A parcel carries nothing that names a shopper.** The domain module's four tables
hold an order id, an order number, a team id and a store id, and that is
deliberate rather than an oversight: a parcel is a fact about goods leaving a
warehouse, the customer relationship lives on the order, and a second copy of it
here would be a second thing to keep in step and a second thing to get wrong.

So there is no clause to narrow on, and the ownership question cannot be folded
into this package's queries. It has to be asked and answered **before the first
read runs**.

### Three ways it could have been answered, and why two are wrong

**By the order number alone.** The number is minted from 48 bits of CSPRNG output
precisely so it cannot be guessed — but knowing a reference is not owning the
thing it names. A number travels in emails, in screenshots, in a support ticket
and in a shared browser history. A surface that treated possession of one as proof
would be a surface anybody can read by asking someone for their order number.

**By reaching into the module that owns orders.** That is an import, and this
fleet's presentation packages do not import sibling domains. It would also make
this package installable into exactly one shape of application.

**By asking the deployment.** Which is what it does. `Support\ShopperContext`
publishes one question — *does this signed-in customer own the order this number
names, and if so what is its id* — and the deployment answers it, either by naming
the model that records ownership or by rebinding the class.

### The default is closed

Unanswered means refused. Every read fails and the shopper is shown nothing.

The alternative — showing parcels to anybody until somebody finishes configuring
the package — is a package whose least-configured state is its most dangerous one.
This one's least-configured state is useless, which is a bug somebody reports on
the first afternoon rather than a leak nobody reports at all.

### The order of operations is the control

```
customerId()  →  null?  →  invitation to sign in. Nothing else runs.
              →  int   →  orderIdFor(number, customerId)
                          →  null?  →  404
                          →  int   →  the domain's reads, keyed on that id
```

Three properties fall out of that ordering and each is asserted:

**The actor is resolved first, so a careless override cannot leak to a guest.** A
deployment that overrode `orderIdFor()` and forgot to use the customer id it was
handed would still show a signed-out visitor nothing, because nothing asks it
anything.

**An order id never arrives from a browser.** It is what the server resolved from
a public number and an authenticated actor together. The domain's reads are all
keyed on it, and this is the only door it comes through.

**Not found and not yours are the same answer.** Both are null here and both are a
404 above. Telling them apart — "that order exists but is not yours" — is how a
caller learns which order numbers exist, and it is exactly the information that
turns a guessed number into a confirmed one.

### A guest is not 404'd

Nothing is looked up for them, so the answer cannot depend on whether the number
is real. A 404 for a real number and a sign-in prompt for an invented one would be
an oracle: ask twice, and the difference between the answers tells you whether an
order exists.

### `where('col', null)` is `is null`

The customer id passed into the ownership question is an `int`, so the clause
built from it cannot become `is null` — which is the query that would answer with
precisely the orders belonging to nobody, the leak written as a tautology. An
empty order number is refused before any query is built at all.

### Locked, and the lock is the second control

Every public property carries `#[Locked]` and nothing is bound to the URL. On a
shopper-facing component every property travels to the browser and back on every
request, which makes each one an input written by a client whether or not anybody
meant it to be.

But the lock is not what protects the parcels. The number is worth nothing until
the deployment says this shopper owns it, so a swapped number resolves to nothing
whichever way it arrives — including by constructing the component in a test and
setting the property directly, which is a thing a browser cannot do and which the
suite does anyway.

---

## 2. The tracking number

A tracking number identifies a parcel to a carrier. Anybody holding one can ask
that carrier where a named person's goods are and when they will be there.

The domain module treats it as evidence: not indexed, absent from every query,
absent from the telemetry log, absent from the read model's `toArray()`, excluded
from the idempotency hash, and `$hidden` on the model. It is reachable as one
property, for one reader.

**This package is that reader**, and the decision here is where it may appear:

| | |
| --- | --- |
| In the markup, to the owner | **Yes**, once the goods have left. It is the number they need to give a carrier, and withholding it would make the page useless for the one thing it is for. |
| In the markup, before dispatch | **No.** A label may exist before a carrier has the goods; the number identifies nothing anybody can look up yet, so showing it early is a screenshot risk in exchange for nothing. |
| As a component property | **No.** A property travels to the browser and back in the snapshot on every request. |
| In the URL | **No.** Query strings end up in access logs, browser history and `Referer` headers. |
| In the live region | **No.** A live region is read out loud. |
| In a log | **No.** Nothing here logs. |

The one link it may appear in is the carrier's own, built from a template the host
configured against the carrier string the host recorded. The carrier issued the
number, so following the link tells it nothing it does not already know — and
`rel="noreferrer"` keeps it from learning the address of the page the shopper came
from. A template that is not an `https` URL containing the placeholder is ignored
rather than rendered: a misconfiguration should cost a link, never produce a
control that goes somewhere unexpected.

---

## 3. Progress, which is not a status

The domain keeps two counters and refuses a status word on a line of demand:

```
committed   a reservation. Falls when a parcel that never left is cancelled.
dispatched  a fact. Never falls.
```

A line of five can be two dispatched, one sitting in a packed parcel, one
cancelled and one still to pick at the same instant, and no single word says that.

So the sentence on this page is assembled from the counters, and there are six of
them because there are six genuinely different situations — everything gone,
something gone and something still coming, nothing gone but something packed,
nothing packed at all, everything settled with part of it called off, and nothing
sent at all. Inventing a seventh word like *partially shipped* would be this
package publishing a state the domain refused, in front of the customer.

The state words that *do* appear belong to a parcel. There are four, they are
keyed by the domain's own enum, and the suite asserts the translated set and the
enum's cases are the same set — so a fifth fails there rather than never.

**The missing one is the interesting one.** There is no *void*. A parcel that has
been dispatched cannot be cancelled, because it is in the world and the quantity
has already been reported outward as fulfilled; a word whose meaning depends on
whether the goods came back or never left is not a state.

---

## 4. Cardinality is the reason this surface exists

One order, several parcels, several carriers, several days. The domain models it
that way because the host's `orders` table grew four carrier columns and each of
them was wrong from the second parcel onwards.

A presentation package that then rendered "your order has been sent" would put the
same mistake back at the last possible moment — after the schema had been fixed to
prevent it. So the page is a **numbered list of parcels**, each with its own state,
its own carrier, its own dates, its own evidence and its own destination.

The destination is per parcel for the same reason. Rerouting a box to a neighbour
or a locker changes where *that box* goes and must not rewrite what the invoice
says the customer asked for — and the shopper is the person who most needs to see
that the second parcel is going somewhere different from the first.

---

## 5. Read-only, on purpose, and the refusal has a route attached

There is no shopper-caused write in this domain at all, so there is no action here
except one that re-reads.

That leaves one question to answer well: *something has arrived and I want to send
it back.* The temptation is a button. A button here would either lie — writing
nothing while looking like a cancellation — or reach into a module that does not
exist yet.

So the answer names the route instead. When something has actually left, the page
says that a sent parcel cannot be called off, that sending something back is a
return, and where returns are handled in this deployment. Before anything has
left, it says nothing at all about returns, because goods that have not gone can
still be called off by the warehouse that packed them and telling a shopper
otherwise would be wrong in the other direction.

---

## 6. What crosses the boundary

This package presents one domain module and imports nothing else. It reads that
module through its published queries and read models — never an Eloquent model,
which would drag a table name, casts and relations across with it.

The boundary check is a **text grep over `src/`**, and a docblock naming what this
package does not import counts as importing it. So the assertion is written as
*every commerce namespace mentioned is this one*, and the prose is worded around
the names rather than through them. The same applies to carriers: the check is
that nothing here addresses a host on the internet at all, rather than a list of
seventeen brands — a test that greps for brand names is a file containing brand
names.

The one class this package needs from the application it is installed into — the
model that records who owns an order — is a string in configuration, resolved at
call time, and named nowhere in `src/`.
