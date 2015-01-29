<?php

/**
 * This file is part of the rstore package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Rstore;

use stdClass;

/**
 * Repository stores and retrieves models from redis.
 *
 * @author Dan Munro <dan @ danmunro.com>
 */

class Repository {

    /**
     * @var \Rstore\Connection $connection A client connection to redis.
     */
    protected $connection = null;

    /**
     * @var array $models An array representing models and their descriptions,
     * this is akin to table definitions in relational databases.
     */
    protected $models = array();

    /**
     * Constructor. Sets the connection and models properties.
     *
     * @param \Rstore\Connection $connection A client connection to redis.
     * @param array $models An array of models.
     */
    public function __construct(Connection $connection, $models) {
        $this->connection = $connection;
        $this->models = $models;
    }

    /**
     * Creates a named model from the known definitions and optionally assigns
     * properties to the new object.
     *
     * @param string $modelName The name of the model to create.
     * @param array $properties Key-value pairs of properties to assign to the
     * newly created model.
     *
     * @throws Exception\ModelNotFound ModelNotFound will be thrown if the provided model name is not found
     * in the model definitions for the repository.
     *
     * @return \stdClass A model.
     */
    public function create($modelName, $properties = array()) {
        $model = null;

        
        if(isset($this->models[$modelName])) {
            $model = new stdClass();
            foreach($this->models[$modelName] as $propertyName => $property) {
                if(isset($property['default'])) {
                    $model->$propertyName = $property['default'];
                    continue;
                }
            }
            $model->created_date = null;
            $model->modified_date = null;
            $model->soft_delete = null;
            $model->id = 0;
            $model->RepositoryNameSave = $modelName;
            foreach($properties as $property => $value) {
                $model->$property = $value;
            }
        } else {
            throw new \Exception\ModelNotFound($modelName.' model not found');
        }
        return $this->fillModelProperties($model);
    }

    /**
     * Validates a given model against the model definition and saves it to redis.
     *
     * @throws Exception\Validation A validation exception will be thrown if the 
     * provided model does not match its repository definition.
     *
     * @param \stdClass $model A model.
     *
     * @return void
     */
    public function save(stdClass $model) {
        $this->validate($model);
        if(!$model->created_date) {
            $model->created_date = time();
        }
        $model->modified_date = time();
        if(!$model->id) {
            $model->id = $this->getNewID($model->RepositoryNameSave);
            $this->connection->rpush($model->RepositoryNameSave, $model->id);
        }
        foreach($model as $property => $value) {
            if(is_object($value)) {
                $this->save($value);
                $this->connection->hset($model->RepositoryNameSave.':'.$model->id, $property, $value->RepositoryNameSave.':model:'.$value->id);
            } else if(is_array($value)) {
                foreach($value as $v) {
                    if(is_object($v)) {
                        $this->save($v);
                        $this->connection->rpush($model->id.':list:'.$property, $v->RepositoryNameSave.':model:'.$v->id);
                    } else {
                        $this->connection->rpush($model->id.':list:'.$property, $v);
                    }
                }
                $this->connection->hset($model->RepositoryNameSave.':'.$model->id, $property, $model->id.':list:'.$property);
            } else {
                $this->connection->hset($model->RepositoryNameSave.':'.$model->id, $property, $value);
            }
        }
        foreach($this->models[$model->RepositoryNameSave] as $propertyName => $property) {
            if(isset($property['index']) && $property['index']) {
                $this->connection->hset($model->RepositoryNameSave.':'.$propertyName, $model->$propertyName, $model->id);
            }
            if(isset($property['sort']) && $property['sort']) {
                $this->connection->zadd($model->RepositoryNameSave.':'.$propertyName, self::getScore($property['type'], $model->$propertyName), $model->id);
            }
        }
    }

    /**
     * Loads a named model by the given index.
     *
     * @param string $modelName Name of the model to load.
     * @param string $index Index to use when looking for the model.
     * @param mixed $value Value of the index to look up.
     *
     * @return \stdClass $model Found model, or null.
     */
    public function loadByIndex($modelName, $index, $value) {
        $id = $this->connection->hget($modelName.':'.$index, $value);
        $result = null;
        if($id) {
            $result = new stdClass();
            foreach($this->connection->hgetall($modelName.':'.$id) as $property => $value) {
                if(strpos($value, ':model:') !== false) {
                    $value = $this->getModelFromIdentifier($value);
                } else if(strpos($value, ':list:') !== false) {
                    $value = $this->getArrayFromIdentifier($value);
                }
                $result->$property = self::getValueType($value);
            }
            $result = $this->fillModelProperties($result);
        }
        return $result;
    }

    /**
     * Loads an array of named models by insertion date.
     *
     * @param string $modelName Name of the models to load.
     * @param int $start The start offset for loading models.
     * @param int $stop The stop offset for loading models.
     *
     * @return array Named models matching the given range.
     */
    public function load($modelName, $start, $stop) {
        $results = array();
        foreach($this->connection->lrange($modelName, $start, $stop) as $modelID) {
            $model = new stdClass();
            foreach($this->connection->hgetall($modelName.':'.$modelID) as $property => $value) {
                if(strpos($value, ':model:') !== false) {
                    $model->$property = $this->getModelFromIdentifier($value);
                } else if(strpos($value, ':list:') !== false) {
                    $model->$property = $this->getArrayFromIdentifier($value);
                } else {
                    $model->$property = self::getValueType($value);
                }
            }
            $results[] = $this->fillModelProperties($model);
        }
        return $results;
    }

    /**
     * Loads an array of named models by reverse order of insertion date.
     *
     * @param string $modelName Name of the models to load.
     * @param int $start The start offset for loading models.
     * @param int $stop The stop offset for loading models.
     *
     * @return array Named models matching the given range.
     */
    public function loadReverse($modelName, $start, $stop) {
        $len = $this->connection->llen($modelName);
        if($stop === -1) {
            $stop = $len;
        }
        $newStart = $len - $stop;
        $newStop = $len - $start - 1;
        return array_reverse($this->load($modelName, $newStart, $newStop));
    }

    /**
     * Loads an array of named models by a specified property and sort
     * orientation (asc/desc).
     *
     * @param string $modelName Name of the models to load.
     * @param string $propertyName Name of the property to sort the models on.
     * @param string $direction Ascending sort or descending sort.
     * @param int $start The index to start at.
     * @param int $stop The index to stop at.
     *
     * @return array An array of sorted models in the range requested.
     */
    public function loadBySort($modelName, $propertyName, $direction = 'desc', $start = 0, $stop = -1) {
        if($direction === 'desc') {
            $command = 'zrange';
        } elseif($direction === 'asc') {
            $command = 'zrevrange';
        } else {
            //error
        }

        $ids = $this->connection->$command($modelName.':'.$propertyName, $start, $stop);
        $results = array();
        foreach($ids as $id) {
            $results[] = $this->loadByIndex($modelName, 'id', $id);
        }
        return $results;
    }

    /**
     * @protected
     */
    protected function validate(stdClass $model) {
        foreach($this->models[$model->RepositoryNameSave] as $propertyName => $property) {
            $this->validateType($propertyName, $property, $model);
        }
    }

    /**
     * @protected
     */
    protected function validateType($propertyName, $propertyDef, $model) {
        $value = $model->$propertyName;
        if($propertyName != 'id' && !$value) {
            if(self::propertyIs($propertyDef, 'required')) {
                throw new Exception\Validation($propertyName." is required");
            }
            if(self::propertyIs($propertyDef, 'index')) {
                throw new Exception\Validation($propertyName." is required");
            }
        }
        if($value && (self::propertyIs($propertyDef, 'unique') || self::propertyIs($propertyDef, 'index'))) {
            $result = $this->loadByIndex($model->RepositoryNameSave, $propertyName, $value);
            if($result && $result->id != $result->id) {
                throw new Exception\Validation($propertyName." must be unique.");
            }
        }
        switch($propertyDef['type']) {
            case 'string':
                if(is_string($value)) {
                    if(isset($propertyDef['maxlength']) && strlen($value) > $propertyDef['maxlength']) {
                        throw new Exception\Validation($propertyName." exceeds max length");
                    }
                    return true;
                }
                throw new Exception\Validation($propertyName." should be string, is not");
            case 'integer':
                if(!is_numeric($value)) {
                    throw new Exception\Validation($propertyName." should be integer, is not");
                }
                return true;
            case 'model':
                if($value && (!is_object($value) || $value->name != $propertyDef['ref'])) {
                    throw new Exception\Validation($propertyName." should be model, is not");
                }
                return true;
            case 'array':
                if(isset($propertyDef['ref'])) {
                    if(is_array($value)) {
                        foreach($value as $child) {
                            if(is_object($child) && $child->name == $propertyDef['ref']) {
                                continue;
                            }
                            throw new Exception\Validation($propertyName." should contain only models, does not");
                        }
                        return true;
                    }
                    throw new Exception\Validation($propertyName." should be an array, is not");
                }
                return is_array($value);
            default:
                throw new Exception\Validation($propertyName." is an invalid type");
        }
    }

    /**
     * @protected
     */
    protected function getModelFromIdentifier($identifier) {
        $parts = explode(':', $identifier);
        if(sizeof($parts) == 3) {
            return $this->loadByIndex($parts[0], 'id', $parts[2]);
        }
        throw new Exception\InvalidIdentifier();
    }

    /**
     * @protected
     */
    protected function getArrayFromIdentifier($identifier) {
        $parts = explode(':', $identifier);
        $results = array();
        if(sizeof($parts) == 3) {
            $values = $this->connection->lrange($identifier, 0, -1);
            foreach($values as $value) {
                if(strpos($value, ':model:') !== false) {
                    $results[] = $this->getModelFromIdentifier($value);
                } else {
                    $results[] = $value;
                }
            }
        }
        return $results;
    }

    /**
     * @protected
     */
    protected function getNewID($modelName) {
        return $this->connection->hincrby('auto_increment', $modelName, '1');
    }

    /**
     * @private
     */
    private function fillModelProperties(stdClass $model) {

        foreach($this->models[$model->RepositoryNameSave] as $property => $value) {
            if(!isset($model->$property)) {
                $type = $this->models[$model->RepositoryNameSave][$property]['type'];
                switch($type) {
                    case 'integer':
                        $model->$property = 0;
                        break;
                    case 'string':
                        $model->$property = "";
                        break;
                    case 'array':
                        $model->$property = array();
                        break;
                    default:
                        $model->$property = null;
                }
            }
        }
        return $model;
    }

    /**
     * @private
     */
    private static function propertyIs($propertyDef, $propertyKey) {
        return isset($propertyDef[$propertyKey]) ? $propertyDef[$propertyKey] : false;
    }

    /**
     * @private
     */
    private static function getValueType($value) {
        if(is_numeric($value)) {
            return (int)$value;
        } else {
            return $value;
        }
    }

    /**
     * @private
     */
    private static function getScore($type, $value) {
        switch($type) {
            case 'integer':
                return $value;
            case 'string':
                $rank = "0123456789abcdefghijklmnopqrstuvwxyz";
                $pos = strpos($rank, strtolower(substr($value, 0, 1)));
                if($pos === false) {
                    $pos = -1;
                }
                return $pos;
            default:
                throw new Exception\InvalidScoreType();
        }
    }
}
