<?php

namespace Seat\Kassie\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Seat\Eveapi\Models\Character\CharacterInfo;

class PapAdjustment extends Model
{
    public $timestamps = false;

    protected $table = 'kassie_calendar_pap_adjustments';

    protected $fillable = [
        'operation_id', 'character_id', 'value', 'reason', 'created_by_character_id', 'created_at',
    ];

    protected $casts = [
        'value' => 'float',
        'created_at' => 'datetime',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'operation_id', 'id');
    }

    public function character(): HasOne
    {
        return $this->hasOne(CharacterInfo::class, 'character_id', 'character_id')
            ->withDefault(['name' => trans('web::seat.unknown')]);
    }

    public function creator(): HasOne
    {
        return $this->hasOne(CharacterInfo::class, 'character_id', 'created_by_character_id')
            ->withDefault(['name' => trans('web::seat.unknown')]);
    }
}
