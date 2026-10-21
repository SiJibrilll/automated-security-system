<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    protected $fillable = [
        'incident_id',
        'timestamp',
        'hypervisor',
        'signature',
        'severity_label',
        'incident_json'
    ];
}
