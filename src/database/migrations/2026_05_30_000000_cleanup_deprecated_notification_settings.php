<?php

use Illuminate\Database\Migrations\Migration;
use Seat\Services\Models\GlobalSetting;

/**
 * 清理已废弃的通知 / Slack / Discord 集成设置残留。
 *
 * 本插件已移除通知与外部集成功能，但历史 migration 在 global_settings 里
 * 遗留了 notify_* / slack_* / discord_* 等设置行。这里按前缀清理，
 * 保留仍在用的 motd_* / api_token / shop_url / pap_start_date。
 *
 * 前向安全的数据清理：down() 不恢复这些已废弃设置。
 */
class CleanupDeprecatedNotificationSettings extends Migration
{
    public function up(): void
    {
        GlobalSetting::where('name', 'like', 'kassie.calendar.notify_%')
            ->orWhere('name', 'like', 'kassie.calendar.slack_%')
            ->orWhere('name', 'like', 'kassie.calendar.discord_%')
            ->delete();
    }

    public function down(): void
    {
        // 已废弃的通知 / 集成设置不再恢复
    }
}
