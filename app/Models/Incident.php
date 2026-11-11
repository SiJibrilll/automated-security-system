<?php

namespace App\Models;

use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * Scope a query to only include incidents without an AI analysis.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeUnanalyzed(Builder $query): Builder
    {
        return $query->whereDoesntHave('analyses');
    }

    /**
     * Scope a query to only include incidents pending AI analysis.
     *
     * @param  Builder<$this>  $query
     * @param  bool  $includeFailed  Whether to include incidents whose previous analysis failed
     * @return Builder<$this>
     */
    public function scopePendingAnalysis(Builder $query, bool $includeFailed = false): Builder
    {
        if ($includeFailed) {
            return $query->whereDoesntHave('analyses', function (Builder $q) {
                $q->where('status', 'completed');
            });
        }

        return $query->whereDoesntHave('analyses');
    }

    /**
     * Get the source IP address if present in incident_json.
     *
     * @return Attribute<string|null, never>
     */
    protected function sourceIp(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->incident_json['source_ip'] ?? $this->incident_json['src_ip'] ?? null
        );
    }

    /**
     * Get the destination IP address if present in incident_json.
     *
     * @return Attribute<string|null, never>
     */
    protected function destinationIp(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->incident_json['destination_ip'] ?? $this->incident_json['dest_ip'] ?? null
        );
    }

    /**
     * Get the destination port if present in incident_json.
     *
     * @return Attribute<int|null, never>
     */
    protected function destinationPort(): Attribute
    {
        return Attribute::make(
            get: function () {
                $port = $this->incident_json['destination_port'] ?? $this->incident_json['dest_port'] ?? $this->incident_json['dport'] ?? null;

                return $port !== null ? (int) $port : null;
            }
        );
    }

    /**
     * Get the source port if present in incident_json.
     *
     * @return Attribute<int|null, never>
     */
    protected function sourcePort(): Attribute
    {
        return Attribute::make(
            get: function () {
                $port = $this->incident_json['source_port'] ?? $this->incident_json['src_port'] ?? $this->incident_json['sport'] ?? null;

                return $port !== null ? (int) $port : null;
            }
        );
    }

    /**
     * Get the network protocol if present in incident_json.
     *
     * @return Attribute<string|null, never>
     */
    protected function protocol(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->incident_json['protocol'] ?? $this->incident_json['proto'] ?? null
        );
    }
}
