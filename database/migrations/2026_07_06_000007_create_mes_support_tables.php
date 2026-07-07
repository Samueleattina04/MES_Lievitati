<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelle di supporto: audit trail (§11) e coda di sincronizzazione offline (§8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_eventi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo_evento', 50);
            $table->nullableMorphs('soggetto'); // riferimento polimorfico (fase, ordine, ...)
            $table->string('descrizione', 255)->nullable();
            $table->json('dati')->nullable(); // es. { "prima": ..., "dopo": ... } per modifiche quantita'
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->index('tipo_evento');
            $table->index('created_at');
        });

        // Coda azioni offline (§5, §8). L'endpoint /api/sync verifica client_uuid prima
        // di processare, garantendo idempotenza sui retry di rete.
        Schema::create('sync_queue', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            // conferma_fase | split | lotto_materia_prima | chiusura_fase
            $table->string('tipo_azione', 50);
            $table->json('payload');
            $table->boolean('processato')->default(false);
            $table->timestamp('processato_at')->nullable();
            $table->text('errore')->nullable();
            $table->timestamps();

            $table->index('processato');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_queue');
        Schema::dropIfExists('log_eventi');
    }
};
