<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('associables', function (Blueprint $table): void {
            $table->id();
            $table->string('associated_type');
            $table->unsignedBigInteger('associated_id');
            $table->string('associable_type');
            $table->unsignedBigInteger('associable_id');
            $table->timestamps();

            $table->index(['associated_type', 'associated_id']);
            $table->index(['associable_type', 'associable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('associables');
    }
};
