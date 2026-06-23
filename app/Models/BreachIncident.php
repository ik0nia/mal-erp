<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BreachIncident extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $fillable = [
        'type', 'title', 'description', 'severity', 'status', 'detected_at', 'affected_scope',
        'affected_count', 'authority_notify_due_at', 'authority_notified_at',
        'subjects_notified_at', 'resolved_at', 'reported_by_user_id',
    ];

    protected $casts = [
        'detected_at'             => 'datetime',
        'authority_notify_due_at' => 'datetime',
        'authority_notified_at'   => 'datetime',
        'subjects_notified_at'    => 'datetime',
        'resolved_at'             => 'datetime',
        'affected_count'          => 'integer',
    ];

    public const SEVERITIES = ['low' => 'Scăzută', 'medium' => 'Medie', 'high' => 'Ridicată', 'critical' => 'Critică'];
    public const STATUSES = [
        'open' => 'Deschis', 'investigating' => 'În investigare', 'contained' => 'Limitat',
        'notified' => 'Notificat', 'closed' => 'Închis',
    ];

    public const TYPES = [
        'data_breach' => 'Breșă de date personale',
        'security_alert' => 'Alertă de securitate',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $incident) {
            // Termenul de 72h pentru notificarea ANSPDCP se aplică DOAR breșelor de date
            // personale, NU alertelor de securitate (ex. tentative brute-force fără compromitere).
            if ($incident->type !== 'security_alert'
                && $incident->detected_at && ! $incident->authority_notify_due_at) {
                $incident->authority_notify_due_at = $incident->detected_at->copy()->addHours(72);
            }
            if (! $incident->reported_by_user_id && auth()->check()) {
                $incident->reported_by_user_id = auth()->id();
            }
        });
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function isSecurityAlert(): bool
    {
        return $this->type === 'security_alert';
    }

    public function isAuthorityNotificationOverdue(): bool
    {
        return ! $this->isSecurityAlert()
            && $this->authority_notified_at === null
            && $this->authority_notify_due_at !== null
            && $this->authority_notify_due_at->isPast();
    }
}
