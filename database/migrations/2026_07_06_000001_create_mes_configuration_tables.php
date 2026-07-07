<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelle di configurazione MES (§5): reparti e definizione delle fasi/step.
 * Sono dati gestiti manualmente nel MES (dall'admin), non provenienti dal gestionale.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Reparto: unita' produttiva (Impasto, Lievitazione, Forno, Confezionamento, ...).
        Schema::create('reparti', function (Blueprint $table) {
            $table->id();
            $table->string('codice', 50)->unique();
            $table->string('descrizione', 150);
            $table->boolean('attivo')->default(true);
            $table->timestamps();
        });

        // TipoFase: template di lavorazione associabile a un articolo prodotto.
        // Rappresenta la sequenza ordinata di step (ciascuno su un reparto) che
        // compone la produzione di quel nodo. Consente "fase che attraversa piu' reparti" (§3).
        Schema::create('tipi_fase', function (Blueprint $table) {
            $table->id();
            $table->string('codice', 50)->unique();
            $table->string('descrizione', 150);
            $table->timestamps();
        });

        // Step ordinato di un TipoFase, assegnato a un reparto.
        Schema::create('tipo_fase_step', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_fase_id')->constrained('tipi_fase')->cascadeOnDelete();
            $table->foreignId('reparto_id')->constrained('reparti')->restrictOnDelete();
            $table->unsignedSmallInteger('ordine');
            $table->string('descrizione', 150)->nullable();
            // Lo step su cui l'operatore conferma i materiali della fase (default: il primo).
            $table->boolean('consuma_materiali')->default(false);
            $table->timestamps();

            $table->unique(['tipo_fase_id', 'ordine']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_fase_step');
        Schema::dropIfExists('tipi_fase');
        Schema::dropIfExists('reparti');
    }
};
