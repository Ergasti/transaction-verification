<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per submitted receipt. Kept forever (no soft delete, no pruning).
 * The destination, OCR text and extracted fields are encrypted by the model; lookups use HMAC hashes.
 */
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

        // Guarded so a re-run (or a table created by hand) is a no-op.
        if ($schema->hasTable('transaction_verifications')) {
            return;
        }

        $schema->create('transaction_verifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('subject_type', 64);
            $table->string('subject_id', 64);
            $table->unsignedBigInteger('merchant_id')->nullable()->index();
            $table->string('status', 16);
            // Claims so far; the claim a run holds, so a recovered row's old worker can't overwrite the new run.
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('verdict', 16)->nullable();
            $table->bigInteger('expected_amount_minor');
            $table->char('currency', 3);
            $table->text('expected_destination');
            $table->char('expected_destination_hash', 64);
            $table->string('file_disk', 32);
            $table->string('file_path');
            $table->string('file_mime', 100);
            $table->unsignedInteger('file_size');
            $table->char('file_sha256', 64)->index();
            $table->string('engine', 32)->nullable();
            $table->string('engine_version', 64)->nullable();
            $table->longText('ocr_text')->nullable();
            $table->longText('second_ocr_text')->nullable();
            $table->text('extracted')->nullable();
            $table->char('reference_hash', 64)->nullable()->index();
            $table->json('checks')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('error')->nullable();
            $table->string('idempotency_key')->unique();
            $table->json('context')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('transaction_verifications');
    }
};
