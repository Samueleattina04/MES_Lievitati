<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lotto del prodotto/semilavorato in uscita da una fase, inserito dall'operatore (§6).
 */
class LottoProdotto extends Model
{
    protected $table = 'lotti_prodotto';

    protected $fillable = [
        'fase_ordine_id',
        'articolo_codice',
        'lotto',
        'quantita',
        'creato_da_id',
        'client_uuid',
    ];

    protected function casts(): array
    {
        return ['quantita' => 'decimal:6'];
    }

    public function fase(): BelongsTo
    {
        return $this->belongsTo(FaseOrdine::class, 'fase_ordine_id');
    }

    public function creatoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creato_da_id');
    }
}
