<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riga della distinta esplosa "congelata" per un ordine (snapshot audit, §4.1).
 */
class DistintaRiga extends Model
{
    protected $table = 'distinta_righe';

    protected $fillable = [
        'ordine_id',
        'articolo_padre_codice',
        'articolo_figlio_codice',
        'descrizione',
        'quantita',
        'qta_per_unita',
        'udm',
        'livello_relativo',
        'posizione',
        'e_nodo_prodotto',
    ];

    protected function casts(): array
    {
        return [
            'quantita' => 'decimal:6',
            'qta_per_unita' => 'decimal:6',
            'livello_relativo' => 'integer',
            'posizione' => 'integer',
            'e_nodo_prodotto' => 'boolean',
        ];
    }

    public function ordine(): BelongsTo
    {
        return $this->belongsTo(OrdineProduzione::class, 'ordine_id');
    }
}
