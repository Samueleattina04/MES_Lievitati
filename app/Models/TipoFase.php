<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Template di lavorazione: sequenza ordinata di step (ciascuno su un reparto) associabile
 * a un articolo prodotto. Consente la "fase che attraversa piu' reparti" (§3).
 */
class TipoFase extends Model
{
    protected $table = 'tipi_fase';

    protected $fillable = ['codice', 'descrizione'];

    public function steps(): HasMany
    {
        return $this->hasMany(TipoFaseStep::class)->orderBy('ordine');
    }
}
