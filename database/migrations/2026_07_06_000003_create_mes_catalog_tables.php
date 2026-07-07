<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anagrafica articoli (§5). `articoli` e' una cache dei dati letti dal gestionale
 * (aggiornata dall'adapter alla creazione ordine). `articolo_configurazione_mes`
 * contiene invece gli attributi che esistono SOLO nel MES (reparto, tipo fase,
 * override flag lotto): e' tenuta separata e chiavata sul CODICE articolo, cosi'
 * da non andare persa quando la cache viene rigenerata a ogni nuova esplosione.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articoli', function (Blueprint $table) {
            $table->id();
            $table->string('codice', 100)->unique();
            $table->string('descrizione', 200)->nullable();
            $table->string('udm', 20)->nullable();
            $table->string('udm_tecnica', 20)->nullable();
            // acquistato (foglia, si consuma) | prodotto (ha sotto-distinta, genera fase)
            $table->string('tipo', 20)->default('acquistato');
            $table->boolean('flag_lotto')->default(false);
            $table->timestamps();

            $table->index('tipo');
        });

        Schema::create('articolo_configurazione_mes', function (Blueprint $table) {
            $table->id();
            $table->string('articolo_codice', 100)->unique();
            $table->foreignId('reparto_default_id')->nullable()->constrained('reparti')->nullOnDelete();
            $table->foreignId('tipo_fase_id')->nullable()->constrained('tipi_fase')->nullOnDelete();
            // Se valorizzato, sovrascrive articoli.flag_lotto per questo codice.
            $table->boolean('flag_lotto_override')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articolo_configurazione_mes');
        Schema::dropIfExists('articoli');
    }
};
