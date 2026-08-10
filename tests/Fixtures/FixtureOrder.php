<?php

namespace Liberu\Ecommerce\Fulfillment\Livewire\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * An order, shaped the way a host's is, and belonging to this suite.
 *
 * The package under test holds no order table and no customer column — the domain
 * module it presents files a parcel against an order id and nothing else. So the
 * ownership question is answered by a model the *deployment* names, and this is
 * the deployment's model for the length of a test: three columns, a public number
 * and the customer it is filed against, which is all `Support\ShopperContext` ever
 * asks of one.
 *
 * Using a fixture rather than a stub context is deliberate. It is the shipped
 * default resolver that runs in almost every test here, over a real query against
 * a real table, because that is the code a first deployment will be relying on.
 *
 * @property int $id
 * @property string $number
 * @property int|null $customer_id
 */
class FixtureOrder extends Model
{
    protected $table = 'fixture_orders';

    protected $fillable = ['id', 'number', 'customer_id'];

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'customer_id' => 'integer',
    ];
}
