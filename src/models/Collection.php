<?php

namespace flusio\models;

use flusio\utils;

/**
 * Represent a list containing a set of links.
 *
 * @author  Marien Fressinaud <dev@marienfressinaud.fr>
 * @license http://www.gnu.org/licenses/agpl-3.0.en.html AGPL
 */
class Collection extends \Minz\Model
{
    use DaoConnector;

    public const VALID_TYPES = ['bookmarks', 'collection'];

    public const PROPERTIES = [
        'id' => [
            'type' => 'string',
            'required' => true,
        ],

        'created_at' => 'datetime',

        'name' => [
            'type' => 'string',
            'required' => true,
            'validator' => '\flusio\models\Collection::validateName',
        ],

        'description' => [
            'type' => 'string',
        ],

        'type' => [
            'type' => 'string',
            'required' => true,
            'validator' => '\flusio\models\Collection::validateType',
        ],

        'is_public' => [
            'type' => 'boolean',
            'required' => true,
        ],

        'user_id' => [
            'type' => 'string',
            'required' => true,
        ],

        'number_links' => [
            'type' => 'integer',
            'computed' => true,
        ],
    ];

    /**
     * Initialize the model with default values.
     *
     * @param mixed $values
     */
    public function __construct($values)
    {
        parent::__construct(array_merge([
            'id' => utils\Random::timebased(),
            'description' => '',
            'type' => 'collection',
            'is_public' => false,
        ], $values));
    }

    /**
     * @param string $user_id
     * @param string $name
     * @param string $description
     * @param boolean|string $is_public
     *
     * @return \flusio\models\Collection
     */
    public static function init($user_id, $name, $description, $is_public)
    {
        return new self([
            'name' => trim($name),
            'description' => trim($description),
            'is_public' => filter_var($is_public, FILTER_VALIDATE_BOOLEAN),
            'user_id' => $user_id,
        ]);
    }

    /**
     * @param string $user_id
     *
     * @return \flusio\models\Collection
     */
    public static function initBookmarks($user_id)
    {
        return new self([
            'name' => _('Bookmarks'),
            'type' => 'bookmarks',
            'user_id' => $user_id,
        ]);
    }

    /**
     * Return the name of the collection.
     *
     * If the collection is of "bookmarks" type, the localized version is
     * returned.
     *
     * @return string
     */
    public function name()
    {
        if ($this->type === 'bookmarks') {
            return _('Bookmarks');
        } else {
            return $this->name;
        }
    }

    /**
     * Return the owner of the collection.
     *
     * @return \flusio\models\User
     */
    public function owner()
    {
        return User::find($this->user_id);
    }

    /**
     * Return the list of links attached to this collection
     *
     * @return \flusio\models\Link[]
     */
    public function links()
    {
        return Link::daoToList('listByCollectionIdWithNumberComments', $this->id);
    }

    /**
     * Return the list of public (only) links attached to this collection
     *
     * @return \flusio\models\Link[]
     */
    public function publicLinks()
    {
        return Link::daoToList('listPublicByCollectionIdWithNumberComments', $this->id);
    }

    /**
     * Return the topics attached to the current collection
     *
     * @return \flusio\models\Topic[]
     */
    public function topics()
    {
        return Topic::daoToList('listByCollectionId', $this->id);
    }

    /**
     * Sort collections based on given locale
     *
     * @param \flusio\models\Collection[] $collections
     * @param string $locale
     */
    public static function sort(&$collections, $locale)
    {
        $collator = new \Collator($locale);
        usort($collections, function ($collection1, $collection2) use ($collator) {
            if ($collection1->type === 'bookmarks') {
                return -1;
            }

            if ($collection2->type === 'bookmarks') {
                return 1;
            }

            return $collator->compare($collection1->name, $collection2->name);
        });
    }

    /**
     * @param string $type
     * @return boolean
     */
    public static function validateType($type)
    {
        return in_array($type, self::VALID_TYPES);
    }

    /**
     * @param string $name
     * @return boolean
     */
    public static function validateName($name)
    {
        return strlen($name) <= 100;
    }

    /**
     * Return a list of errors (if any). The array keys indicated the concerned
     * property.
     *
     * @return string[]
     */
    public function validate()
    {
        $formatted_errors = [];

        foreach (parent::validate() as $property => $error) {
            $code = $error['code'];

            if ($property === 'name' && $code === 'required') {
                $formatted_error = _('The name is required.');
            } elseif ($property === 'name') {
                $formatted_error = _('The name must be less than 100 characters.');
            } else {
                $formatted_error = $error['description']; // @codeCoverageIgnore
            }

            $formatted_errors[$property] = $formatted_error;
        }

        return $formatted_errors;
    }
}
