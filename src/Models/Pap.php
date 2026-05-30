<?php
/**
 * User: Warlof Tutsimo <loic.leuilliot@gmail.com>
 * Date: 21/12/2017
 * Time: 11:24
 */

namespace Seat\Kassie\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Eveapi\Models\Sde\MapDenormalize;
use Seat\Web\Models\User;

/**
 * Class Pap.
 *
 * @package Seat\Kassie\Calendar\Models
 */
class Pap extends Model
{

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $table = 'kassie_calendar_paps';

    /**
     * @var array
     */
    protected $primaryKey = [
        'operation_id', 'character_id'
    ];

    /**
     * @var array
     */
    protected $fillable = [
        'operation_id', 'character_id', 'ship_type_id', 'solar_system_id', 'join_time', 'value', 'created_at',
    ];

    /**
     * @param array $options
     * @return bool
     */
    public function save(array $options = []): bool
    {

        $operation = Operation::find($this->getAttributeValue('operation_id'));

        if (is_null($this->getAttributeValue('value')))
            $this->setAttribute('value', 0);

        if (!is_null($operation) && $operation->tags->count() > 0)
            $this->setAttribute('value', $operation->tags->max('quantifier'));

        if (array_key_exists('join_time', $this->attributes)) {
            $dt = carbon($this->getAttributeValue('join_time'));
            $this->setAttribute('week', $dt->weekOfMonth);
            $this->setAttribute('month', $dt->month);
            $this->setAttribute('year', $dt->year);
        }

        return parent::save($options);
    }

    /**
     * @return HasOne
     */
    public function character(): HasOne
    {
        return $this->hasOne(CharacterInfo::class, 'character_id', 'character_id')
            ->withDefault([
                'name' => trans('web::seat.unknown'),
            ]);
    }

    /**
     * @return HasOneThrough
     */
    public function user(): HasOneThrough
    {
        return $this->hasOneThrough(User::class, RefreshToken::class,
            'character_id', 'id', 'character_id', 'user_id')
            ->withDefault([
                'name' => trans('web::seat.unknown'),
            ]);
    }

    /**
     * @return BelongsTo
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'operation_id', 'id')
            ->withDefault([
                'title' => trans('web::seat.unknown'),
            ]);
    }

    /**
     * @return HasOne
     */
    public function type(): HasOne
    {
        return $this->hasOne(InvType::class, 'typeID', 'ship_type_id')
            ->withDefault([
                'typeName' => trans('web::seat.unknown'),
            ]);
    }

    /**
     * @return HasOne
     */
    public function solarSystem(): HasOne
    {
        return $this->hasOne(MapDenormalize::class, 'itemID', 'solar_system_id')
            ->withDefault([
                'itemName' => trans('web::seat.unknown'),
            ]);
    }

    /**
     * 全局 PAP 统计起始日（单一来源，见计划 §12）
     *
     * 所有统计 / 排行 / 抽奖余额 / API 查询都应通过本方法读取统计起点，
     * 不要再各处硬编码日期。起始日由管理员在设置页配置，存
     * setting('kassie.calendar.pap_start_date')，缺省回退 2026-01-01。
     *
     * 强制月初对齐：个人页趋势按月分组，月中起始日会让该月变成「半个月」。
     */
    public static function statisticsStartDate(): \Carbon\Carbon
    {
        $raw = setting('kassie.calendar.pap_start_date', true);

        try {
            $date = $raw ? carbon($raw) : carbon('2026-01-01');
        } catch (\Exception) {
            $date = carbon('2026-01-01');
        }

        return $date->startOfMonth();
    }

    /**
     * 重新计算指定行动 + 角色的最终 PAP 值并写回 paps.value
     * 最终值 = operation tag max(quantifier) + Σ adjustments
     */
    public static function recomputeValueFor(int $operationId, int $characterId): void
    {
        $operation = Operation::with('tags')->find($operationId);
        $baseValue = $operation?->tags->max('quantifier') ?: 0;

        $adjustSum = (float) PapAdjustment::where('operation_id', $operationId)
            ->where('character_id', $characterId)
            ->sum('value');

        DB::table('kassie_calendar_paps')
            ->where('operation_id', $operationId)
            ->where('character_id', $characterId)
            ->update(['value' => $baseValue + $adjustSum]);
    }
}
