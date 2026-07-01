<?php
/**
 * Base Model
 *
 * Abstract foundation for all MongoDB Eloquent models in the Gandalf API. Extends
 * the Jenssegers MongoDB Eloquent model to add three conveniences: a fluent save()
 * that throws FailedToSaveModel instead of returning false, a static findById()
 * that throws IdNotFoundException when the ID is empty or not found, and helpers
 * (getId, isNew, createId) for working with MongoDB ObjectIDs.
 *
 * @package App\Models
 */
namespace App\Models;

use \MongoDB\BSON\ObjectID;
use App\Exceptions\FailedToSaveModel;
use App\Exceptions\IdNotFoundException;
use Jenssegers\Mongodb\Eloquent\Model as Eloquent;

/**
 * Class Base
 * @package App\Models
 * @property string $_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
abstract class Base extends Eloquent
{
    const PRIMARY_KEY = '_id';
    protected $connection = 'mongodb';
    protected $validation_rules = [];

    protected $casts = [
        '_id' => 'string',
    ];

    /**
     * Return the model's primary key value (_id).
     *
     * @return string|null
     */
    public function getId()
    {
        return $this->{self::PRIMARY_KEY};
    }

    /**
     * Determine whether this is a new (unsaved) model instance.
     *
     * A model is considered new if it has no _id assigned yet.
     *
     * @return bool
     */
    public function isNew()
    {
        return empty($this->getId());
    }

    /**
     * Generate and assign a new MongoDB ObjectID to this model.
     *
     * Useful when you need to know the future ID before the document is persisted.
     *
     * @return void
     */
    public function createId()
    {
        $this->{self::PRIMARY_KEY} = new ObjectID;
    }

    /**
     * Save the model and return $this for method chaining.
     *
     * Unlike the parent which returns a boolean, this override throws
     * FailedToSaveModel (HTTP 400) if the underlying MongoDB write fails,
     * making error handling uniform across all callers.
     *
     * @param  array $options
     * @return $this
     * @throws FailedToSaveModel
     */
    public function save(array $options = [])
    {
        if (parent::save($options)) {
            return $this;
        }

        throw new FailedToSaveModel;
    }

    /**
     * Find a model by its MongoDB _id, throwing exceptions for bad inputs.
     *
     * Throws IdNotFoundException (404) if $id is empty, and Eloquent's own
     * ModelNotFoundException (also mapped to 404) if no document is found.
     *
     * @param  string $id  MongoDB ObjectID string.
     * @return static
     * @throws IdNotFoundException
     */
    public static function findById($id)
    {
        if (empty($id)) {
            throw new IdNotFoundException;
        }

        return self::where(self::PRIMARY_KEY, $id)->firstOrFail();
    }
}
