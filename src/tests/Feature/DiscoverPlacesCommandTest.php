<?php

namespace Tests\Feature;

use App\Models\Toilet;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DiscoverPlacesCommandTest extends TestCase
{
    public function test_discovers_only_toilets_that_were_included_but_not_yet_discovered(): void
    {
        $now = now();

        $includedButNotDiscovered = Toilet::create([
            'name' => 'WC #1',
            'owner' => 'Cafe A',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => 'place_a',
            'status' => 'active',
            'last_included' => $now,
            'last_discovered' => null,
        ]);

        Toilet::create([
            'name' => 'WC #2',
            'owner' => 'Cafe B',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => 'place_b',
            'status' => 'active',
            'last_included' => $now,
            'last_discovered' => $now,
        ]);

        Toilet::create([
            'name' => 'WC #3',
            'owner' => 'Cafe C',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => 'place_c',
            'status' => 'active',
            'last_included' => $now,
            'last_discovered' => $now->copy()->subHour(),
        ]);

        $status = Artisan::call('app:discover-places', ['--dry-run' => true, '--limit' => 500]);
        $output = Artisan::output();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Found', $output);
        $this->assertStringContainsString('toilets to discover.', $output);
        $this->assertStringContainsString("toilet_id={$includedButNotDiscovered->id} place_id=place_a", $output);
        $this->assertStringContainsString('place_id=place_c', $output);
        $this->assertStringNotContainsString('place_id=place_b', $output);

        Toilet::whereIn('place_id', ['place_a', 'place_b', 'place_c'])->delete();
    }

    public function test_logs_debug_details_when_existing_toilet_is_updated(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        $placeId = 'place_log_test_' . uniqid();

        $placesService = $this->createMock(\App\Services\GooglePlacesService::class);
        $placesService->method('fetchPlaceDetails')
            ->willReturn([
                'id' => $placeId,
                'displayName' => ['text' => 'New Owner Name'],
                'location' => ['latitude' => 52.8, 'longitude' => 13.8],
                'formattedAddress' => 'Updated Address 123',
            ]);

        $this->app->instance(\App\Services\GooglePlacesService::class, $placesService);

        $toilet = Toilet::create([
            'name' => 'WC Existing',
            'owner' => 'Old Owner Name',
            'lat' => 52.0,
            'lon' => 13.0,
            'place_id' => $placeId,
            'status' => 'active',
            'last_included' => now()->addDays(10),
            'last_discovered' => null,
        ]);

        $status = Artisan::call('app:discover-places', ['--limit' => 1]);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('debug')
            ->with(
                \Mockery::pattern('/Updated toilet ' . $toilet->id . '/'),
                \Mockery::on(function ($context) use ($toilet, $placeId) {
                    return $context['toilet_id'] === $toilet->id
                        && $context['place_id'] === $placeId
                        && isset($context['changes']['owner'])
                        && $context['changes']['owner']['old'] === 'Old Owner Name'
                        && $context['changes']['owner']['new'] === 'New Owner Name';
                })
            );

        $toilet->delete();
        \App\Models\Place::where('place_id', $placeId)->delete();
        Toilet::where('place_id', 'place_log_test')->delete();
        \App\Models\Place::where('place_id', 'place_log_test')->delete();
    }
}
