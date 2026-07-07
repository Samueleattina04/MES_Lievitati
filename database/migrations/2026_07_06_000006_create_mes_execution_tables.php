<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dati registrati dall'operatore durante l'esecuzione (§5, §5-bis, §6):
 * consumi effettivi + righe lotto (multi-lotto), lotti prodotti in uscita, split.
 * client_uuid garantisce l'idempotenza della sincronizzazione offline (§8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumi_materiale', function (Blueprint $table) {
            $table->id();
            $table->foreignId('materiale_fase_id')->constrained('materiali_fase')->cascadeOnDelete();
            $table->decimal('quantita_effettiva', 18, 6);
            $table->foreignId('confermato_da_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confermato_at')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();

            $table->unique('materiale_fase_id');
            $table->index('client_uuid');
        });

        // Righe multi-lotto: uno stesso componente puo' essere ripartito su piu' lotti.
        // La somma delle quantita' deve quadrare con quantita_effettiva entro tolleranza (§6).
        Schema::create('consumo_materiale_lotti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumo_materiale_id')->constrained('consumi_materiale')->cascadeOnDelete();
            $table->string('lotto', 100);
            $table->decimal('quantita', 18, 6);
            $table->timestamps();

            $table->index('consumo_materiale_id');
            $table->index('lotto');
        });

        // Lotto del prodotto/semilavorato in uscita dalla fase (inserito dall'operatore, §6).
        Schema::create('lotti_prodotto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fase_ordine_id')->constrained('fasi_ordine')->cascadeOnDelete();
            $table->string('articolo_codice', 100);
            $table->string('lotto', 100);
            $table->decimal('quantita', 18, 6)->nullable();
            $table->foreignId('creato_da_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();

            $table->index('fase_ordine_id');
            $table->index('lotto');
        });

        // Ripartizione (split) di un nodo condiviso prodotto una sola volta (§5-bis).
        // sorgente = fase del nodo condiviso; destinazione = fase padre che ne consuma una quota.
        Schema::create('fase_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fase_sorgente_id')->constrained('fasi_ordine')->cascadeOnDelete();
            $table->foreignId('fase_destinazione_id')->constrained('fasi_ordine')->cascadeOnDelete();
            $table->decimal('quantita_assegnata', 18, 6);
            $table->foreignId('operatore_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();

            $table->unique(['fase_sorgente_id', 'fase_destinazione_id']);
            $table->index('fase_destinazione_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fase_splits');
        Schema::dropIfExists('lotti_prodotto');
        Schema::dropIfExists('consumo_materiale_lotti');
        Schema::dropIfExists('consumi_materiale');
    }
};
