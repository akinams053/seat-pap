<?php

namespace Seat\Kassie\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 抽奖主表，1:1 绑定一个 operation（见计划 §3.1）
 *
 * status：open / sold_out / drawn / cancelled（第一版不做 draft）
 */
class Lottery extends Model
{
    protected $table = 'kassie_calendar_lotteries';

    protected $fillable = [
        'operation_id',
        'title',
        'node_count',
        'node_price',
        'max_nodes_per_user',
        'allow_repeat_winners',
        'status',
        'created_by_character_id',
        'drawn_by_character_id',
        'drawn_at',
        'draw_log',
    ];

    protected $casts = [
        'node_price' => 'float',
        'allow_repeat_winners' => 'boolean',
        'draw_log' => 'array',
        'drawn_at' => 'datetime',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'operation_id', 'id');
    }

    public function prizes(): HasMany
    {
        return $this->hasMany(LotteryPrize::class, 'lottery_id', 'id')
            ->orderBy('sort_order');
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(LotteryNode::class, 'lottery_id', 'id')
            ->orderBy('node_number');
    }

    /**
     * 已售出（已购买且未退款 / 未作废）节点数
     */
    public function soldNodesCount(): int
    {
        return $this->nodes()
            ->whereNotNull('purchased_at')
            ->whereNull('refunded_at')
            ->whereNull('voided_at')
            ->count();
    }

    /**
     * 系统保留的抽奖专用 tag（见计划 §2.2）
     *
     * 以 analytics = lottery 为稳定标识 firstOrCreate；
     * 强制 quantifier = 0，保证抽奖行动基础 PAP 为 0。
     * 创建抽奖时由系统查找 / 创建并绑定，FC 无需手动选择。
     */
    public static function reservedTag(): Tag
    {
        $tag = Tag::firstOrCreate(
            ['analytics' => 'lottery'],
            [
                'name' => 'PAP 抽奖 / Lottery',
                // bg_color / text_color 在 calendar_tags 是 NOT NULL 无默认，必须显式给值；金色呼应中奖高亮
                'bg_color' => '#d4af37',
                'text_color' => '#ffffff',
                'quantifier' => 0,
                'order' => 0,
            ]
        );

        // 防御性纠偏：保留 tag 的 quantifier 必须恒为 0
        if ((float) $tag->quantifier !== 0.0) {
            $tag->quantifier = 0;
            $tag->save();
        }

        return $tag;
    }
}
