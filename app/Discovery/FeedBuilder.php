<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Discovery;

/**
 * Builds an Atom 1.0 feed string (discovery 3.2). Pure string assembly with strict XML escaping — the same
 * dependency-free approach as SitemapController. Every dynamic value passes through htmlspecialchars with
 * ENT_XML1, so a topic title / post excerpt can never break the document.
 */
final class FeedBuilder
{
    /**
     * @param  array{title:string,url:string,selfUrl:string,updated:string}  $meta
     * @param  list<array{title:string,url:string,id:string,updated:string,summary?:string,author?:string}>  $entries
     */
    public function atom(array $meta, array $entries): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom">';
        $xml .= '<title>'.$esc($meta['title']).'</title>';
        $xml .= '<link href="'.$esc($meta['url']).'" rel="alternate" type="text/html"/>';
        $xml .= '<link href="'.$esc($meta['selfUrl']).'" rel="self" type="application/atom+xml"/>';
        $xml .= '<id>'.$esc($meta['selfUrl']).'</id>';
        $xml .= '<updated>'.$esc($meta['updated']).'</updated>';

        foreach ($entries as $entry) {
            $xml .= '<entry>';
            $xml .= '<title>'.$esc($entry['title']).'</title>';
            $xml .= '<link href="'.$esc($entry['url']).'" rel="alternate" type="text/html"/>';
            $xml .= '<id>'.$esc($entry['id']).'</id>';
            $xml .= '<updated>'.$esc($entry['updated']).'</updated>';
            if (($entry['summary'] ?? '') !== '') {
                $xml .= '<summary>'.$esc($entry['summary']).'</summary>';
            }
            if (($entry['author'] ?? '') !== '') {
                $xml .= '<author><name>'.$esc($entry['author']).'</name></author>';
            }
            $xml .= '</entry>';
        }

        $xml .= '</feed>';

        return $xml;
    }
}
