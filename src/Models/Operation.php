<?php

namespace Seat\Kassie\Calendar\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use s9e\TextFormatter\Bundles\Forum as TextFormatter;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Sde\MapDenormalize;
use Seat\Web\Models\User;

/**
 * Class Operation.
 * @package Seat\Kassie\Calendar\Models
 */
class Operation extends Model
{
    /**
     * @var string
     */
    protected $table = 'calendar_operations';

    /**
     * @var array
     */
    protected $fillable = [
        'title',
        'start_at',
        'end_at',
        'importance',
        'description',
        'description_new',
        'staging_sys',
        'staging_sys_id',
        'staging_info',
        'is_cancelled',
        'fc',
        'fc_character_id',
        'role_name',
        'is_consumption',
        'consumption_key',
    ];

    /**
     * @var array
     */
    protected $casts = ['start_at' => 'datetime', 'end_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime', 'is_consumption' => 'boolean'];

    /**
     * @return HasOne
     */
    public function fleet_commander(): HasOne
    {
        return $this->hasOne(CharacterInfo::class, 'character_id', 'fc_character_id');
    }

    /**
     * @return HasMany
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(Attendee::class);
    }

    /**
     * @return BelongsToMany
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'calendar_tag_operation');
    }

    /**
     * @return HasOne
     */
    public function staging(): HasOne
    {
        return $this->hasOne(MapDenormalize::class, 'itemID', 'staging_sys_id')
            ->withDefault();
    }

    /**
     * 抽奖行动绑定的抽奖记录（普通行动为 null）
     *
     * @return HasOne
     */
    public function lottery(): HasOne
    {
        return $this->hasOne(Lottery::class, 'operation_id', 'id');
    }

    /**
     * @return bool
     */
    public function getIsFleetCommanderAttribute(): bool
    {
        if ($this->fc_character_id == null)
            return false;

        return in_array($this->fc_character_id, auth()->user()->associatedCharacterIds());
    }

    /**
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param $value
     * @return mixed
     */
    public function getDescriptionAttribute($value): mixed
    {
        return $value ?: $this->description_new;
    }

    /**
     * @param $value
     */
    public function setDescriptionAttribute($value): void
    {
        $this->attributes['description_new'] = $value;
    }

    /**
     * @return string
     */
    public function getParsedDescriptionAttribute(): string
    {
        $parser = TextFormatter::getParser();
        $parser->disablePlugin('Emoji');

        $xml = $parser->parse($this->description ?: $this->description_new);

        return TextFormatter::render($xml);
    }

    /**
     * @return string|null
     */
    public function getDurationAttribute(): ?string
    {
        if ($this->end_at)
            return $this->end_at->diffForHumans($this->start_at,
                [
                    'syntax' => CarbonInterface::DIFF_ABSOLUTE,
                    'options' => Carbon::ROUND,
                ]
            );

        return null;
    }

    /**
     * @return string
     */
    public function getStatusAttribute(): string
    {
        if ($this->is_cancelled)
            return "cancelled";

        if ($this->start_at > Carbon::now('UTC'))
            return "incoming";

        if ($this->end_at > Carbon::now('UTC'))
            return "ongoing";

        return "faded";
    }

    /**
     * @return string
     */
    public function getStartsInAttribute(): string
    {
        return $this->start_at->diffForHumans(Carbon::now('UTC'),
            [
                'syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW,
                'options' => Carbon::ROUND,
            ]
        );
    }

    /**
     * @return string
     */
    public function getEndsInAttribute(): string
    {
        return $this->end_at->longRelativeToNowDiffForHumans(Carbon::now('UTC'),
            [
                'syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW,
                'options' => Carbon::ROUND,
            ]
        );
    }

    /**
     * @return string
     */
    public function getStartedAttribute(): string
    {
        return $this->start_at->longRelativeToNowDiffForHumans(Carbon::now('UTC'),
            [
                'syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW,
                'options' => Carbon::ROUND,
            ]
        );
    }

    /**
     * @param $user_id
     * @return string|null
     */
    public function getAttendeeStatus($user_id): ?string
    {
        $entry = $this->attendees->where('user_id', $user_id)->first();

        if ($entry != null)
            return $entry->status;

        return null;
    }

    /**
     * Return true if the user can see the operation
     *
     * @param User $user
     * @return bool
     */
    public function isUserGranted(User $user): bool
    {
        if (is_null($this->role_name))
            return true;

        return $user->roles->where('title', $this->role_name)->isNotEmpty() || auth()->user()->isAdmin();
    }

    /**
     * 取 / 建某商户在指定月份的常驻消费锚 operation（debit/refund 挂账用）。
     *
     * 每个商户每自然月一个锚（is_consumption=1、不挂 tag → 基础 PAP 为 0），
     * 让消费按交易月归集，从而复用现有「按 paps.join_time 归月」的统计而无需改动。
     * 锚为系统记录、无归属用户（user_id / fc_character_id 占位 0）。
     * consumption_key '<商户>:<YYYY-MM>' 唯一：跨用户并发首笔时另一请求撞唯一约束 → 复用已建锚。
     */
    public static function standingFor(string $merchant, Carbon $when): self
    {
        $key = sprintf('%s:%s', $merchant, $when->format('Y-m'));
        $title = sprintf('[消费] %s %s', $merchant, $when->format('Y-m'));

        if ($existing = static::where('consumption_key', $key)->first()) {
            return $existing;
        }

        try {
            $operation = new static([
                'title' => $title,
                'is_consumption' => true,
                'consumption_key' => $key,
            ]);
            $operation->user_id = 0;          // 系统锚，无归属用户
            $operation->fc = 'SYSTEM';
            $operation->fc_character_id = 0;
            $operation->importance = 0;
            $operation->start_at = $when->copy()->startOfMonth();
            $operation->end_at = $when->copy()->endOfMonth();
            $operation->save();

            return $operation;
        } catch (UniqueConstraintViolationException) {
            // 并发竞态：另一请求已抢先建好同键锚，复用之（consumption_key 唯一兜底）
            return static::where('consumption_key', $key)->firstOrFail();
        }
    }
}
