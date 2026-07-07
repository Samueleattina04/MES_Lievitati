<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumoMaterialeLotto extends Model
{
    protected $table = 'consumo_materiale_lotti';

    protected $fillable = ['consumo_materiale_id', 'lotto', 'quantita'];

    protected function casts(): array
    {
        return ['quantita' => 'decimal:6'];
    }

    public function consumo(): BelongsTo
    {
        return $this->belongsTo(ConsumoMateriale::class, 'consumo_materiale_id');
    }
}
