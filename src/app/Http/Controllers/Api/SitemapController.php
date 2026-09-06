<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Toilet;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * Generate the XML sitemap.
     *
     * Returns a sitemap.org compatible XML document containing static pages
     * and all qualified toilet detail pages.
     */
    public function output(): Response
    {
        $urls = [
            ['loc' => '/', 'lastmod' => '2023-10-19', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => '/Toilets/Current-Location---NEARBY', 'lastmod' => date('Y-m-d'), 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => '/Help', 'lastmod' => '2023-03-22', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['loc' => '/Law/Disclaimer', 'lastmod' => '2023-03-22', 'changefreq' => 'yearly', 'priority' => '0.1'],
            ['loc' => '/Law/Imprint', 'lastmod' => '2023-03-22', 'changefreq' => 'yearly', 'priority' => '0.1'],
            ['loc' => '/Law/Privacy', 'lastmod' => '2023-03-22', 'changefreq' => 'yearly', 'priority' => '0.2'],
        ];

        $toilets = Toilet::qualified()
            ->where('status', '!=', 'deleted')
            ->with('place')
            ->orderByDesc('updated')
            ->get();

        foreach ($toilets as $toilet) {
            $placeName = $toilet->place?->data['name'] ?? $toilet->owner;

            $urls[] = [
                'loc' => '/Toilets/'.$this->slug($placeName).'---'.$toilet->place_id.'/'.$this->slug($toilet->owner.'-'.$toilet->name).'-'.$toilet->id,
                'lastmod' => date('Y-m-d', strtotime($toilet->updated)),
                'changefreq' => 'daily',
                'priority' => time() - strtotime($toilet->updated) < (60 * 60 * 24 * 4) ? '0.9' : '0.8',
            ];
        }

        usort($urls, function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        $xml = simplexml_load_string('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');

        foreach ($urls as $url) {
            $urlEl = $xml->addChild('url');
            foreach ($url as $key => $val) {
                if ($key === 'loc') {
                    $val = 'https://wc-info.org'.$val;
                }
                $urlEl->addChild($key, (string) $val);
            }
        }

        return response($xml->asXML(), 200)
            ->header('Content-Type', 'application/xml');
    }

    private function slug(string $text): string
    {
        $text = str_replace(
            ['Ä', 'Ö', 'Ü', 'ä', 'ö', 'ü', 'ß'],
            ['Ae', 'Oe', 'Ue', 'ae', 'oe', 'ue', 'ss'],
            $text
        );

        return preg_replace('/[^0-9a-zA-Z]+/', '-', $text);
    }
}
