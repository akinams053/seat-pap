<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 扩展 calendar_tags.analytics enum，新增 lottery（抽奖前置改造，见计划 §3.4.2）
 *
 * 抽奖专用 tag 固定 analytics = lottery、quantifier = 0，
 * 行动审查列表 / operation 列表可直接靠该类型识别并标记抽奖行动。
 *
 * 用原生 MODIFY 修改 enum；只新增取值，前向安全。
 */
return new class extends Migration {

    public function up(): void
    {
        DB::statement(
            "ALTER TABLE calendar_tags MODIFY analytics "
            . "ENUM('strategic','pvp','mining','other','untracked','lottery') "
            . "NOT NULL DEFAULT 'untracked'"
        );
    }

    public function down(): void
    {
        // 回退前先把已用 lottery 的行归位，避免 enum 收窄时报错
        DB::table('calendar_tags')->where('analytics', 'lottery')->update(['analytics' => 'untracked']);

        DB::statement(
            "ALTER TABLE calendar_tags MODIFY analytics "
            . "ENUM('strategic','pvp','mining','other','untracked') "
            . "NOT NULL DEFAULT 'untracked'"
        );
    }
};
