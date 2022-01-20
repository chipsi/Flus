<?php

namespace flusio\controllers;

use Minz\Response;
use flusio\auth;
use flusio\models;
use flusio\utils;

/**
 * @author  Marien Fressinaud <dev@marienfressinaud.fr>
 * @license http://www.gnu.org/licenses/agpl-3.0.en.html AGPL
 */
class Profiles
{
    /**
     * Show the public profile page of a user.
     *
     * @request_param string id
     *
     * @response 404
     *    If the requested profile doesn’t exist or is associated to the
     *    support user.
     * @response 200
     *    On success
     */
    public function show($request)
    {
        $user_id = $request->param('id');
        $user = models\User::find($user_id);
        if (!$user || $user->isSupportUser()) {
            return Response::notFound('not_found.phtml');
        }

        $current_user = auth\CurrentUser::get();
        $is_current_user_profile = $current_user && $current_user->id === $user->id;

        $is_atom_feed = utils\Belt::endsWith($request->path(), 'feed.atom.xml');
        if ($is_atom_feed) {
            utils\Locale::setCurrentLocale($user->locale);
            $links = $user->links(['published_at'], [
                'unshared' => false,
                'limit' => 30,
            ]);

            return Response::ok('profiles/feed.atom.xml.php', [
                'user' => $user,
                'links' => $links,
                'user_agent' => \Minz\Configuration::$application['user_agent'],
            ]);
        } else {
            $links = $user->links(['published_at', 'number_comments', 'is_read'], [
                'unshared' => false,
                'limit' => 6,
                'context_user_id' => $current_user ? $current_user->id : null,
            ]);

            $collections = $user->collections(['number_links'], [
                'private' => false,
                'count_hidden' => $is_current_user_profile,
            ]);
            utils\Sorter::localeSort($collections, 'name');

            return Response::ok('profiles/show.phtml', [
                'user' => $user,
                'links' => $links,
                'collections' => $collections,
                'is_current_user_profile' => $is_current_user_profile,
            ]);
        }
    }
}
