<?php

namespace App\Console\Commands;

use App\Models\Toilet;
use App\Services\AdminLinkService;
use App\Services\MailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NotifyNewToiletsCommand extends Command
{
    protected $signature = 'app:notify-new-toilets';

    protected $description = 'Send a daily email with newly added toilets';

    public function handle(MailService $mail, AdminLinkService $adminLink): int
    {
        $toilets = Toilet::where('email_sent', 0)
            ->where('status', '=', 'active')
            ->get();

        if ($toilets->isEmpty()) {
            $this->info('No new toilets to notify.');
            return self::SUCCESS;
        }

        $body = '<h1>' . $toilets->count() . " new toilet(s)!</h1>\n";

        foreach ($toilets as $toilet) {
            $properties = DB::table('toilet_properties')
                ->where('fk_toiletId', $toilet->id)
                ->pluck('value', 'type')
                ->toArray();

            $hash = $adminLink->hash($toilet->id, $toilet->place_id ?? '');

            $body .= '<hr>';
            $body .= '<p><strong>Name:</strong> ' . e($toilet->name) . '<br>';
            $body .= '<strong>Owner:</strong> ' . e($toilet->owner) . '<br>';
            $body .= '<strong>Type:</strong> ' . e($toilet->type) . '<br>';
            $body .= '<strong>Properties:</strong> ' . nl2br(e(json_encode($properties, JSON_PRETTY_PRINT))) . '</p>';
            $body .= '<p>';
            $body .= '<a href="https://wc-info.de/Toilets/xyz---' . $toilet->place_id . '/xyz-' . $toilet->id . '">Open</a> | ';
            $body .= '<a href="https://api.wc-info.de/toilet/' . $toilet->id . '/admin-qualify?hash=' . $hash . '">Qualify</a> | ';
            $body .= '<a href="https://api.wc-info.de/toilet/' . $toilet->id . '/admin-delete?hash=' . $hash . '">Delete</a>';
            $body .= '</p>';
        }

        $mail->send('hallo@wc-info.de', $toilets->count() . ' new toilet(s)!', $body, true);

        Toilet::whereIn('id', $toilets->pluck('id'))->update(['email_sent' => 1]);

        $this->info('Notification sent for ' . $toilets->count() . ' new toilet(s).');

        return self::SUCCESS;
    }
}
