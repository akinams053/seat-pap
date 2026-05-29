<?php

namespace Seat\Kassie\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Seat\Eveapi\Models\Character\CharacterInfo;

/**
 * 抽奖节点表（见计划 §3.3）
 *
 * 创建抽奖时预生成全部节点，购买时更新归属（user_id / character_id / pap_adjustment_id / purchased_at）。
 */
class LotteryNode extends Model
{
    public $timestamps = false;

    protected $table = 'kassie_calendar_lottery_nodes';

    protected $fillable = [
        'lottery_id',
        'node_number',
        'user_id',
        'character_id',
        'pap_adjustment_id',
        'purchased_at',
        'refunded_at',
        'voided_at',
    ];

    protected $casts = [
        'purchased_at' => 'datetime',
        'refunded_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function lottery(): BelongsTo
    {
        return $this->belongsTo(Lottery::class, 'lottery_id', 'id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(PapAdjustment::class, 'pap_adjustment_id', 'id');
    }

    public function character(): HasOne
    {
        return $this->hasOne(CharacterInfo::class, 'character_id', 'character_id')
            ->withDefault(['name' => trans('web::seat.unknown')]);
    }

    /**
     * 节点是否仍属有效购买（已购、未退、未作废）
     */
    public function isActivelyOwned(): bool
    {
        return ! is_null($this->purchased_at)
            && is_null($this->refunded_at)
            && is_null($this->voided_at);
    }
}
