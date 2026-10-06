<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every duplicate match ever found, kept for fraud investigation. Indexes only, no FKs. */
return new class extends Migration
{
    // The module's connection, from config (unset = the app's default).
    public function getConnection(): ?string
    {
        return config('transaction-verification.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasTable('transaction_verification_duplicates')) {
            return;
        }

        $schema->create('transaction_verification_duplicates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('verification_id')->index();
            $table->unsignedBigInteger('duplicate_of_id')->index();
            // sha256 | reference.
            $table->string('method', 16);
            $table->timestamps();

            // Explicit name: the generated one is over MySQL's 64-char limit.
            $table->unique(['verification_id', 'duplicate_of_id', 'method'], 'tv_duplicates_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('transaction_verification_duplicates');
    }
};
