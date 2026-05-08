<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('kassie_calendar_pap_adjustments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('operation_id');
            $table->bigInteger('character_id');
            // 有符号：正=奖励，负=扣除
            $table->decimal('value', 6, 2);
            $table->string('reason', 255);
            $table->bigInteger('created_by_character_id');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['operation_id', 'character_id']);
            $table->index('created_by_character_id');
        });

        Schema::table('kassie_calendar_paps', function (Blueprint $table) {
            // 旧数据 NULL，前向安全
            $table->bigInteger('solar_system_id')->nullable()->after('ship_type_id');
            $table->timestamp('created_at')->nullable()->after('year');
        });
    }

    public function down(): void
    {
        Schema::table('kassie_calendar_paps', function (Blueprint $table) {
            $table->dropColumn(['solar_system_id', 'created_at']);
        });

        Schema::dropIfExists('kassie_calendar_pap_adjustments');
    }
};
