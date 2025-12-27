<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Add content field for text-based documents
            $table->text('content')->nullable()->after('storage_path');

            // Make file-related fields nullable (for text-only documents)
            $table->string('filename')->nullable()->change();
            $table->string('mime_type')->nullable()->change();
            $table->unsignedBigInteger('file_size')->nullable()->change();
            $table->string('storage_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('content');

            // Revert nullable changes
            $table->string('filename')->nullable(false)->change();
            $table->string('mime_type')->nullable(false)->change();
            $table->unsignedBigInteger('file_size')->nullable(false)->change();
            $table->string('storage_path')->nullable(false)->change();
        });
    }
};
