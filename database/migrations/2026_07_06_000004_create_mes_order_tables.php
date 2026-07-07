<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordini di produzione e cache "congelata" della distinta esplosa (§4.1, §5).
 * distinta_righe e' lo snapshot dell'esplosione al momento della creazione ordine:
 * da quel momento l'esecuzione lavora solo su MySQL e non cambia se la ricetta
 * cambia nel gestionale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordini_produzione', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 50)->unique();
            $table->string('articolo_finito_codice', 100);
            $table->string('descrizione_articolo', 200)->nullable();
            $table->decimal('quantita', 18, 6);
            $table->string('udm', 20)->nullable();
            $table->date('data');
            // aperto | in_lavorazione | completato | esportato (App\Enums\StatoOrdine)
            $table->string('stato', 20)->default('aperto');
            // manuale | import (App\Enums\OrigineOrdine)
            $table->string('origine', 20)->default('manuale');
            $table->foreignId('creato_da_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('esploso_at')->nullable();
            $table->timestamp('esportato_at')->nullable();
            $table->timestamps();

            $table->index('stato');
            $table->index('articolo_finito_codice');
        });

        Schema::create('distinta_righe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordine_id')->constrained('ordini_produzione')->cascadeOnDelete();
            $table->string('articolo_padre_codice', 100)->nullable(); // null = radice
            $table->string('articolo_figlio_codice', 100);
            $table->string('descrizione', 200)->nullable();
            // Quantita' pianificata per questo arco = qta_per_unita * quantita ordine.
            $table->decimal('quantita', 18, 6);
            // Quantita' cumulata per 1 unita' di prodotto finito (gia' normalizzata su QtaRifDb).
            $table->decimal('qta_per_unita', 18, 6);
            $table->string('udm', 20)->nullable();
            $table->unsignedSmallInteger('livello_relativo');
            $table->unsignedInteger('posizione')->default(0);
            $table->boolean('e_nodo_prodotto')->default(false);
            $table->timestamps();

            $table->index(['ordine_id', 'articolo_figlio_codice']);
            $table->index(['ordine_id', 'articolo_padre_codice']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distinta_righe');
        Schema::dropIfExists('ordini_produzione');
    }
};
