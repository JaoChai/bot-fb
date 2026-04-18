<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('agent_cost_usage');
        Schema::dropIfExists('second_ai_logs');
    }

    public function down(): void
    {
        throw new \Exception('Cannot restore dropped tables, use DB backup');
    }
};
