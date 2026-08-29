<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SitemapUrlResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'loc' => $this['loc'],
            'lastmod' => $this['lastmod'],
            'changefreq' => $this['changefreq'],
            'priority' => $this['priority'],
        ];
    }
}
