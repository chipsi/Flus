<?php

namespace flusio;

use Minz\Response;

/**
 * Handle the requests related to the news.
 *
 * @author  Marien Fressinaud <dev@marienfressinaud.fr>
 * @license http://www.gnu.org/licenses/agpl-3.0.en.html AGPL
 */
class NewsLinks
{
    /**
     * Show the news page.
     *
     * @response 302 /login?redirect_to=/news
     *     if not connected
     * @response 200
     *
     * @param \Minz\Request $request
     *
     * @return \Minz\Response
     */
    public function index()
    {
        $user = utils\CurrentUser::get();
        if (!$user) {
            return Response::redirect('login', [
                'redirect_to' => \Minz\Url::for('news'),
            ]);
        }

        $news_links = $user->newsLinks();
        $tip_no_news = [
            _('As you’re navigating on the Internet, bookmark the links you would like to read later.'),
            _('Find public collections created by other users, and follow them if you like them.'),
            _('Ask the developer to implement the suggestions based on public links (it will come!)'),
        ];

        return Response::ok('news_links/index.phtml', [
            'news_links' => $news_links,
            'no_news' => utils\Flash::pop('no_news'),
            'tip_no_news' => $tip_no_news[array_rand($tip_no_news)],
        ]);
    }

    /**
     * Allow to add a link from a news_link (which is mark as read). If a link
     * already exists with the same URL, it is offered to update it.
     *
     * @request_param string id
     *
     * @response 302 /login?redirect_to=/news/:id/add
     *     if not connected
     * @response 404
     *     if the link doesn't exist, or is not associated to the current user
     * @response 200
     *     on success
     *
     * @param \Minz\Request $request
     *
     * @return \Minz\Response
     */
    public function adding($request)
    {
        $user = utils\CurrentUser::get();
        $news_link_id = $request->param('id');

        if (!$user) {
            return Response::redirect('login', [
                'redirect_to' => \Minz\Url::for('add news', ['id' => $news_link_id]),
            ]);
        }

        $news_link = $user->newsLink($news_link_id);
        if (!$news_link) {
            return Response::notFound('not_found.phtml');
        }

        $collections = $user->collections(true);
        models\Collection::sort($collections, $user->locale);

        $existing_link = $user->linkByUrl($news_link->url);
        if ($existing_link) {
            $is_public = $existing_link->is_public;
            $existing_collections = $existing_link->collections();
            $collection_ids = array_column($existing_collections, 'id');
        } else {
            $is_public = false;
            $collection_ids = [];
        }

        return Response::ok('news_links/adding.phtml', [
            'news_link' => $news_link,
            'is_public' => $is_public,
            'collection_ids' => $collection_ids,
            'collections' => $collections,
            'comment' => '',
            'exists_already' => $existing_link !== null,
        ]);
    }

    /**
     * Mark a news_link as read and add it as a link to the user's collections.
     *
     * @request_param string id
     * @request_param string csrf
     * @request_param boolean is_public
     * @request_param string[] collection_ids
     * @request_param string comment
     *
     * @response 302 /login?redirect_to=/news/:id/add
     *     if not connected
     * @response 404
     *     if the link doesn't exist, or is not associated to the current user
     * @response 400
     *     if CSRF is invalid, if collection_ids is empty or contains inexisting ids
     * @response 302 /news
     *     on success
     *
     * @param \Minz\Request $request
     *
     * @return \Minz\Response
     */
    public function add($request)
    {
        $user = utils\CurrentUser::get();
        $news_link_id = $request->param('id');

        if (!$user) {
            return Response::redirect('login', [
                'redirect_to' => \Minz\Url::for('add news', ['id' => $news_link_id]),
            ]);
        }

        $news_link = $user->newsLink($news_link_id);
        if (!$news_link) {
            return Response::notFound('not_found.phtml');
        }

        $link_dao = new models\dao\Link();
        $news_link_dao = new models\dao\NewsLink();
        $collection_dao = new models\dao\Collection();
        $links_to_collections_dao = new models\dao\LinksToCollections();
        $message_dao = new models\dao\Message();

        $collections = $user->collections(true);
        models\Collection::sort($collections, $user->locale);

        $existing_link = $user->linkByUrl($news_link->url);

        $is_public = $request->param('is_public', false);
        $collection_ids = $request->param('collection_ids', []);
        $comment = $request->param('comment', '');

        $csrf = new \Minz\CSRF();
        if (!$csrf->validateToken($request->param('csrf'))) {
            return Response::badRequest('news_links/adding.phtml', [
                'news_link' => $news_link,
                'is_public' => $is_public,
                'collection_ids' => $collection_ids,
                'collections' => $collections,
                'comment' => $comment,
                'exists_already' => $existing_link !== null,
                'error' => _('A security verification failed: you should retry to submit the form.'),
            ]);
        }

        if (empty($collection_ids)) {
            return Response::badRequest('news_links/adding.phtml', [
                'news_link' => $news_link,
                'is_public' => $is_public,
                'collection_ids' => $collection_ids,
                'collections' => $collections,
                'comment' => $comment,
                'exists_already' => $existing_link !== null,
                'errors' => [
                    'collection_ids' => _('The link must be associated to a collection.'),
                ],
            ]);
        }

        if (!$collection_dao->existForUser($user->id, $collection_ids)) {
            return Response::badRequest('news_links/adding.phtml', [
                'news_link' => $news_link,
                'is_public' => $is_public,
                'collection_ids' => $collection_ids,
                'collections' => $collections,
                'comment' => $comment,
                'exists_already' => $existing_link !== null,
                'errors' => [
                    'collection_ids' => _('One of the associated collection doesn’t exist.'),
                ],
            ]);
        }

        // First, save the link (if a Link with matching URL exists, just get
        // this link and optionally change its is_public status)
        if ($existing_link) {
            $link = $existing_link;
        } else {
            $link = models\Link::initFromNews($news_link, $user->id);
        }
        $link->is_public = filter_var($is_public, FILTER_VALIDATE_BOOLEAN);
        $link_dao->save($link);

        // Attach the link to the given collections (and potentially forget the
        // old ones)
        $links_to_collections_dao->set($link->id, $collection_ids);

        // Then, if a comment has been passed, save it.
        if (trim($comment)) {
            $message = models\Message::init($user->id, $link->id, $comment);
            $message_dao->save($message);
        }

        // Finally, hide the news_link from the news page.
        $news_link->is_hidden = true;
        $news_link_dao->save($news_link);

        return Response::redirect('news');
    }

    /**
     * Fill the news page with links to read (from bookmarks and followed
     * collections)
     *
     * @request_param string csrf
     *
     * @response 302 /login?redirect_to=/news
     *     if not connected
     * @response 400
     *     if csrf is invalid
     * @response 302 /news
     *
     * @param \Minz\Request $request
     *
     * @return \Minz\Response
     */
    public function fill($request)
    {
        $user = utils\CurrentUser::get();
        if (!$user) {
            return Response::redirect('login', [
                'redirect_to' => \Minz\Url::for('news'),
            ]);
        }

        $csrf = new \Minz\CSRF();
        if (!$csrf->validateToken($request->param('csrf'))) {
            return Response::badRequest('news_links/index.phtml', [
                'news_links' => [],
                'no_news' => false,
                'error' => _('A security verification failed: you should retry to submit the form.'),
            ]);
        }

        $news_link_dao = new models\dao\NewsLink();
        $news_picker = new services\NewsPicker($user);
        $db_links = $news_picker->pick();

        foreach ($db_links as $db_link) {
            $link = new models\Link($db_link);
            $news_link = models\NewsLink::initFromLink($link, $user->id);
            $values = $news_link->toValues();
            $values['created_at'] = \Minz\Time::now()->format(\Minz\Model::DATETIME_FORMAT);
            // The id should be set by the DB. Here, PostgreSQL fails because
            // its value is null.
            unset($values['id']);
            $news_link_dao->create($values);
        }

        if (!$db_links) {
            utils\Flash::set('no_news', true);
        }

        return Response::redirect('news');
    }

    /**
     * Remove a link from news and add it to bookmarks.
     *
     * @request_param string csrf
     * @request_param string id
     *
     * @response 302 /login?redirect_to=/news
     *     if not connected
     * @response 302 /news
     *     if the link doesn't exist, or is not associated to the current user
     * @response 302 /news
     *     if CSRF is invalid
     * @response 302 /news
     *     on success
     *
     * @param \Minz\Request $request
     *
     * @return \Minz\Response
     */
    public function readLater($request)
    {
        $user = utils\CurrentUser::get();
        $from = \Minz\Url::for('news');
        $news_link_id = $request->param('id');

        if (!$user) {
            return Response::redirect('login', ['redirect_to' => $from]);
        }

        $news_link = $user->newsLink($news_link_id);
        if (!$news_link) {
            utils\Flash::set('error', _('The link doesn’t exist.'));
            return Response::found($from);
        }

        $csrf = new \Minz\CSRF();
        if (!$csrf->validateToken($request->param('csrf'))) {
            utils\Flash::set('error', _('A security verification failed.'));
            return Response::found($from);
        }

        $links_to_collections_dao = new models\dao\LinksToCollections();
        $link_dao = new models\dao\Link();
        $news_link_dao = new models\dao\NewsLink();

        // First, we want the link with corresponding URL to exist for the
        // current user (or it would be impossible to bookmark it correctly).
        // If it doesn't exist, let's create it in DB from the $news_link variable.
        $link = $user->linkByUrl($news_link->url);
        if (!$link) {
            $link = models\Link::initFromNews($news_link, $user->id);
            $link_dao->save($link);
        }

        // Then, we check if the link is bookmarked. If it isn't, bookmark it.
        $bookmarks = $user->bookmarks();
        $actual_collection_ids = array_column($link->collections(), 'id');
        if (!in_array($bookmarks->id, $actual_collection_ids)) {
            $links_to_collections_dao->attach($link->id, [$bookmarks->id]);
        }

        // Then, remove the news (we don't hide it since it would no longer be
        // suggested to the user).
        $news_link_dao->delete($news_link->id);

        return Response::found($from);
    }

    /**
     * Hide a link from news and remove it from bookmarks.
     *
     * @request_param string csrf
     * @request_param string id
     *
     * @response 302 /login?redirect_to=/news
     *     if not connected
     * @response 302 /news
     *     if the link doesn't exist, or is not associated to the current user
     * @response 302 /news
     *     if CSRF is invalid
     * @response 302 /news
     *     on success
     *
     * @param \Minz\Request $request
     *
     * @return \Minz\Response
     */
    public function hide($request)
    {
        $user = utils\CurrentUser::get();
        $from = \Minz\Url::for('news');
        $news_link_id = $request->param('id');

        if (!$user) {
            return Response::redirect('login', ['redirect_to' => $from]);
        }

        $news_link = $user->newsLink($news_link_id);
        if (!$news_link) {
            utils\Flash::set('error', _('The link doesn’t exist.'));
            return Response::found($from);
        }

        $csrf = new \Minz\CSRF();
        if (!$csrf->validateToken($request->param('csrf'))) {
            utils\Flash::set('error', _('A security verification failed.'));
            return Response::found($from);
        }

        $links_to_collections_dao = new models\dao\LinksToCollections();
        $news_link_dao = new models\dao\NewsLink();

        // First, hide the link from the news.
        $news_link->is_hidden = true;
        $news_link_dao->save($news_link);

        // Then, we try to find a link with corresponding URL in order to
        // remove it from bookmarks.
        $link = $user->linkByUrl($news_link->url);
        if ($link) {
            $bookmarks = $user->bookmarks();
            $actual_collection_ids = array_column($link->collections(), 'id');
            if (in_array($bookmarks->id, $actual_collection_ids)) {
                $links_to_collections_dao->detach($link->id, [$bookmarks->id]);
            }
        }

        return Response::found($from);
    }
}
