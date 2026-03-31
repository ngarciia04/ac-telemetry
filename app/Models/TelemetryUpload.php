<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TelemetryUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'circuit_name',
        'car_name',
        'best_lap_time',
        'original_filename',
        'stored_filename',
        'stored_path',
        'file_size',
        'metadata',
    ];

    protected $casts = [
        'best_lap_time' => 'decimal:3',
        'metadata' => 'array',
    ];

    /**
     * Relación con el usuario (opcional por ahora)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

