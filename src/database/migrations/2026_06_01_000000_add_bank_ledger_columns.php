<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAP 银行化：账本来源标记 + 消费锚标记（见实施计划 阶段 1）
 *
 * - pap_adjustments 加 source/external_ref/ref_group：复用现有调整表当账本，
 *   debit/refund 写带来源的调整行；external_ref 唯一保证幂等（同键重试不重复扣）。
 * - calendar_operations 加 is_consumption：消费判定从 lotteries-join 切到此列。
 * - 历史回填：旧抽奖 operation 置 is_consumption=1、其调整置 source=lottery，
 *   保证三口径从 join 切到 is_consumption 后出勤/消费口径不变。
 *
 * 前向安全、可重复执行（加列前判存在）。
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::table('kassie_calendar_pap_adjustments', function (Blueprint $table) {
            // 来源分类：attendance_audit(FC奖惩) / lottery / shop / refund …
            if (! Schema::hasColumn('kassie_calendar_pap_adjustments', 'source')) {
                $table->string('source', 32)->default('attendance_audit')->after('value');
            }
            // 外部幂等键：唯一；NULL 不参与唯一约束（行动审查留空）
            if (! Schema::hasColumn('kassie_calendar_pap_adjustments', 'external_ref')) {
                $table->string('external_ref', 128)->nullable();
                $table->unique('external_ref');
            }
            // 场次/订单分组键：外部消费审查按此聚合成一笔
            if (! Schema::hasColumn('kassie_calendar_pap_adjustments', 'ref_group')) {
                $table->string('ref_group', 64)->nullable();
                $table->index('ref_group');
            }
        });

        Schema::table('calendar_operations', function (Blueprint $table) {
            // 1=消费锚（抽奖/商店常驻账本 operation）；0=真实出勤行动
            if (! Schema::hasColumn('calendar_operations', 'is_consumption')) {
                $table->boolean('is_consumption')->default(false);
                $table->index('is_consumption');
            }
            // 消费锚幂等键 '<商户>:<YYYY-MM>'，唯一：根除跨用户并发首笔时重复建锚。
            // 普通行动留 NULL（MySQL 唯一索引不约束 NULL），故不影响行动可重名。
            if (! Schema::hasColumn('calendar_operations', 'consumption_key')) {
                $table->string('consumption_key', 80)->nullable()->unique();
            }
        });

        // 历史回填：旧抽奖 operation 与其调整打标，保证口径切换无缝衔接
        if (Schema::hasTable('kassie_calendar_lotteries')) {
            DB::statement("
                UPDATE kassie_calendar_pap_adjustments SET source = 'lottery'
                 WHERE operation_id IN (SELECT operation_id FROM kassie_calendar_lotteries)
            ");
            DB::statement('
                UPDATE calendar_operations SET is_consumption = 1
                 WHERE id IN (SELECT operation_id FROM kassie_calendar_lotteries)
            ');
        }
    }

    public function down(): void
    {
        Schema::table('kassie_calendar_pap_adjustments', function (Blueprint $table) {
            $table->dropUnique(['external_ref']);
            $table->dropIndex(['ref_group']);
            $table->dropColumn(['source', 'external_ref', 'ref_group']);
        });

        Schema::table('calendar_operations', function (Blueprint $table) {
            $table->dropUnique(['consumption_key']);
            $table->dropColumn('consumption_key');
            $table->dropIndex(['is_consumption']);
            $table->dropColumn('is_consumption');
        });
    }
};
