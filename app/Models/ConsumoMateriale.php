<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Consumo effettivo registrato dall'operatore per un materiale di fase (§5, §6).
 */
class ConsumoMateriale extends Model
{
    protected $table = 'consumi_materiale';

    protected $fillable = [
        'materiale_fase_id',
        'quantita_effettiva',
        'confermato_da_id',
        'confermato_at',
        'client_uuid',
    ];

    protected function casts(): array
    {
        return [
            'quantita_effettiva' => 'decimal:6',
            'confermato_at' => 'datetime',
        ];
    }

    public function materiale(): BelongsTo
    {
        return $this->belongsTo(MaterialeFase::class, 'materiale_fase_id');
    }

    public function confermatoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confermato_da_id');
    }

    /** Righe multi-lotto: ripartizione della quantita' su piu' lotti (§6). */
    public function lotti(): HasMany
    {
        return $this->hasMany(ConsumoMaterialeLotto::class, 'consumo_materiale_id');
    }
}
