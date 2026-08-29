<?php

namespace App\Console\Commands;

use App\Models\Toilet;
use App\Models\ToiletPhoto;
use App\Services\AdminLinkService;
use App\Services\MailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NotifyUpdatedToiletsCommand extends Command
{
    protected $signature = 'app:notify-updated-toilets';

    protected $description = 'Send a daily email with updated toilets and deleted photos';

    public function handle(MailService $mail, AdminLinkService $adminLink): int
    {
        $toilets = Toilet::where('email_sent', 2)
            ->where('status', '=', 'active')
            ->get();

        $deletedPhotos = ToiletPhoto::with('toilet')
            ->whereNotNull('deleted_ts')
            ->where('email_sent', 2)
            ->get();

        if ($toilets->isEmpty() && $deletedPhotos->isEmpty()) {
            $this->info('No updated toilets or deleted photos to notify.');
            return self::SUCCESS;
        }

        $itemsSummary = [];
        if ($toilets->isNotEmpty()) {
            $itemsSummary[] = $toilets->count() . ' updated toilet(s)';
        }
        if ($deletedPhotos->isNotEmpty()) {
            $itemsSummary[] = $deletedPhotos->count() . ' deleted photo(s)';
        }
        $subject = implode(', ', $itemsSummary) . '!';

        $body = '<h1>' . e($subject) . "</h1>\n";

        if ($toilets->isNotEmpty()) {
            $body .= '<h2>Updated Toilets</h2>';
            foreach ($toilets as $toilet) {
                $properties = DB::table('toilet_properties')
                    ->where('fk_toiletId', $toilet->id)
                    ->pluck('value', 'type')
                    ->toArray();

                $hash = $adminLink->hash($toilet->id, $toilet->place_id ?? "");
                $diff = json_decode($toilet->last_diff, true) ?: [];

                $body .= '<hr>';
                $body .= '<p><strong>Name:</strong> ' . e($toilet->name) . '<br>';
                $body .= '<strong>Owner:</strong> ' . e($toilet->owner) . '<br>';
                $body .= '<strong>Status:</strong> ' . e($toilet->status) . '<br>';
                $body .= '<strong>Diff:</strong><pre> ' . nl2br(e(json_encode($diff, JSON_PRETTY_PRINT))) . '</pre></p>';
                $body .= '<p>';
                $body .= '<a href="https://wc-info.de/Toilets/Place---' . $toilet->place_id . '/Toilette---' . $toilet->id . '">Open</a> | ';
                $body .= '<a href="https://api.wc-info.de/toilet/' . $toilet->id . '/admin-qualify?hash=' . $hash . '">Qualify</a> | ';
                $body .= '<a href="https://api.wc-info.de/toilet/' . $toilet->id . '/admin-delete?hash=' . $hash . '">Delete</a>';
                $body .= '</p>';
            }
        }

        if ($deletedPhotos->isNotEmpty()) {
            $body .= '<h2>Deleted Photos</h2>';
            foreach ($deletedPhotos as $photo) {
                $toilet = $photo->toilet;
                $body .= '<hr>';
                $body .= '<p><strong>Photo ID:</strong> ' . $photo->id . '<br>';
                $body .= '<strong>Filename:</strong> ' . e($photo->filename) . '<br>';
                $body .= '<strong>Deleted at:</strong> ' . e($photo->deleted_ts) . '<br>';
                if ($toilet) {
                    $hash = $adminLink->hash($toilet->id, $toilet->place_id ?? "");
                    $body .= '<strong>Toilet:</strong> #' . $toilet->id . ' - ' . e($toilet->name) . ' (' . e($toilet->owner) . ')<br>';
                    $body .= '<p>';
                    $body .= '<a href="https://wc-info.de/Toilets/Place---' . $toilet->place_id . '/Toilette---' . $toilet->id . '">Open Toilet</a> | ';
                    $body .= '<a href="https://api.wc-info.de/toilet/' . $toilet->id . '/admin-delete?hash=' . $hash . '">Delete Toilet</a>';
                    $body .= '</p>';
                }
                $body .= '</p>';
            }
        }

        $mail->send('hallo@wc-info.de', $subject, $body, true);

        if ($toilets->isNotEmpty()) {
            Toilet::whereIn('id', $toilets->pluck('id'))->update(['email_sent' => 1]);
        }

        if ($deletedPhotos->isNotEmpty()) {
            ToiletPhoto::whereIn('id', $deletedPhotos->pluck('id'))->update(['email_sent' => 1]);
        }

        $this->info('Notification sent for ' . $subject);

        return self::SUCCESS;
    }
}
