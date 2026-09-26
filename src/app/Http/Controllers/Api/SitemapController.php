<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Toilet;
use Carbon\Carbon;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * Generate the XML sitemap.
     *
     * Returns a sitemap.org compatible XML document containing fixed pages
     * and all active qualified toilet detail pages.
     */
    public function output(): Response
    {
        $urls = [
            [
                'loc' => '/',
                'lastmod' => '2023-10-19',
                'changefreq' => 'daily',
                'priority' => '1.0',
            ],
            [
                'loc' => '/Toilets/Current-Location---NEARBY',
                'lastmod' => date('Y-m-d'),
                'changefreq' => 'daily',
                'priority' => '1.0',
            ],
            [
                'loc' => '/Help',
                'lastmod' => '2023-03-22',
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ],
            [
                'loc' => '/Law/Disclaimer',
                'lastmod' => '2023-03-22',
                'changefreq' => 'yearly',
                'priority' => '0.1',
            ],
            [
                'loc' => '/Law/Imprint',
                'lastmod' => '2023-03-22',
                'changefreq' => 'yearly',
                'priority' => '0.1',
            ],
            [
                'loc' => '/Law/Privacy',
                'lastmod' => '2023-03-22',
                'changefreq' => 'yearly',
                'priority' => '0.2',
            ],
        ];

        $toilets = Toilet::where('status', 'active')
            ->where('is_qualified', 1)
            ->with('properties')
            ->get();

        $recentCutoff = Carbon::now()->subDays(14)->timestamp;

        foreach ($toilets as $toilet) {
            $placeSegment = ! empty($toilet->place_id) ? $toilet->place_id : 'NEARBY';
            $loc = '/Toilets/Toilet---'.$placeSegment.'/Toilet---'.$toilet->id;

            $updatedDate = $toilet->updated
                ? $toilet->updated
                : ($toilet->created_at ? $toilet->created_at : null);

            $lastmod = $updatedDate ? $updatedDate->format('Y-m-d') : date('Y-m-d');
            $updatedTimestamp = $updatedDate ? $updatedDate->timestamp : 0;
            $isRecent = $updatedTimestamp >= $recentCutoff;
            $isPublic = $toilet->isFlagSet('public_accessible');

            if ($isPublic) {
                $priority = $isRecent ? '0.9' : '0.8';
            } else {
                $priority = $isRecent ? '0.7' : '0.6';
            }

            $urls[] = [
                'loc' => $loc,
                'lastmod' => $lastmod,
                'changefreq' => 'daily',
                'priority' => $priority,
            ];
        }

        usort($urls, function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority'];
        });

        $xml = simplexml_load_string('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');

        foreach ($urls as $url) {
            $urlEl = $xml->addChild('url');
            foreach ($url as $key => $val) {
                if ($key === 'loc') {
                    $val = str_starts_with((string) $val, 'http') ? (string) $val : 'https://wc-info.org'.$val;
                    $val = $this->replaceUmlauts((string) $val);
                }
                $urlEl->addChild($key, htmlspecialchars((string) $val, ENT_XML1, 'UTF-8'));
            }
        }

        return response($xml->asXML(), 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * Replace German umlauts and sharp s with ASCII equivalents to ensure valid URLs.
     */
    private function replaceUmlauts(string $text): string
    {
        return str_replace(
            ['Ä', 'Ö', 'Ü', 'ä', 'ö', 'ü', 'ß'],
            ['Ae', 'Oe', 'Ue', 'ae', 'oe', 'ue', 'ss'],
            $text
        );
    }
}
