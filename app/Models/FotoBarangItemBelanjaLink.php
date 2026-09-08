<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FotoBarangItemBelanjaLink extends Model
{
    protected $fillable = [
        'foto_barang_item_id',
        'pengeluaran_belanja_item_id',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(FotoBarangItem::class, 'foto_barang_item_id');
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PengeluaranBelanjaItem::class, 'pengeluaran_belanja_item_id');
    }
}
