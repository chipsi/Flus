<?php

namespace flusio\models\dao;

/**
 * Connect links to collections
 *
 * @author  Marien Fressinaud <dev@marienfressinaud.fr>
 * @license http://www.gnu.org/licenses/agpl-3.0.en.html AGPL
 */
class LinksToCollections extends \Minz\DatabaseModel
{
    /**
     * @throws \Minz\Errors\DatabaseError
     */
    public function __construct()
    {
        $properties = ['id', 'link_id', 'collection_id'];
        parent::__construct('links_to_collections', 'id', $properties);
    }

    /**
     * Attach the collections to the given link.
     *
     * @param string $link_id
     * @param string[] $collection_ids
     *
     * @return boolean True on success
     */
    public function attachCollectionsToLink($link_id, $collection_ids)
    {
        $values_as_question_marks = [];
        $values = [];
        foreach ($collection_ids as $collection_id) {
            $values_as_question_marks[] = '(?, ?)';
            $values = array_merge($values, [$link_id, $collection_id]);
        }
        $values_placeholder = implode(", ", $values_as_question_marks);

        $sql = <<<SQL
            INSERT INTO links_to_collections (link_id, collection_id)
            VALUES {$values_placeholder};
        SQL;

        $statement = $this->prepare($sql);
        $result = $statement->execute($values);
        return $this->lastInsertId();
    }

    /**
     * Find a relation between a link and a collection for the given user.
     *
     * @param string $user_id
     * @param string $link_id
     * @param string $collection_id
     *
     * return array|null
     */
    public function findRelation($user_id, $link_id, $collection_id)
    {
        $sql = <<<'SQL'
            SELECT * FROM links_to_collections
            WHERE link_id = (
                SELECT id FROM links
                WHERE id = ? AND user_id = ?
            ) AND collection_id = (
                SELECT id FROM collections
                WHERE id = ? AND user_id = ?
            );
        SQL;

        $statement = $this->prepare($sql);
        $statement->execute([$link_id, $user_id, $collection_id, $user_id]);
        $result = $statement->fetch();
        if ($result) {
            return $result;
        } else {
            return null;
        }
    }
}
