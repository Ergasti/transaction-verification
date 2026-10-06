<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The receipt is read from the upload and never stored (0.2.0): no file location, no OCR text. */
return new class extends Migration
{
    private const COLUMNS = ['file_disk', 'file_path', 'ocr_text', 'second_ocr_text'];

    // The module's connection, from config (unset = the app's default).
    public function getConnection(): ?string
    {
        return config('transaction-verification.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        // Guarded so a re-run is a no-op.
        $columns = array_values(array_filter(self::COLUMNS, fn (string $column) => $schema->hasColumn('transaction_verifications', $column)));

        if ($columns !== []) {
            $schema->table('transaction_verifications', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('transaction_verifications', function (Blueprint $table) {
            $table->string('file_disk', 32)->nullable();
            $table->string('file_path')->nullable();
            $table->longText('ocr_text')->nullable();
            $table->longText('second_ocr_text')->nullable();
        });
    }
};
