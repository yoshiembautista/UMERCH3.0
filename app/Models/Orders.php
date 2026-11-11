<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Orders extends Model
{
    protected $table = '_orders';

    protected $primaryKey = 'order_id';

    protected $appends = ['receipt_form_url'];

    protected $fillable = [
        'user_id',
        'status',
        'receipt_form',
        'order_date',
        'payment_method',
        'fulfillment_method',
        'campus',
    ];

    /**
     * Full public URL for the uploaded payment receipt, including when the
     * public disk is backed by Cloudflare R2.
     */
    public function getReceiptFormUrlAttribute(): ?string
    {
        if (! $this->receipt_form) {
            return null;
        }

        if (str_starts_with($this->receipt_form, 'http://') || str_starts_with($this->receipt_form, 'https://')) {
            return $this->receipt_form;
        }

        return Storage::disk('public')->url($this->receipt_form);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItems::class, 'order_id', 'order_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id')->withDefault([
            'user_fullname' => 'Unknown',
            'email' => 'N/A',
            'um_id' => 'N/A',
        ]);
    }
}
