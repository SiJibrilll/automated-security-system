<?php

namespace App\Models;

use Database\Factories\IncidentAnalysisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentAnalysis extends Model
{
    /** @use HasFactory<IncidentAnalysisFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'incident_id',
        'provider',
        'model',
        'status',
        'threat_level',
        'is_false_positive',
        'confidence_score',
        'summary',
        'detailed_analysis',
        'recommendations',
        'prompt',
        'raw_response',
        'tokens_used',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_false_positive' => 'boolean',
            'confidence_score' => 'float',
            'recommendations' => 'array',
            'raw_response' => 'array',
            'tokens_used' => 'integer',
        ];
    }

    /**
     * Get the incident associated with this analysis.
     *
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
