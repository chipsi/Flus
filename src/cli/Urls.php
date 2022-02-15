<?php

namespace flusio\cli;

use Minz\Response;

/**
 * @author  Marien Fressinaud <dev@marienfressinaud.fr>
 * @license http://www.gnu.org/licenses/agpl-3.0.en.html AGPL
 */
class Urls
{
    /**
     * Show the HTTP response returned by an URL.
     *
     * @param_request string url
     *
     * @response 400
     *     If the URL is invalid.
     * @response 500
     *     If the URL cannot be resolved.
     * @response 200
     */
    public function show($request)
    {
        $url = $request->param('url');
        $url_is_valid = filter_var($url, FILTER_VALIDATE_URL) !== false;
        if (!$url_is_valid) {
            return Response::text(400, "`{$url}` is not a valid URL.");
        }

        try {
            $http = new \SpiderBits\Http();
            $response = $http->get($url);
        } catch (\SpiderBits\HttpError $error) {
            return Response::text(500, $error->getMessage());
        }

        return Response::text(200, (string)$response);
    }

    /**
     * Clear the cache of the given URL.
     *
     * @param_request string url
     *
     * @response 500
     *     If the cache cannot be cleared.
     * @response 200
     */
    public function uncache($request)
    {
        $url = $request->param('url');
        $url_is_valid = filter_var($url, FILTER_VALIDATE_URL) !== false;
        if (!$url_is_valid) {
            return Response::text(400, "`{$url}` is not a valid URL.");
        }

        $url_hash = \SpiderBits\Cache::hash($url);
        $cache = new \SpiderBits\Cache(\Minz\Configuration::$application['cache_path']);
        $result = $cache->remove($url_hash);
        if ($result) {
            return Response::text(200, "Cache for {$url} ({$url_hash}) has been cleared.");
        } else {
            return Response::text(500, "Cache for {$url} ({$url_hash}) cannot be cleared.");
        }
    }
}
