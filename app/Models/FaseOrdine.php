<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatoFase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Istanza di fase = produzione di un nodo prodotto dell'ordine (§3). Unica per
 * (ordine, articolo_prodotto): i nodi condivisi generano UNA sola fase.
 */
class FaseOrdine extends Model
{
    protected $table = 'fasi_ordine';

    protected $fillable = [
        'ordine_id',
        'articolo_prodotto_codice',
        'descrizione',
        'quantita_pianificata',
        'quantita_prodotta',
        'udm',
        'livello_relativo',
        'stato',
        'tipo_fase_id',
        'reparto_step_corrente_id',
        'operatore_id',
        'timestamp_inizio',
        'timestamp_fine',
        'is_nodo_condiviso',
        'split_completato',
    ];

    protected function casts(): array
    {
        return [
            'quantita_pianificata' => 'decimal:6',
            'quantita_prodotta' => 'decimal:6',
            'livello_relativo' => 'integer',
            'stato' => StatoFase::class,
            'timestamp_inizio' => 'datetime',
            'timestamp_fine' => 'datetime',
            'is_nodo_condiviso' => 'boolean',
            'split_completato' => 'boolean',
        ];
    }

    public function ordine(): BelongsTo
    {
        return $this->belongsTo(OrdineProduzione::class, 'ordine_id');
    }

    public function operatore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operatore_id');
    }

    public function tipoFase(): BelongsTo
    {
        return $this->belongsTo(TipoFase::class);
    }

    public function repartoCorrente(): BelongsTo
    {
        return $this->belongsTo(Reparto::class, 'reparto_step_corrente_id');
    }

    public function materiali(): HasMany
    {
        return $this->hasMany(MaterialeFase::class, 'fase_ordine_id')->orderBy('posizione');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(FaseOrdineStep::class, 'fase_ordine_id')->orderBy('ordine');
    }

    public function lottiProdotto(): HasMany
    {
        return $this->hasMany(LottoProdotto::class, 'fase_ordine_id');
    }

    /** Fasi prerequisito (nodi componenti prodotti): devono essere chiuse prima di avviare questa (§3). */
    public function fasiFiglie(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'fase_precedenze', 'fase_id', 'fase_figlia_id')->withTimestamps();
    }

    /** Fasi che dipendono da questa (i padri BOM che la consumano). */
    public function fasiPadre(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'fase_precedenze', 'fase_figlia_id', 'fase_id')->withTimestamps();
    }

    /** Ripartizioni in uscita: questa fase e' il nodo condiviso sorgente (§5-bis). */
    public function splitInUscita(): HasMany
    {
        return $this->hasMany(FaseSplit::class, 'fase_sorgente_id');
    }

    /** Ripartizioni in entrata: questa fase consuma la quota di un nodo condiviso. */
    public function splitInEntrata(): HasMany
    {
        return $this->hasMany(FaseSplit::class, 'fase_destinazione_id');
    }

    public function eChiusa(): bool
    {
        return $this->stato === StatoFase::Chiusa;
    }
}
