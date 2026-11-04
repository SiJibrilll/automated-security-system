<?php

namespace App\Models;

use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $incident_id
 * @property Carbon|null $timestamp
 * @property string|null $hypervisor
 * @property string|null $signature
 * @property string|null $severity_label
 * @property array<string, mixed>|null $incident_json
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'incident_id',
        'timestamp',
        'hypervisor',
        'signature',
        'severity_label',
        'incident_json',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'incident_json' => 'array',
        ];
    }

    /**
     * Get the AI analyses for the incident.
     *
     * @return HasMany<IncidentAnalysis, $this>
     */
    public function analyses(): HasMany
    {
        return $this->hasMany(IncidentAnalysis::class);
    }

    /**
     * Get the latest AI analysis for the incident.
     *
     * @return HasOne<IncidentAnalysis, $this>
     */
    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(IncidentAnalysis::class)->latestOfMany();
    }
}
