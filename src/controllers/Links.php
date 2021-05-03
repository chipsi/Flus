<?php

namespace flusio\controllers;

use Minz\Response;
use flusio\auth;
use flusio\models;
use flusio\services;
use flusio\utils;

/**
 * Handle the requests related to the links.
 *
 * @author  Marien Fressinaud <dev@marienfressinaud.fr>
 * @license http://www.gnu.org/licenses/agpl-3.0.en.html AGPL
 */
class Links
{
    /**
     * Show a link page.
     *
     * @request_param string id
     *
     * @response 302 /login?redirect_to=/links/:id
     *     if user is not connected and the link is not public
     * @response 404
     *     if the link doesn't exist or is inaccessible to current user
     * @response 200
     */
    public function show($request)
    {
        $user = auth\CurrentUser::get();
        $link_id = $request->param('id');
        $link = models\Link::find($link_id);

        $can_view = auth\LinksAccess::canView($user, $link);
        $can_update = auth\LinksAccess::canUpdate($user, $link);
        if (!$can_view && $user) {
            return Response::notFound('not_found.phtml');
        } elseif (!$can_view) {
            return Response::redirect('login', [
                'redirect_to' => \Minz\Url::for('link', ['id' => $link_id]),
            ]);
        }

        $is_atom_feed = utils\Belt::endsWith($request->path(), 'feed.atom.xml');
        if ($is_atom_feed) {
            $locale = $link->owner()->locale;
            utils\Locale::setCurrentLocale($locale);
            $response = Response::ok('links/feed.atom.xml.phtml', [
                'link' => $link,
                'messages' => $link->messages(),
                'user_agent' => \Minz\Configuration::$application['user_agent'],
            ]);
            $response->setHeader('Content-Type', 'application/atom+xml;charset=UTF-8');
            return $response;
        } elseif ($can_update) {
            $collections = $link->collections();
            models\Collection::sort($collections, $user->locale);

            return Response::ok('links/show.phtml', [
                'link' => $link,
                'collections' => $collections,
                'messages' => $link->messages(),
                'comment' => '',
            ]);
        } else {
            return Response::ok('links/show_public.phtml', [
                'link' => $link,
                'messages' => $link->messages(),
            ]);
        }
    }

    /**
     * Show the page to add a link.
     *
     * @request_param string url The URL to prefill the URL input (default is '')
     * @request_param string[] collection_ids Collection to check (default contains bookmarks id)
     *
     * @response 302 /login?redirect_to=/links/new if not connected
     * @response 200
     */
    public function new($request)
    {
        $user = auth\CurrentUser::get();
        $default_url = $request->param('url', '');

        if (!$user) {
            if ($default_url) {
                $redirect_to = \Minz\Url::for('new link', ['url' => $default_url]);
            } else {
                $redirect_to = \Minz\Url::for('new link');
            }

            return Response::redirect('login', ['redirect_to' => $redirect_to]);
        }

        $collections = $user->collections();
        models\Collection::sort($collections, $user->locale);

        $default_collection_id = $request->param('collection');
        if ($default_collection_id) {
            $default_collection_ids = [$default_collection_id];
        } else {
            $bookmarks_collection = $user->bookmarks();
            $default_collection_ids = [$bookmarks_collection->id];
        }

        return Response::ok('links/new.phtml', [
            'url' => $default_url,
            'is_hidden' => false,
            'collection_ids' => $default_collection_ids,
            'collections' => $collections,
        ]);
    }

    /**
     * Create a link for the current user.
     *
     * @request_param string csrf
     * @request_param string url It must be a valid non-empty URL
     * @request_param boolean is_hidden
     * @request_param string[] collection_ids It must contain at least one
     *                                        collection id
     *
     * @response 302 /login?redirect_to=/links/new if not connected
     * @response 400 if CSRF or the url is invalid, of if one collection id
     *               doesn't exist or parameter is missing/empty
     * @response 302 /links/:id on success
     */
    public function create($request)
    {
        $user = auth\CurrentUser::get();
        $url = $request->param('url', '');
        $is_hidden = $request->param('is_hidden', false);
        $collection_ids = $request->param('collection_ids', []);

        if (!$user) {
            return Response::redirect('login', [
                'redirect_to' => \Minz\Url::for('new link', ['url' => $url]),
            ]);
        }

        $collections = $user->collections();
        models\Collection::sort($collections, $user->locale);

        $csrf = new \Minz\CSRF();
        if (!$csrf->validateToken($request->param('csrf'))) {
            return Response::badRequest('links/new.phtml', [
                'url' => $url,
                'is_hidden' => $is_hidden,
                'collection_ids' => $collection_ids,
                'collections' => $collections,
                'error' => _('A security verification failed: you should retry to submit the form.'),
            ]);
        }

        $link = models\Link::init($url, $user->id, $is_hidden);
        $errors = $link->validate();
        if ($errors) {
            return Response::badRequest('links/new.phtml', [
                'url' => $url,
                'is_hidden' => $is_hidden,
                'collection_ids' => $collection_ids,
                'collections' => $collections,
                'errors' => $errors,
            ]);
        }

        if (empty($collection_ids)) {
            return Response::badRequest('links/new.phtml', [
                'url' => $url,
                'is_hidden' => $is_hidden,
                'collection_ids' => $collection_ids,
                'collections' => $collections,
                'errors' => [
                    'collection_ids' => _('The link must be associated to a collection.'),
                ],
            ]);
        }

        if (!models\Collection::daoCall('existForUser', $user->id, $collection_ids)) {
            return Response::badRequest('links/new.phtml', [
                'url' => $url,
                'is_hidden' => $is_hidden,
                'collection_ids' => $collection_ids,
                'collections' => $collections,
                'errors' => [
                    'collection_ids' => _('One of the associated collection doesn’t exist.'),
                ],
            ]);
        }

        $existing_link = models\Link::findBy([
            'url' => $link->url,
            'user_id' => $user->id,
        ]);
        if ($existing_link) {
            $link = $existing_link;
        } else {
            $link_fetcher_service = new services\LinkFetcher([
                'timeout' => 10,
                'rate_limit' => false,
            ]);
            $link_fetcher_service->fetch($link);
        }

        $existing_collections = $link->collections();
        $existing_collection_ids = array_column($existing_collections, 'id');
        $collection_ids = array_diff($collection_ids, $existing_collection_ids);
        if ($collection_ids) {
            $links_to_collections_dao = new models\dao\LinksToCollections();
            $links_to_collections_dao->attach($link->id, $collection_ids);
        }

        return Response::redirect('link', [
            'id' => $link->id,
        ]);
    }

    /**
     * Show the update link page.
     *
     * @request_param string id
     * @request_param string from (default is /links/:id)
     *
     * @response 302 /login?redirect_to=/links/:id/edit if not connected
     * @response 404 if the link doesn't exist or not associated to the current user
     * @response 200
     */
    public function edit($request)
    {
        $user = auth\CurrentUser::get();
        $link_id = $request->param('id');
        $from = $request->param('from', \Minz\Url::for('link', ['id' => $link_id]));

        if (!$user) {
            return Response::redirect('login', [
                'redirect_to' => \Minz\Url::for('edit link', ['id' => $link_id]),
            ]);
        }

        $link = models\Link::find($link_id);
        if (auth\LinksAccess::canUpdate($user, $link)) {
            return Response::ok('links/edit.phtml', [
                'link' => $link,
                'title' => $link->title,
                'is_hidden' => $link->is_hidden,
                'from' => $from,
            ]);
        } else {
            return Response::notFound('not_found.phtml');
        }
    }

    /**
     * Update a link.
     *
     * @request_param string csrf
     * @request_param string id
     * @request_param string title
     * @request_param boolean is_hidden
     * @request_param string from (default is /links/:id)
     *
     * @response 302 /login?redirect_to=/links/:id/edit if not connected
     * @response 404 if the link doesn't exist or not associated to the current user
     * @response 302 :from if csrf token or title are invalid
     * @response 302 :from
     */
    public function update($request)
    {
        $user = auth\CurrentUser::get();
        $link_id = $request->param('id');
        $new_title = $request->param('title');
        $is_hidden = $request->param('is_hidden', false);
        $from = $request->param('from', \Minz\Url::for('link', ['id' => $link_id]));

        if (!$user) {
            return Response::redirect('login', [
                'redirect_to' => \Minz\Url::for('edit link', ['id' => $link_id]),
            ]);
        }

        $link = models\Link::find($link_id);
        if (!auth\LinksAccess::canUpdate($user, $link)) {
            return Response::notFound('not_found.phtml');
        }

        $csrf = new \Minz\CSRF();
        if (!$csrf->validateToken($request->param('csrf'))) {
            utils\Flash::set('error', _('A security verification failed.'));
            return Response::found($from);
        }

        $link->title = trim($new_title);
        $link->is_hidden = filter_var($is_hidden, FILTER_VALIDATE_BOOLEAN);
        $errors = $link->validate();
        if ($errors) {
            utils\Flash::set('errors', $errors);
            return Response::found($from);
        }

        $link->save();

        return Response::found($from);
    }

    /**
     * Delete a link
     *
     * @request_param string id
     * @request_param string from default is /links/:id
     * @request_param string redirect_to default is /
     *
     * @response 302 /login?redirect_to=:from if not connected
     * @response 404 if the link doesn’t exist or user hasn't access
     * @response 302 :from if csrf is invalid
     * @response 302 :redirect_to on success
     */
    public function delete($request)
    {
        $user = auth\CurrentUser::get();
        $link_id = $request->param('id');
        $from = $request->param('from', \Minz\Url::for('link', ['id' => $link_id]));
        $redirect_to = $request->param('redirect_to', \Minz\Url::for('home'));

        if (!$user) {
            return Response::redirect('login', ['redirect_to' => $from]);
        }

        $link = models\Link::find($link_id);
        if (!auth\LinksAccess::canDelete($user, $link)) {
            return Response::notFound('not_found.phtml');
        }

        $csrf = new \Minz\CSRF();
        if (!$csrf->validateToken($request->param('csrf'))) {
            utils\Flash::set('error', _('A security verification failed.'));
            return Response::found($from);
        }

        models\Link::delete($link->id);

        return Response::found($redirect_to);
    }

    /**
     * Mark a link as read and remove it from bookmarks.
     *
     * @request_param string csrf
     * @request_param string id
     *
     * @response 302 /login?redirect_to=/bookmarks
     *     if not connected
     * @response 404
     *     if the link doesn't exist, or is not associated to the current user
     * @response 302 /bookmarks
     * @flash error
     *     if CSRF is invalid
     * @response 302 /bookmarks
     *     on success
     */
    public function markAsRead($request)
    {
        $user = auth\CurrentUser::get();
        $from = \Minz\Url::for('bookmarks');
        $link_id = $request->param('id');

        if (!$user) {
            return Response::redirect('login', ['redirect_to' => $from]);
        }

        $csrf = new \Minz\CSRF();
        if (!$csrf->validateToken($request->param('csrf'))) {
            utils\Flash::set('error', _('A security verification failed.'));
            return Response::found($from);
        }

        $link = models\Link::find($link_id);
        if (!auth\LinksAccess::canUpdate($user, $link)) {
            return Response::notFound('not_found.phtml');
        }

        // First, we make sure to mark a corresponding news link as read
        $news_link = models\NewsLink::findBy([
            'url' => $link->url,
            'user_id' => $user->id,
        ]);
        if (!$news_link) {
            $news_link = models\NewsLink::initFromLink($link, $user->id);
        }
        $news_link->via_type = 'bookmarks';
        $news_link->is_read = true;
        $news_link->save();

        // Then, we detach the link from the bookmarks
        $bookmarks = $user->bookmarks();
        $links_to_collections_dao = new models\dao\LinksToCollections();
        $links_to_collections_dao->detach($link->id, [$bookmarks->id]);

        return Response::found($from);
    }

    /**
     * Do nothing, it handles webextension requests on the removed fetch endpoint.
     *
     * @response 200
     */
    public function fetch()
    {
        return \Minz\Response::ok();
    }
}
