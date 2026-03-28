<?php

use Illuminate\Database\Migrations\Migration;
use Seat\Services\Exceptions\SettingException;
use Seat\Services\Models\GlobalSetting;

class DefaultMotdSettings extends Migration
{
    const DEFAULT_SETTINGS = [
        'kassie.calendar.motd_color_header'    => '00ff00',
        'kassie.calendar.motd_color_fleet'     => 'ffffff',
        'kassie.calendar.motd_color_members'   => 'ffff00',
        'kassie.calendar.motd_color_pap_value' => 'ffff00',
        'kassie.calendar.motd_color_pap_type'  => '00ffff',
        'kassie.calendar.motd_color_time'      => '00ff00',
        'kassie.calendar.motd_color_error'     => 'ff0000',
        'kassie.calendar.motd_footer_text'     => '鱼落星海祝您船蛋平安',
        'kassie.calendar.motd_footer_color'    => 'ffff00',
    ];

    /**
     * @throws SettingException
     */
    public function up(): void
    {
        foreach (self::DEFAULT_SETTINGS as $name => $value) {
            setting([$name, $value], true);
        }
    }

    public function down(): void
    {
        GlobalSetting::whereIn('name', array_keys(self::DEFAULT_SETTINGS))->delete();
    }
}
