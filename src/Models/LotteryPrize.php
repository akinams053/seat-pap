<?php

namespace Seat\Kassie\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Seat\Eveapi\Models\Character\CharacterInfo;

/**
 * 抽奖奖品表（见计划 §3.2）
 */
class LotteryPrize extends Model
{
    public $timestamps = false;

    protected $table = 'kassie_calendar_lottery_prizes';

    protected $fillable = [
        'lottery_id',
        'sort_order',
        'name',
        'description',
        'winner_node_number',
        'winner_user_id',
        'winner_character_id',
        'drawn_at',
    ];

    protected $casts = [
        'drawn_at' => 'datetime',
    ];

    public function lottery(): BelongsTo
    {
        return $this->belongsTo(Lottery::class, 'lottery_id', 'id');
    }

    public function winner(): HasOne
    {
        return $this->hasOne(CharacterInfo::class, 'character_id', 'winner_character_id')
            ->withDefault(['name' => trans('web::seat.unknown')]);
    }
}
