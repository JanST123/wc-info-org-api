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
     * Supported language codes.
     */
    public const array SUPPORTED_LANGUAGES = ['de', 'en', 'nl'];

    /**
     * Generate the XML sitemap.
     *
     * Returns a sitemap.org compatible XML document containing fixed pages
     * and all active qualified toilet detail pages across all supported languages (de, en, nl)
     * with xhtml:link alternate annotations.
     */
    public function output(): Response
    {
        $pages = [
            [
                'path' => '/',
                'lastmod' => '2026-09-28',
                'changefreq' => 'daily',
                'priority' => '1.0',
            ],
            [
                'path' => '/Toilets/Current-Location---NEARBY',
                'lastmod' => date('Y-m-d'),
                'changefreq' => 'daily',
                'priority' => '1.0',
            ],
            [
                'path' => '/Help',
                'lastmod' => '2023-03-22',
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ],
            [
                'path' => '/Law/Disclaimer',
                'lastmod' => '2023-03-22',
                'changefreq' => 'yearly',
                'priority' => '0.1',
            ],
            [
                'path' => '/Law/Imprint',
                'lastmod' => '2023-03-22',
                'changefreq' => 'yearly',
                'priority' => '0.1',
            ],
            [
                'path' => '/Law/Privacy',
                'lastmod' => '2023-03-22',
                'changefreq' => 'yearly',
                'priority' => '0.2',
            ],
        ];

        $toilets = Toilet::where('status', 'active')
            ->where('is_qualified', 1)
            ->with(['properties', 'place'])
            ->get();

        $recentCutoff = Carbon::now()->subDays(14)->timestamp;

        foreach ($toilets as $toilet) {
            $placePrefix = 'Toilet';
            if ($toilet->place) {
                $placeName = $toilet->place->getName();
                $placeSlug = $this->slug($placeName);
                if ($placeSlug !== '') {
                    $placePrefix = $placeSlug;
                }
            }

            $placeId = ! empty($toilet->place_id) ? $toilet->place_id : 'NEARBY';
            $placeSegment = $placePrefix.'---'.$placeId;

            $toiletPrefix = 'Toilet';
            if (! empty($toilet->owner)) {
                $ownerSlug = $this->slug($toilet->owner);
                if ($ownerSlug !== '') {
                    $toiletPrefix = $ownerSlug;
                }
            } elseif (! empty($toilet->name)) {
                $nameSlug = $this->slug($toilet->name);
                if ($nameSlug !== '') {
                    $toiletPrefix = $nameSlug;
                }
            }

            $toiletSegment = $toiletPrefix.'---'.$toilet->id;

            $path = '/Toilets/'.$placeSegment.'/'.$toiletSegment;

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

            $pages[] = [
                'path' => $path,
                'lastmod' => $lastmod,
                'changefreq' => 'daily',
                'priority' => $priority,
            ];
        }

        $urls = [];
        foreach ($pages as $page) {
            $alternates = [];
            foreach (self::SUPPORTED_LANGUAGES as $lang) {
                $alternates[$lang] = $this->buildLocalizedUrl($lang, $page['path']);
            }

            $urls[] = [
                'loc' => $this->buildMainUrl($page['path']),
                'alternates' => $alternates,
                'lastmod' => $page['lastmod'],
                'changefreq' => $page['changefreq'],
                'priority' => $page['priority'],
            ];
        }

        usort($urls, function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority'];
        });

        $xml = simplexml_load_string(
            '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml"></urlset>'
        );

        foreach ($urls as $url) {
            $urlEl = $xml->addChild('url');
            $urlEl->addChild('loc', htmlspecialchars($url['loc'], ENT_XML1, 'UTF-8'));

            foreach ($url['alternates'] as $lang => $href) {
                $link = $urlEl->addChild('xhtml:link', null, 'http://www.w3.org/1999/xhtml');
                $link->addAttribute('rel', 'alternate');
                $link->addAttribute('hreflang', $lang);
                $link->addAttribute('href', htmlspecialchars($href, ENT_XML1, 'UTF-8'));
            }

            $urlEl->addChild('lastmod', htmlspecialchars($url['lastmod'], ENT_XML1, 'UTF-8'));
            $urlEl->addChild('changefreq', htmlspecialchars($url['changefreq'], ENT_XML1, 'UTF-8'));
            $urlEl->addChild('priority', htmlspecialchars($url['priority'], ENT_XML1, 'UTF-8'));
        }

        return response($xml->asXML(), 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * Build the main (unlocalized) URL for a path.
     */
    private function buildMainUrl(string $path): string
    {
        $path = $this->replaceUmlauts($path);
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return 'https://wc-info.org'.$path;
    }

    /**
     * Build the localized URL for a language code and path.
     */
    private function buildLocalizedUrl(string $lang, string $path): string
    {
        $path = $this->replaceUmlauts($path);
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return 'https://wc-info.org/'.$lang.$path;
    }

    /**
     * Create a URL-compatible slug from text with umlaut replacement.
     */
    private function slug(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        $text = $this->replaceUmlauts($text);

        return trim((string) preg_replace('/[^0-9a-zA-Z]+/', '-', $text), '-');
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
