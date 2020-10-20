<?php

namespace SpiderBits;

/**
 * The DOM extractor, pure juice.
 *
 * @author  Marien Fressinaud <dev@marienfressinaud.fr>
 * @license http://www.gnu.org/licenses/agpl-3.0.en.html AGPL
 */
class DomExtractor
{
    /**
     * Return the title of the DOM document.
     *
     * @param \SpiderBits\Dom $dom
     *
     * @return string
     */
    public static function title($dom)
    {
        $xpath_queries = [
            // Look for OpenGraph title first
            '/html/head/meta[@property = "og:title"][1]/attribute::content',
            // Then Twitter meta tag
            '/html/head/meta[@name = "twitter:title"][1]/attribute::content',
            // Still nothing? Look for a <title> tag
            '/html/head/title[1]',

            // Err, still nothing! Let's try to be more tolerant (e.g. Youtube
            // puts the meta and title tags in the body :/)
            '//meta[@property = "og:title"][1]/attribute::content',
            '//meta[@name = "twitter:title"][1]/attribute::content',
            // For titles, we must be sure to not consider svg title tags!
            '//title[not(ancestor::svg)][1]',
        ];

        foreach ($xpath_queries as $query) {
            $title = $dom->select($query);
            if ($title) {
                return $title->text();
            }
        }

        // It's hopeless...
        return '';
    }

    /**
     * Return the main content of the DOM document.
     *
     * @param \SpiderBits\Dom $dom
     *
     * @return string
     */
    public static function content($dom)
    {
        $body = $dom->select('//body');
        if (!$body) {
            return '';
        }

        $main_node = $body->select('//main');
        if (!$main_node) {
            $main_node = $body->select('//*[@id = "main"]');
        }

        if (!$main_node) {
            $main_node = $body;
        }

        $main_node->remove('//script');

        return $main_node->text();
    }
}
