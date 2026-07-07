<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Materiale atteso di una fase = figlio diretto del nodo nella distinta (§3).
 */
class MaterialeFase extends Model
{
    protected $table = 'materiali_fase';

    protected $fillable = [
        'fase_ordine_id',
        'articolo_codice',
        'descrizione',
        'quantita_pianificata',
        'udm',
        'flag_lotto',
        'e_semilavorato',
        'fase_produttrice_id',
        'posizione',
    ];

    protected function casts(): array
    {
        return [
            'quantita_pianificata' => 'decimal:6',
            'flag_lotto' => 'boolean',
            'e_semilavorato' => 'boolean',
            'posizione' => 'integer',
        ];
    }

    public function fase(): BelongsTo
    {
        return $this->belongsTo(FaseOrdine::class, 'fase_ordine_id');
    }

    /** Se semilavorato: la fase che lo produce (da cui deriva il lotto). */
    public function faseProduttrice(): BelongsTo
    {
        return $this->belongsTo(FaseOrdine::class, 'fase_produttrice_id');
    }

    public function consumo(): HasOne
    {
        return $this->hasOne(ConsumoMateriale::class, 'materiale_fase_id');
    }
}
