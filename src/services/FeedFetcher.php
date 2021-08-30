<?php

namespace flusio\services;

use flusio\models;
use flusio\utils;

/**
 * @author  Marien Fressinaud <dev@marienfressinaud.fr>
 * @license http://www.gnu.org/licenses/agpl-3.0.en.html AGPL
 */
class FeedFetcher
{
    /** @var \SpiderBits\Cache */
    private $cache;

    /** @var \SpiderBits\Http */
    private $http;

    /** @var array */
    private $options = [
        'timeout' => 20,
        'rate_limit' => true,
        'cache' => true,
    ];

    /**
     * @param array $options
     *     A list of options where possible keys are:
     *     - timeout (integer)
     *     - rate_limit (boolean)
     *     - cache (boolean)
     */
    public function __construct($options = [])
    {
        $this->options = array_merge($this->options, $options);

        $cache_path = \Minz\Configuration::$application['cache_path'];
        $this->cache = new \SpiderBits\Cache($cache_path);

        $this->http = new \SpiderBits\Http();
        $this->http->user_agent = \Minz\Configuration::$application['user_agent'];
        $this->http->timeout = $this->options['timeout'];
    }

    /**
     * Fetch a feed collection
     *
     * @param \flusio\models\Collection $collection
     */
    public function fetch($collection)
    {
        $info = $this->fetchUrl($collection->feed_url);

        $collection->feed_fetched_at = \Minz\Time::now();
        $collection->feed_fetched_code = $info['status'];
        $collection->feed_fetched_error = null;
        if (isset($info['error'])) {
            $collection->feed_fetched_error = $info['error'];
            $collection->save();
            return;
        }

        $feed = $info['feed'];
        $feed_hash = $feed->hash();

        if ($feed_hash === $collection->feed_last_hash) {
            // The feed didn’t change, do nothing
            $collection->save();
            return;
        }

        $collection->feed_last_hash = $feed_hash;

        $title = substr(trim($feed->title), 0, models\Collection::NAME_MAX_LENGTH);
        if ($title) {
            $collection->name = $title;
        }

        $description = trim($feed->description);
        if ($description) {
            $collection->description = $description;
        }

        if ($feed->link) {
            $feed_site_url = \SpiderBits\Url::absolutize($feed->link, $collection->feed_url);
            $feed_site_url = \SpiderBits\Url::sanitize($feed_site_url);
        } else {
            $feed_site_url = $collection->feed_url;
        }

        $collection->feed_site_url = $feed_site_url;

        $collection->save();

        $link_ids_by_urls = models\Link::daoCall('listIdsByUrlsForCollection', $collection->id);
        $link_urls_by_entry_ids = models\Link::daoCall('listUrlsByEntryIdsForCollection', $collection->id);

        $links_columns = [];
        $links_to_create = [];
        $links_to_collections_to_create = [];

        foreach ($feed->entries as $entry) {
            if (!$entry->link) {
                continue;
            }

            $url = \SpiderBits\Url::absolutize($entry->link, $collection->feed_url);
            $url = \SpiderBits\Url::sanitize($url);

            if (isset($link_ids_by_urls[$url])) {
                // The URL is already associated to the collection, we have
                // nothing more to do.
                continue;
            }

            if ($entry->published_at) {
                $created_at = $entry->published_at;
            } else {
                $created_at = \Minz\Time::now();
            }

            if ($entry->id) {
                $feed_entry_id = $entry->id;
            } else {
                $feed_entry_id = $url;
            }

            if (
                isset($link_urls_by_entry_ids[$feed_entry_id]) &&
                $link_urls_by_entry_ids[$feed_entry_id]['url'] !== $url
            ) {
                // We detected a link with the same entry id has a different
                // URL. This can happen if the URL was changed by the publisher
                // after our first fetch. Normally, there is a redirection on
                // the server so it's not a big deal to not track this change,
                // but it duplicates content.
                // To avoid this problem, we update the link URL and publication
                // date. The title and fetched_at are also reset so the link is
                // resynchronised by the LinkFetcher service.
                $link_id = $link_urls_by_entry_ids[$feed_entry_id]['id'];
                models\Link::update($link_id, [
                    'url' => $url,
                    'title' => $url,
                    'created_at' => $created_at->format(\Minz\Model::DATETIME_FORMAT),
                    'fetched_at' => null,
                ]);
            } else {
                // The URL is not associated to the collection in database yet,
                // so we create a new link.
                $link = models\Link::init($url, $collection->user_id, false);
                $entry_title = trim($entry->title);
                if ($entry_title) {
                    $link->title = $entry_title;
                }
                $link->created_at = $created_at;
                $link->feed_entry_id = $feed_entry_id;

                $db_link = $link->toValues();
                $links_to_create = array_merge(
                    $links_to_create,
                    array_values($db_link)
                );
                if (!$links_columns) {
                    $links_columns = array_keys($db_link);
                }

                $link_ids_by_urls[$link->url] = $link->id;
                $link_urls_by_entry_ids[$link->url] = $link->feed_entry_id;
                $link_id = $link->id;
            }

            $links_to_collections_to_create[] = $link_id;
            $links_to_collections_to_create[] = $collection->id;
        }

        if ($links_to_create) {
            models\Link::daoCall(
                'bulkInsert',
                $links_columns,
                $links_to_create
            );
        }

        if ($links_to_collections_to_create) {
            $links_to_collections_dao = new models\dao\LinksToCollections();
            $links_to_collections_dao->bulkInsert(
                ['link_id', 'collection_id'],
                $links_to_collections_to_create
            );
        }

        if (!$collection->image_fetched_at) {
            try {
                $response = $this->http->get($collection->feed_site_url);
            } catch (\SpiderBits\HttpError $e) {
                return;
            }

            if (!$response->success) {
                return;
            }

            $content_type = $response->header('content-type');
            if (!utils\Belt::contains($content_type, 'text/html')) {
                $collection->image_fetched_at = \Minz\Time::now();
                $collection->save();
                return;
            }

            $encodings = mb_list_encodings();
            $data = mb_convert_encoding($response->data, 'UTF-8', $encodings);

            $dom = \SpiderBits\Dom::fromText($data);
            $url_illustration = \SpiderBits\DomExtractor::illustration($dom);
            $url_illustration = \SpiderBits\Url::sanitize($url_illustration);
            if (!$url_illustration) {
                $collection->image_fetched_at = \Minz\Time::now();
                $collection->save();
                return;
            }

            $image_service = new Image();
            $image_filename = $image_service->generatePreviews($url_illustration);
            $collection->image_filename = $image_filename;
            $collection->image_fetched_at = \Minz\Time::now();
            $collection->save();
        }
    }

    /**
     * Fetch URL content and return information about the feed
     *
     * @param string $url
     *
     * @return array Possible keys are:
     *     - status (always)
     *     - error
     *     - feed
     */
    public function fetchUrl($url)
    {
        // First, we "GET" the URL...
        $url_hash = \SpiderBits\Cache::hash($url);
        $cached_response = $this->cache->get($url_hash, 60 * 60);
        if ($this->options['cache'] && $cached_response) {
            // ... via the cache
            $response = \SpiderBits\Response::fromText($cached_response);
        } else {
            if (
                $this->options['rate_limit'] &&
                models\FetchLog::hasReachedRateLimit($url, 'feed')
            ) {
                // We slow down the requests
                $sleep_time = random_int(5, 10);
                \Minz\Time::sleep($sleep_time);
            }

            // ... or via HTTP
            models\FetchLog::log($url, 'feed');
            try {
                $response = $this->http->get($url);
            } catch (\SpiderBits\HttpError $e) {
                return [
                    'status' => 0,
                    'error' => $e->getMessage(),
                ];
            }

            // that we add to cache on success
            if ($response->success) {
                $this->cache->save($url_hash, (string)$response);
            }
        }

        $info = [
            'status' => $response->status,
        ];

        if (!$response->success) {
            $encodings = mb_list_encodings();
            $data = mb_convert_encoding($response->data, 'UTF-8', $encodings);

            // Okay, Houston, we've had a problem here. Return early, there's
            // nothing more to do.
            $info['error'] = $data;
            return $info;
        }

        $content_type = $response->header('content-type');
        if (!\SpiderBits\feeds\Feed::isFeedContentType($content_type)) {
            $info['error'] = "Invalid content type: {$content_type}";
            return $info; // @codeCoverageIgnore
        }

        try {
            $feed = \SpiderBits\feeds\Feed::fromText($response->data);
            $info['feed'] = $feed;
        } catch (\Exception $e) {
            $info['error'] = (string)$e;
        }

        return $info;
    }
}
