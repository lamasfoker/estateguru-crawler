<?php
declare(strict_types=1);

namespace App\DomCrawler;

use Symfony\Component\DomCrawler\Crawler;

class CrawlerFactory
{
    public function create(): Crawler
    {
        return new Crawler();
    }
}
