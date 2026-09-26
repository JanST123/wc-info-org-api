<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Toilet;
use App\Models\ToiletProperty;
use Carbon\Carbon;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    private array $createdToiletIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->createdToiletIds)) {
            ToiletProperty::whereIn('fk_toiletId', $this->createdToiletIds)->delete();
            Toilet::whereIn('id', $this->createdToiletIds)->delete();
        }

        parent::tearDown();
    }

    public function test_sitemap_returns_valid_xml_with_fixed_urls(): void
    {
        $response = $this->get('/sitemap');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $content = $response->getContent();
        $xml = simplexml_load_string($content);
        $this->assertNotFalse($xml, 'Sitemap should be valid XML');
        $this->assertSame('urlset', $xml->getName());

        $urls = [];
        foreach ($xml->url as $url) {
            $urls[(string) $url->loc] = [
                'lastmod' => (string) $url->lastmod,
                'changefreq' => (string) $url->changefreq,
                'priority' => (string) $url->priority,
            ];
        }

        // Fixed links assertions
        $this->assertArrayHasKey('https://wc-info.org/', $urls);
        $this->assertSame('2023-10-19', $urls['https://wc-info.org/']['lastmod']);
        $this->assertSame('daily', $urls['https://wc-info.org/']['changefreq']);
        $this->assertSame('1.0', $urls['https://wc-info.org/']['priority']);

        $this->assertArrayHasKey('https://wc-info.org/Toilets/Current-Location---NEARBY', $urls);
        $this->assertSame(date('Y-m-d'), $urls['https://wc-info.org/Toilets/Current-Location---NEARBY']['lastmod']);
        $this->assertSame('daily', $urls['https://wc-info.org/Toilets/Current-Location---NEARBY']['changefreq']);
        $this->assertSame('1.0', $urls['https://wc-info.org/Toilets/Current-Location---NEARBY']['priority']);

        $this->assertArrayHasKey('https://wc-info.org/Help', $urls);
        $this->assertSame('2023-03-22', $urls['https://wc-info.org/Help']['lastmod']);
        $this->assertSame('monthly', $urls['https://wc-info.org/Help']['changefreq']);
        $this->assertSame('0.7', $urls['https://wc-info.org/Help']['priority']);

        $this->assertArrayHasKey('https://wc-info.org/Law/Disclaimer', $urls);
        $this->assertSame('2023-03-22', $urls['https://wc-info.org/Law/Disclaimer']['lastmod']);
        $this->assertSame('yearly', $urls['https://wc-info.org/Law/Disclaimer']['changefreq']);
        $this->assertSame('0.1', $urls['https://wc-info.org/Law/Disclaimer']['priority']);

        $this->assertArrayHasKey('https://wc-info.org/Law/Imprint', $urls);
        $this->assertSame('2023-03-22', $urls['https://wc-info.org/Law/Imprint']['lastmod']);
        $this->assertSame('yearly', $urls['https://wc-info.org/Law/Imprint']['changefreq']);
        $this->assertSame('0.1', $urls['https://wc-info.org/Law/Imprint']['priority']);

        $this->assertArrayHasKey('https://wc-info.org/Law/Privacy', $urls);
        $this->assertSame('2023-03-22', $urls['https://wc-info.org/Law/Privacy']['lastmod']);
        $this->assertSame('yearly', $urls['https://wc-info.org/Law/Privacy']['changefreq']);
        $this->assertSame('0.2', $urls['https://wc-info.org/Law/Privacy']['priority']);
    }

    public function test_sitemap_includes_active_qualified_toilets_with_correct_url_schema_and_priorities(): void
    {
        // 1. Active & Qualified with place_id + public_accessible + recently updated -> Priority 0.9
        $toilet1 = Toilet::create([
            'name' => 'Active Qualified Public Recent',
            'place_id' => 'ChIJplace123',
            'status' => 'active',
            'is_qualified' => 1,
            'updated' => Carbon::now()->subDays(2),
        ]);
        $this->createdToiletIds[] = $toilet1->id;
        ToiletProperty::create([
            'fk_toiletId' => $toilet1->id,
            'type' => 'public_accessible',
            'value' => '1',
        ]);

        // 2. Active & Qualified with NULL place_id + public_accessible + older -> Priority 0.8
        $toilet2 = Toilet::create([
            'name' => 'Active Qualified Public Older',
            'place_id' => null,
            'status' => 'active',
            'is_qualified' => 1,
            'updated' => Carbon::now()->subDays(30),
        ]);
        $this->createdToiletIds[] = $toilet2->id;
        ToiletProperty::create([
            'fk_toiletId' => $toilet2->id,
            'type' => 'public_accessible',
            'value' => '1',
        ]);

        // 3. Active & Qualified with place_id + NOT public_accessible + recently updated -> Priority 0.7
        $toilet3 = Toilet::create([
            'name' => 'Active Qualified Private Recent',
            'place_id' => 'ChIJplace456',
            'status' => 'active',
            'is_qualified' => 1,
            'updated' => Carbon::now()->subDays(3),
        ]);
        $this->createdToiletIds[] = $toilet3->id;

        // 4. Active & Qualified with NULL place_id + NOT public_accessible + older -> Priority 0.6
        $toilet4 = Toilet::create([
            'name' => 'Active Qualified Private Older',
            'place_id' => null,
            'status' => 'active',
            'is_qualified' => 1,
            'updated' => Carbon::now()->subDays(60),
        ]);
        $this->createdToiletIds[] = $toilet4->id;

        // 5. Inactive toilet (status=hidden, is_qualified=1) -> Should NOT appear
        $hiddenToilet = Toilet::create([
            'name' => 'Hidden Qualified Toilet',
            'place_id' => 'ChIJhidden',
            'status' => 'hidden',
            'is_qualified' => 1,
        ]);
        $this->createdToiletIds[] = $hiddenToilet->id;

        // 6. Deleted toilet (status=deleted, is_qualified=1) -> Should NOT appear
        $deletedToilet = Toilet::create([
            'name' => 'Deleted Qualified Toilet',
            'place_id' => 'ChIJdeleted',
            'status' => 'deleted',
            'is_qualified' => 1,
        ]);
        $this->createdToiletIds[] = $deletedToilet->id;

        // 7. Unqualified toilet (status=active, is_qualified=0) -> Should NOT appear
        $unqualifiedToilet = Toilet::create([
            'name' => 'Active Unqualified Toilet',
            'place_id' => 'ChIJunqualified',
            'status' => 'active',
            'is_qualified' => 0,
        ]);
        $this->createdToiletIds[] = $unqualifiedToilet->id;

        $response = $this->get('/sitemap');
        $response->assertStatus(200);

        $xml = simplexml_load_string($response->getContent());
        $urls = [];
        $priorityList = [];
        foreach ($xml->url as $url) {
            $loc = (string) $url->loc;
            $prio = (float) $url->priority;
            $urls[$loc] = [
                'lastmod' => (string) $url->lastmod,
                'changefreq' => (string) $url->changefreq,
                'priority' => (string) $url->priority,
            ];
            $priorityList[] = $prio;
        }

        // Expected URLs
        $url1 = "https://wc-info.org/Toilets/Toilet---ChIJplace123/Toilet---{$toilet1->id}";
        $url2 = "https://wc-info.org/Toilets/Toilet---NEARBY/Toilet---{$toilet2->id}";
        $url3 = "https://wc-info.org/Toilets/Toilet---ChIJplace456/Toilet---{$toilet3->id}";
        $url4 = "https://wc-info.org/Toilets/Toilet---NEARBY/Toilet---{$toilet4->id}";

        $this->assertArrayHasKey($url1, $urls);
        $this->assertSame('0.9', $urls[$url1]['priority']);

        $this->assertArrayHasKey($url2, $urls);
        $this->assertSame('0.8', $urls[$url2]['priority']);

        $this->assertArrayHasKey($url3, $urls);
        $this->assertSame('0.7', $urls[$url3]['priority']);

        $this->assertArrayHasKey($url4, $urls);
        $this->assertSame('0.6', $urls[$url4]['priority']);

        // Excluded URLs
        $this->assertArrayNotHasKey("https://wc-info.org/Toilets/Toilet---ChIJhidden/Toilet---{$hiddenToilet->id}", $urls);
        $this->assertArrayNotHasKey("https://wc-info.org/Toilets/Toilet---ChIJdeleted/Toilet---{$deletedToilet->id}", $urls);
        $this->assertArrayNotHasKey("https://wc-info.org/Toilets/Toilet---ChIJunqualified/Toilet---{$unqualifiedToilet->id}", $urls);

        // Verify sorted by priority desc
        $sortedPriorities = $priorityList;
        rsort($sortedPriorities);
        $this->assertSame($sortedPriorities, $priorityList);
    }

    public function test_sitemap_replaces_umlauts_in_urls(): void
    {
        $toilet = Toilet::create([
            'name' => 'Umlaut Toilet München-Süd',
            'place_id' => 'ChIJ_münchen_äöüß_place',
            'status' => 'active',
            'is_qualified' => 1,
        ]);
        $this->createdToiletIds[] = $toilet->id;

        $response = $this->get('/sitemap');
        $response->assertStatus(200);

        $xml = simplexml_load_string($response->getContent());
        $urls = [];
        foreach ($xml->url as $url) {
            $urls[] = (string) $url->loc;
        }

        $expectedUrl = "https://wc-info.org/Toilets/Toilet---ChIJ_muenchen_aeoeuess_place/Toilet---{$toilet->id}";
        $this->assertContains($expectedUrl, $urls);
        $this->assertNotContains("https://wc-info.org/Toilets/Toilet---ChIJ_münchen_äöüß_place/Toilet---{$toilet->id}", $urls);
    }
}
