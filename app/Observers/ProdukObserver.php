<?php

namespace App\Observers;

use App\Models\Produk;
use Illuminate\Support\Facades\Storage;

class ProdukObserver
{
    public function updated(Produk $produk): void
    {
        if ($produk->isDirty('gambar')) {
            $oldImage = $produk->getOriginal('gambar');

            if ($oldImage) {
                Storage::disk('public')->delete($oldImage);
            }
        }
    }

    public function deleted(Produk $produk): void
    {
        if (!is_null($produk->gambar)) {
            Storage::disk('public')->delete($produk->gambar);
        }
    }
}
