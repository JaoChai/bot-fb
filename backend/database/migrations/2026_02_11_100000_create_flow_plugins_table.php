<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_plugins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->onDelete('cascade');
            $table->string('type');
            $table->string('name')->nullable();
            $table->boolean('enabled')->default(true);
            $table->text('trigger_condition');
            $table->json('config');
            $table->timestamps();

            $table->index(['flow_id', 'type']);
            $table->index(['flow_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_plugins');
    }
};
