<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoArticolo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Cache locale dell'anagrafica articoli letta dal gestionale (§5). Aggiornata dall'adapter
 * alla creazione ordine. Gli attributi solo-MES stanno in ArticoloConfigurazioneMes.
 */
class Articolo extends Model
{
    protected $table = 'articoli';

    protected $fillable = ['codice', 'descrizione', 'udm', 'udm_tecnica', 'tipo', 'flag_lotto'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoArticolo::class,
            'flag_lotto' => 'boolean',
        ];
    }

    public function configurazioneMes(): HasOne
    {
        return $this->hasOne(ArticoloConfigurazioneMes::class, 'articolo_codice', 'codice');
    }

    /** flag_lotto effettivo: override MES se presente, altrimenti valore da gestionale. */
    public function richiedeLotto(): bool
    {
        $override = $this->configurazioneMes?->flag_lotto_override;

        return $override ?? $this->flag_lotto;
    }
}
