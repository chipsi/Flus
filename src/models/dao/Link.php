<?php

namespace flusio\models\dao;

/**
 * Represent a link in database.
 *
 * @author  Marien Fressinaud <dev@marienfressinaud.fr>
 * @license http://www.gnu.org/licenses/agpl-3.0.en.html AGPL
 */
class Link extends \Minz\DatabaseModel
{
    use BulkHelper;

    /**
     * @throws \Minz\Errors\DatabaseError
     */
    public function __construct()
    {
        $properties = array_keys(\flusio\models\Link::PROPERTIES);
        parent::__construct('links', 'id', $properties);
    }

    /**
     * Return link with computed number_comments
     *
     * @param array $values
     *
     * @return array
     */
    public function findByWithNumberComments($values)
    {
        $parameters = [];
        $where_statement_as_array = [];
        foreach ($values as $property => $parameter) {
            $parameters[] = $parameter;
            $where_statement_as_array[] = "{$property} = ?";
        }
        $where_statement = implode(' AND ', $where_statement_as_array);

        $sql = <<<SQL
            SELECT l.*, (
                SELECT COUNT(*) FROM messages m
                WHERE m.link_id = l.id
            ) AS number_comments
            FROM links l
            WHERE {$where_statement}
        SQL;

        $statement = $this->prepare($sql);
        $statement->execute($parameters);
        $result = $statement->fetch();
        if ($result) {
            return $result;
        } else {
            return null;
        }
    }

    /**
     * Return links within the given collection
     *
     * You can pass an offset and a limit to paginate the results. It is not
     * paginated by default.
     *
     * @param string $collection_id
     * @param boolean $visible_only
     * @param integer $offset
     * @param integer|string $limit
     *
     * @return array
     */
    public function listByCollectionIdWithNumberComments($collection_id, $visible_only, $offset = 0, $limit = 'ALL')
    {
        $values = [
            ':collection_id' => $collection_id,
            ':offset' => $offset,
        ];

        $visibility_clause = '';
        if ($visible_only) {
            $visibility_clause = 'AND l.is_hidden = false';
        }

        $limit_clause = '';
        if ($limit !== 'ALL') {
            $limit_clause = 'LIMIT :limit';
            $values[':limit'] = $limit;
        }

        $sql = <<<SQL
            SELECT l.*, (
                SELECT COUNT(m.*) FROM messages m
                WHERE m.link_id = l.id
            ) AS number_comments
            FROM links l, links_to_collections lc

            WHERE l.id = lc.link_id
            AND lc.collection_id = :collection_id

            {$visibility_clause}

            ORDER BY l.created_at DESC, l.id
            OFFSET :offset
            {$limit_clause}
        SQL;

        $statement = $this->prepare($sql);
        $statement->execute($values);
        return $statement->fetchAll();
    }

    /**
     * Count links within the given collection
     *
     * @param string $collection_id
     * @param boolean $visible_only
     *
     * @return array
     */
    public function countByCollectionId($collection_id, $visible_only)
    {
        $visibility_clause = '';
        if ($visible_only) {
            $visibility_clause = 'AND l.is_hidden = false';
        }

        $sql = <<<SQL
            SELECT COUNT(l.*)
            FROM links l, links_to_collections lc

            WHERE l.id = lc.link_id
            AND lc.collection_id = :collection_id

            {$visibility_clause}
        SQL;

        $statement = $this->prepare($sql);
        $statement->execute([
            ':collection_id' => $collection_id,
        ]);
        return intval($statement->fetchColumn());
    }

    /**
     * Return links listed in bookmarks of the given user, ordered randomly.
     *
     * @param string $user_id
     *
     * @return array
     */
    public function listFromBookmarksForNews($user_id)
    {
        $sql = <<<'SQL'
            SELECT l.*, 'bookmarks' AS news_via_type
            FROM links l, collections c, links_to_collections lc

            WHERE lc.link_id = l.id
            AND lc.collection_id = c.id

            AND c.user_id = :user_id
            AND l.user_id = :user_id

            AND c.type = 'bookmarks'

            GROUP BY l.id

            ORDER BY random()
        SQL;

        $statement = $this->prepare($sql);
        $statement->execute([
            ':user_id' => $user_id,
        ]);
        return $statement->fetchAll();
    }

    /**
     * Return public links listed in followed collections of the given user,
     * ordered randomly. Links with a matching url in news_links are not
     * returned.
     *
     * @param string $user_id
     *
     * @return array
     */
    public function listFromFollowedCollectionsForNews($user_id)
    {
        $sql = <<<SQL
            SELECT l.*, 'followed' AS news_via_type, c.id AS news_via_collection_id
            FROM links l, collections c, links_to_collections lc, followed_collections fc

            WHERE fc.user_id = :user_id
            AND fc.collection_id = lc.collection_id

            AND lc.link_id = l.id
            AND lc.collection_id = c.id

            AND l.is_hidden = false
            AND c.is_public = true

            AND l.url NOT IN (
                SELECT nl.url FROM news_links nl
                WHERE nl.user_id = :user_id
            )

            GROUP BY l.id, c.id

            ORDER BY random()
            LIMIT 500
        SQL;

        $statement = $this->prepare($sql);
        $statement->execute([
            ':user_id' => $user_id,
        ]);
        return $statement->fetchAll();
    }

    /**
     * Return public links based on interests of the given user, ordered
     * randomly. Links with a matching url in news_links are not returned.
     *
     * @param string $user_id
     *
     * @return array
     */
    public function listFromTopicsForNews($user_id)
    {
        $sql = <<<SQL
            SELECT l.*, 'topics' AS news_via_type, ct.collection_id AS news_via_collection_id
            FROM links l, links_to_collections lc, collections_to_topics ct

            WHERE ct.topic_id IN (
                SELECT ut.topic_id FROM users_to_topics ut
                WHERE ut.user_id = :user_id
            )

            AND ct.collection_id = lc.collection_id
            AND lc.link_id = l.id

            AND l.is_hidden = false
            AND l.user_id != :user_id
            AND l.url NOT IN (
                SELECT nl.url FROM news_links nl
                WHERE nl.user_id = :user_id
            )

            GROUP BY l.id, ct.collection_id

            ORDER BY random()
            LIMIT 500
        SQL;

        $statement = $this->prepare($sql);
        $statement->execute([
            ':user_id' => $user_id,
        ]);
        return $statement->fetchAll();
    }

    /**
     * Return links with oldest fetched_at date.
     *
     * @param integer $number
     *
     * @return array
     */
    public function listByOldestFetching($number)
    {
        $sql = <<<SQL
             SELECT * FROM links
             ORDER BY fetched_at
             LIMIT ?
        SQL;

        $statement = $this->prepare($sql);
        $statement->execute([$number]);
        return $statement->fetchAll();
    }

    /**
     * Return the list of url ids indexed by urls for the given user.
     *
     * @param string $user_id
     *
     * @return array
     */
    public function listIdsByUrls($user_id)
    {
        $sql = <<<SQL
            SELECT id, url FROM links
            WHERE user_id = :user_id
        SQL;

        $statement = $this->prepare($sql);
        $statement->execute([
            ':user_id' => $user_id,
        ]);

        $ids_by_urls = [];
        foreach ($statement->fetchAll() as $row) {
            $ids_by_urls[$row['url']] = $row['id'];
        }
        return $ids_by_urls;
    }

    /**
     * Return the list of ids that needs to be synced (i.e. feed_entry_id is null)
     *
     * The ids are set as values AND keys of the returned array.
     *
     * @param string $user_id
     *
     * @return array
     */
    public function listIdsToFeedSync($user_id)
    {
        $sql = <<<SQL
            SELECT id FROM links
            WHERE user_id = :user_id
            AND feed_entry_id IS NULL
        SQL;

        $statement = $this->prepare($sql);
        $statement->execute([
            ':user_id' => $user_id,
        ]);

        $ids = [];
        foreach ($statement->fetchAll() as $row) {
            $ids[$row['id']] = $row['id'];
        }
        return $ids;
    }

    /**
     * Return a list of links to fetch (fetched_at is null, or fetched_error is
     * not null).
     *
     * Links in error are not returned if their fetched_count is greater than
     * 25 or if fetched_at is too close (a number of seconds depending on the
     * fetched_count value).
     *
     * @param integer $max_number
     *
     * @return array
     */
    public function listToFetch($max_number)
    {
        $sql = <<<SQL
            SELECT * FROM links
            WHERE fetched_at IS NULL
            OR (
                fetched_error IS NOT NULL
                AND fetched_count <= 25
                AND fetched_at < (?::timestamptz - interval '1 second' * (5 + pow(fetched_count, 4)))
            )
            ORDER BY random()
            LIMIT ?
        SQL;

        $now = \Minz\Time::now();
        $statement = $this->prepare($sql);
        $statement->execute([
            $now->format(\Minz\Model::DATETIME_FORMAT),
            $max_number,
        ]);
        return $statement->fetchAll();
    }
}
