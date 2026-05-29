<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 拓宽 PAP 金额字段精度（抽奖前置改造，见计划 §3.4.1）
 *
 * 原 decimal(5,2)/(6,2) 存不下抽奖累计消费产生的负数。
 * recomputeValueFor() 会把 base(0) + Σadjustments 回写进 paps.value，
 * 一旦累计消费超过原上限就会溢出报错，故统一拓宽到 decimal(8,2)（±999,999.99）。
 *
 * 用原生 MODIFY 避免引入 doctrine/dbal；只扩大范围，前向安全。
 */
return new class extends Migration {

    public function up(): void
    {
        // paps.value：保留 NOT NULL + 默认 1.00
        DB::statement('ALTER TABLE kassie_calendar_paps MODIFY value DECIMAL(8,2) NOT NULL DEFAULT 1.00');
        // pap_adjustments.value：有符号，正=奖励/负=扣除
        DB::statement('ALTER TABLE kassie_calendar_pap_adjustments MODIFY value DECIMAL(8,2) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE kassie_calendar_paps MODIFY value DECIMAL(5,2) NOT NULL DEFAULT 1.00');
        DB::statement('ALTER TABLE kassie_calendar_pap_adjustments MODIFY value DECIMAL(6,2) NOT NULL');
    }
};
