<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceOrder extends Model
{
    protected $table = 'orders';

    public $timestamps = false;

    protected $fillable = [
        'buyer_id', 'product_id', 'total', 'commission', 'seller_receives', 'status', 'stripe_session_id',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'commission' => 'decimal:2',
        'seller_receives' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}
