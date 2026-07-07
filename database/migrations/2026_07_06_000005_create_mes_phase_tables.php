<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Istanze di fase generate dall'esplosione (§3, §5). Una fase per ogni NODO PRODOTTO
 * dell'ordine (unique ordine+articolo => i nodi condivisi generano UNA sola fase).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fasi_ordine', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordine_id')->constrained('ordini_produzione')->cascadeOnDelete();
            $table->string('articolo_prodotto_codice', 100);
            $table->string('descrizione', 200)->nullable();
            $table->decimal('quantita_pianificata', 18, 6);
            // Quantita' reale prodotta, inserita dall'operatore alla chiusura.
            $table->decimal('quantita_prodotta', 18, 6)->nullable();
            $table->string('udm', 20)->nullable();
            // Profondita' massima nell'albero (solo per ordinamento/visualizzazione, NON identifica la fase, §3).
            $table->unsignedSmallInteger('livello_relativo')->default(0);
            // da_lavorare | in_corso | chiusa (App\Enums\StatoFase)
            $table->string('stato', 20)->default('da_lavorare');
            $table->foreignId('tipo_fase_id')->nullable()->constrained('tipi_fase')->nullOnDelete();
            $table->foreignId('reparto_step_corrente_id')->nullable()->constrained('reparti')->nullOnDelete();
            $table->foreignId('operatore_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('timestamp_inizio')->nullable();
            $table->timestamp('timestamp_fine')->nullable();
            // Nodo consumato da piu' padri nell'albero: richiede split alla chiusura (§5-bis).
            $table->boolean('is_nodo_condiviso')->default(false);
            $table->boolean('split_completato')->default(false);
            $table->timestamps();

            $table->unique(['ordine_id', 'articolo_prodotto_codice']);
            $table->index(['ordine_id', 'stato']);
        });

        // Precedenze bottom-up: la fase `fase_id` e' avviabile solo quando TUTTE le sue
        // `fase_figlia_id` (i nodi componenti prodotti) sono chiuse (§3, §4.1).
        Schema::create('fase_precedenze', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fase_id')->constrained('fasi_ordine')->cascadeOnDelete();
            $table->foreignId('fase_figlia_id')->constrained('fasi_ordine')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['fase_id', 'fase_figlia_id']);
            $table->index('fase_figlia_id');
        });

        // Step di lavorazione della fase, ciascuno su un reparto (fase multi-reparto, §3).
        // La fase e' chiusa quando tutti i suoi step sono chiusi (criterio di accettazione 4).
        Schema::create('fase_ordine_step', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fase_ordine_id')->constrained('fasi_ordine')->cascadeOnDelete();
            $table->foreignId('reparto_id')->constrained('reparti')->restrictOnDelete();
            $table->unsignedSmallInteger('ordine');
            $table->string('descrizione', 150)->nullable();
            $table->boolean('consuma_materiali')->default(false);
            $table->string('stato', 20)->default('da_lavorare');
            $table->foreignId('operatore_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('timestamp_inizio')->nullable();
            $table->timestamp('timestamp_fine')->nullable();
            $table->timestamps();

            $table->unique(['fase_ordine_id', 'ordine']);
            $table->index(['reparto_id', 'stato']);
        });

        // Materiali attesi della fase = figli diretti del nodo nella distinta (§3).
        Schema::create('materiali_fase', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fase_ordine_id')->constrained('fasi_ordine')->cascadeOnDelete();
            $table->string('articolo_codice', 100);
            $table->string('descrizione', 200)->nullable();
            $table->decimal('quantita_pianificata', 18, 6);
            $table->string('udm', 20)->nullable();
            $table->boolean('flag_lotto')->default(false);
            // Se il materiale e' un semilavorato prodotto in questo stesso ordine, il suo
            // lotto proviene dalla fase produttrice (non digitato dall'operatore).
            $table->boolean('e_semilavorato')->default(false);
            $table->foreignId('fase_produttrice_id')->nullable()->constrained('fasi_ordine')->nullOnDelete();
            $table->unsignedInteger('posizione')->default(0);
            $table->timestamps();

            $table->index('fase_ordine_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materiali_fase');
        Schema::dropIfExists('fase_ordine_step');
        Schema::dropIfExists('fase_precedenze');
        Schema::dropIfExists('fasi_ordine');
    }
};
