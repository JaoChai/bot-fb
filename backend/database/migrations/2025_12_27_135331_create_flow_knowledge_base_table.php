<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Create pivot table for Flow <-> KnowledgeBase (Many-to-Many)
        Schema::create('flow_knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->integer('kb_top_k')->default(5);
            $table->decimal('kb_similarity_threshold', 4, 3)->default(0.7);
            $table->timestamps();

            $table->unique(['flow_id', 'knowledge_base_id']);
        });

        // Migrate existing relationships from flows table to pivot table
        $flows = \DB::table('flows')
            ->whereNotNull('knowledge_base_id')
            ->get(['id', 'knowledge_base_id', 'kb_top_k', 'kb_similarity_threshold']);

        foreach ($flows as $flow) {
            \DB::table('flow_knowledge_base')->insert([
                'flow_id' => $flow->id,
                'knowledge_base_id' => $flow->knowledge_base_id,
                'kb_top_k' => $flow->kb_top_k ?? 5,
                'kb_similarity_threshold' => $flow->kb_similarity_threshold ?? 0.7,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Drop old columns from flows table
        Schema::table('flows', function (Blueprint $table) {
            $table->dropForeign(['knowledge_base_id']);
            $table->dropColumn(['knowledge_base_id', 'kb_top_k', 'kb_similarity_threshold']);
        });
    }

    public function down(): void
    {
        // Restore columns to flows table
        Schema::table('flows', function (Blueprint $table) {
            $table->foreignId('knowledge_base_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('kb_top_k')->default(5);
            $table->decimal('kb_similarity_threshold', 4, 3)->default(0.7);
        });

        // Migrate data back (only first KB per flow)
        $pivotData = \DB::table('flow_knowledge_base')
            ->orderBy('created_at')
            ->get();

        $migratedFlows = [];
        foreach ($pivotData as $record) {
            if (! in_array($record->flow_id, $migratedFlows)) {
                \DB::table('flows')
                    ->where('id', $record->flow_id)
                    ->update([
                        'knowledge_base_id' => $record->knowledge_base_id,
                        'kb_top_k' => $record->kb_top_k,
                        'kb_similarity_threshold' => $record->kb_similarity_threshold,
                    ]);
                $migratedFlows[] = $record->flow_id;
            }
        }

        Schema::dropIfExists('flow_knowledge_base');
    }
};
