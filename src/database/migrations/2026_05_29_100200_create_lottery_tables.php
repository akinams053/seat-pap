<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 抽奖三张表（见计划 §3.1 / §3.2 / §3.3）
 *
 * - lotteries：抽奖主表，1:1 绑定一个 operation
 * - lottery_prizes：多奖品表，记录每个奖品及中奖归属
 * - lottery_nodes：节点表，创建时预生成全部节点，购买时更新归属
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('kassie_calendar_lotteries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('operation_id');
            $table->string('title');
            $table->integer('node_count');
            // 单节点 PAP 价格，与 paps.value / adjustments.value 精度统一
            $table->decimal('node_price', 8, 2);
            $table->integer('max_nodes_per_user')->nullable();
            $table->boolean('allow_repeat_winners')->default(false);
            // open / sold_out / drawn / cancelled（第一版不做 draft）
            $table->string('status')->default('open');
            $table->bigInteger('created_by_character_id');
            $table->bigInteger('drawn_by_character_id')->nullable();
            $table->timestamp('drawn_at')->nullable();
            $table->json('draw_log')->nullable();
            $table->timestamps();

            $table->unique('operation_id');
            $table->index('status');
            $table->index('created_by_character_id');
        });

        Schema::create('kassie_calendar_lottery_prizes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('lottery_id');
            $table->integer('sort_order')->default(0);
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('winner_node_number')->nullable();
            $table->bigInteger('winner_user_id')->nullable();
            $table->bigInteger('winner_character_id')->nullable();
            $table->timestamp('drawn_at')->nullable();

            $table->index(['lottery_id', 'sort_order']);
            $table->index(['lottery_id', 'winner_user_id']);
        });

        Schema::create('kassie_calendar_lottery_nodes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('lottery_id');
            $table->integer('node_number');
            $table->bigInteger('user_id')->nullable();
            $table->bigInteger('character_id')->nullable();
            $table->bigInteger('pap_adjustment_id')->nullable();
            $table->timestamp('purchased_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('voided_at')->nullable();

            $table->unique(['lottery_id', 'node_number']);
            $table->index(['lottery_id', 'user_id']);
            $table->index('pap_adjustment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kassie_calendar_lottery_nodes');
        Schema::dropIfExists('kassie_calendar_lottery_prizes');
        Schema::dropIfExists('kassie_calendar_lotteries');
    }
};
