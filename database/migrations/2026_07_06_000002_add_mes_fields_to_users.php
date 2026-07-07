<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estende users con i campi MES (ruolo, PIN operatore) e crea il pivot
 * operatore->reparti (§7). Gli operatori accedono via PIN su tablet condiviso e
 * possono non avere email/password, per cui questi campi diventano nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // operatore | backoffice | pianificazione | admin (cast a App\Enums\RuoloUtente)
            $table->string('ruolo', 30)->default('operatore')->after('name');
            // PIN numerico hashato (solo operatori). Mai in chiaro.
            $table->string('pin_hash')->nullable()->after('password');
            $table->boolean('attivo')->default(true)->after('pin_hash');

            // Gli operatori loggano via PIN: email/password non obbligatorie per loro.
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });

        // Associazione operatore -> reparti abilitati (un operatore puo' essere su piu' reparti).
        Schema::create('operatore_reparto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reparto_id')->constrained('reparti')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'reparto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operatore_reparto');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ruolo', 'pin_hash', 'attivo']);
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
