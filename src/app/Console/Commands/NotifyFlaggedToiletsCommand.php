<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Toilet;
use App\Services\AdminLinkService;
use App\Services\MailService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NotifyFlaggedToiletsCommand extends Command
{
    protected $signature = 'app:notify-flagged-toilets
        {--hours=48 : Minimum hours a toilet must have been flagged}
        {--dry-run : Print email content without sending}';

    protected $description = 'Send a daily email notification if there are toilets that have been flagged for more than 48 hours';

    public function handle(MailService $mail, AdminLinkService $adminLink): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subHours($hours);

        $flaggedToilets = Toilet::where('flagged', 1)
            ->where('status', '!=', 'deleted')
            ->with(['properties', 'place', 'revisions' => fn ($q) => $q->orderByDesc('version')])
            ->orderByDesc('id')
            ->get();

        if ($flaggedToilets->isEmpty()) {
            $this->info('No flagged toilets found.');

            return self::SUCCESS;
        }

        // Filter toilets flagged for more than specified hours
        $longFlaggedToilets = [];
        foreach ($flaggedToilets as $toilet) {
            $flaggedSince = self::getFlaggedSince($toilet);
            if ($flaggedSince !== null && $flaggedSince->lte($cutoff)) {
                $longFlaggedToilets[] = [
                    'toilet' => $toilet,
                    'flagged_since' => $flaggedSince,
                ];
            }
        }

        if (empty($longFlaggedToilets)) {
            $this->info("No toilets flagged for more than {$hours} hours.");

            return self::SUCCESS;
        }

        $count = count($longFlaggedToilets);
        $subject = "{$count} toilet(s) flagged for >{$hours} hours!";

        $body = "<h1>{$count} Toilet(s) Flagged for More Than {$hours} Hours</h1>\n";
        $body .= "<p>The following toilet(s) require review in the admin panel:</p>\n";
        $body .= '<p><a href="https://api.wc-info.org/admin" style="display: inline-block; background: #4f46e5; color: #ffffff; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-weight: bold;">Open Flagged Review Panel</a></p><hr>';

        foreach ($longFlaggedToilets as $item) {
            /** @var Toilet $toilet */
            $toilet = $item['toilet'];
            /** @var Carbon $flaggedSince */
            $flaggedSince = $item['flagged_since'];

            $properties = $toilet->properties->pluck('value', 'type')->toArray();
            $diff = is_array($toilet->last_diff) ? $toilet->last_diff : (json_decode((string) $toilet->last_diff, true) ?: []);

            $body .= '<div style="margin-bottom: 20px; padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px; background: #f8fafc;">';
            $body .= '<h3 style="margin-top: 0; color: #1e293b;">Toilet #'.$toilet->id.' — '.e($toilet->name ?: 'Unnamed').'</h3>';
            $body .= '<p style="margin: 4px 0;"><strong>Flagged Since:</strong> '.$flaggedSince->format('Y-m-d H:i').' ('.$flaggedSince->diffForHumans().')</p>';
            if (! empty($toilet->owner)) {
                $body .= '<p style="margin: 4px 0;"><strong>Owner / Place:</strong> '.e($toilet->owner).'</p>';
            }
            if ($toilet->place) {
                $placeEmoji = $toilet->place->getEmoji() ? $toilet->place->getEmoji().' ' : '';
                $body .= '<p style="margin: 4px 0;"><strong>Google Place:</strong> '.$placeEmoji.e($toilet->place->getName() ?: $toilet->place_id).' <span style="color: #64748b; font-family: monospace; font-size: 0.85em;">('.e($toilet->place_id).')</span></p>';
            } elseif (! empty($toilet->place_id)) {
                $body .= '<p style="margin: 4px 0;"><strong>Place ID:</strong> <span style="font-family: monospace;">'.e($toilet->place_id).'</span></p>';
            }
            $body .= '<p style="margin: 4px 0;"><strong>Status:</strong> '.e($toilet->status).' | <strong>Source:</strong> '.e($toilet->source ?: 'unknown').'</p>';

            if (! empty($properties['address'])) {
                $body .= '<p style="margin: 4px 0;"><strong>Address:</strong> '.e($properties['address']).'</p>';
            }
            if (! empty($properties['comment'])) {
                $body .= '<p style="margin: 4px 0;"><strong>Comment:</strong> '.e($properties['comment']).'</p>';
            }

            if (! empty($diff)) {
                $body .= '<p style="margin: 4px 0;"><strong>Last Diff:</strong><pre style="background: #ffffff; border: 1px solid #cbd5e1; padding: 6px; border-radius: 4px; font-size: 0.85em;">'.nl2br(e(json_encode($diff, JSON_PRETTY_PRINT))).'</pre></p>';
            }

            $body .= '<p style="margin-top: 8px;">';
            if (! empty($toilet->place_id)) {
                $body .= '<a href="https://wc-info.org/Toilets/Place---'.$toilet->place_id.'/Toilette---'.$toilet->id.'">Open Public</a> | ';
            }
            $body .= '<a href="https://api.wc-info.org/admin/toilets/'.$toilet->id.'" style="font-weight: bold;">Admin Edit # '.$toilet->id.'</a>';
            $body .= '</p>';
            $body .= '</div>';
        }

        if ($dryRun) {
            $this->info("[DRY-RUN] Subject: {$subject}");
            $this->line("Found {$count} toilet(s) flagged for >{$hours} hours.");
            $this->line("Cutoff: {$cutoff->toDateTimeString()}");

            return self::SUCCESS;
        }

        $from = config('wcinfo.sender_mail');
        if (empty($from)) {
            $this->warn('Sender mail not configured (wcinfo.sender_mail). Skipping email send.');

            return self::SUCCESS;
        }

        $mail->send($from, $subject, $body, true);
        Log::info("NotifyFlaggedToiletsCommand: Sent email notification for {$count} flagged toilet(s) older than {$hours}h.");
        $this->info("Notification email sent for {$count} toilet(s) flagged for >{$hours} hours.");

        return self::SUCCESS;
    }

    /**
     * Determine the timestamp when the toilet entered its current flagged streak.
     */
    public static function getFlaggedSince(Toilet $toilet): ?Carbon
    {
        $revisions = $toilet->relationLoaded('revisions')
            ? $toilet->revisions
            : $toilet->revisions()->orderByDesc('version')->get();

        if ($revisions->isNotEmpty()) {
            $flaggedStreakRevision = null;
            foreach ($revisions as $rev) {
                $isFlagged = (bool) ($rev->toilet_data['flagged'] ?? false);
                if ($isFlagged) {
                    $flaggedStreakRevision = $rev;
                } else {
                    // Current flagged streak started after this unflagged revision
                    break;
                }
            }

            if ($flaggedStreakRevision && $flaggedStreakRevision->created_at) {
                return Carbon::parse($flaggedStreakRevision->created_at);
            }
        }

        if ($toilet->created_at) {
            return Carbon::parse($toilet->created_at);
        }

        if ($toilet->updated) {
            return Carbon::parse($toilet->updated);
        }

        // Default to long ago if no timestamps exist on legacy data
        return Carbon::now()->subYears(1);
    }
}
