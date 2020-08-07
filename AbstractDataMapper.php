<?php declare(strict_types=1);
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2019 LYRASOFT.
 * @license    LGPL-2.0-or-later
 */

namespace Windwalker\DataMapper;

use Windwalker\Data\Data;
use Windwalker\Data\DataInterface;
use Windwalker\Data\DataSet;
use Windwalker\Data\DataSetInterface;
use Windwalker\Event\Dispatcher;
use Windwalker\Event\DispatcherInterface;
use Windwalker\Event\Event;
use Windwalker\Event\ListenerMapper;

/**
 * Abstract DataMapper.
 *
 * The class can implement by any database system.
 *
 * @since  2.0
 */
abstract class AbstractDataMapper implements DataMapperInterface, \IteratorAggregate
{
    public const UPDATE_NULLS = true;

    /**
     * Table name.
     *
     * @var    string
     * @since  2.0
     */
    protected $table = null;

    /**
     * Primary key.
     *
     * @var    array
     * @since  2.0
     */
    protected $keys = null;

    /**
     * Table fields.
     *
     * @var    array
     * @since  2.0
     */
    protected $fields = null;

    /**
     * Property selectFields.
     *
     * @var    array
     * @since  2.0
     */
    protected $selectFields = null;

    /**
     * Data object class.
     *
     * @var    string
     * @since  2.0
     */
    protected $dataClass = Data::class;

    /**
     * Data set object class.
     *
     * @var    string
     * @since  2.0
     */
    protected $datasetClass = DataSet::class;

    /**
     * Property useTransaction.
     *
     * @var    boolean
     * @since  2.0
     */
    protected $useTransaction = true;

    /**
     * Property dispatcher.
     *
     * @var  DispatcherInterface
     */
    protected $dispatcher;

    /**
     * Init this class.
     *
     * We don't dependency on database in abstract class, that means you can use other data provider.
     *
     * @param string $table Table name.
     * @param string $keys  The primary key, default will be `id`.
     *
     * @throws  \Exception
     * @since   2.0
     */
    public function __construct($table = null, $keys = null)
    {
        $this->table = $this->table ?: $table;

        $this->keys = $keys ?: $this->keys;
        $this->keys = $this->keys ?: 'id';
        $this->keys = (array) $this->keys;

        // Set some custom configuration.
        $this->init();
    }

    /**
     * This method can be override by sub class to prepare come custom setting.
     *
     * @return  void
     * @since   2.0
     */
    protected function init()
    {
        // Override this method to to something.
    }

    /**
     * Find records and return data set.
     *
     * Example:
     * - `$mapper->find(array('id' => 5), 'date', 20, 10);`
     * - `$mapper->find(null, 'id', 0, 1);`
     *
     * @param mixed   $conditions    Where conditions, you can use array or Compare object.
     *                               Example:
     *                               - `array('id' => 5)` => id = 5
     *                               - `new GteCompare('id', 20)` => 'id >= 20'
     *                               - `new Compare('id', '%Flower%', 'LIKE')` => 'id LIKE "%Flower%"'
     * @param mixed   $order         Order sort, can ba string, array or object.
     *                               Example:
     *                               - `id ASC` => ORDER BY id ASC
     *                               - `array('catid DESC', 'id')` => ORDER BY catid DESC, id
     * @param integer $start         Limit start number.
     * @param integer $limit         Limit rows.
     * @param string  $key           The index key.
     *
     * @return  mixed|DataSet Found rows data set.
     * @since   2.0
     */
    public function find($conditions = [], $order = null, $start = null, $limit = null, $key = null)
    {
        if ($conditions instanceof \Traversable) {
            $conditions = iterator_to_array($conditions);
        } elseif (is_object($conditions)) {
            $conditions = get_object_vars($conditions);
        }

        if (!is_array($conditions)) {
            // Load by primary key.
            $keyCount = count($this->getKeyName(true));

            if ($keyCount) {
                if ($keyCount > 1) {
                    throw new \InvalidArgumentException(
                        'Table has multiple primary keys specified, only one primary key value provided.'
                    );
                }

                $conditions = [$this->getKeyName() => $conditions];
            } else {
                throw new \RuntimeException('No primary keys defined.');
            }
        }

        $order = (array) $order;

        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'conditions' => &$conditions,
                'order' => &$order,
                'start' => &$start,
                'limit' => &$limit,
            ]
        );

        // Find data
        $result = $this->doFind($conditions, $order, $start, $limit, $key) ?: [];

        foreach ($result as $k => $data) {
            if (!($data instanceof $this->dataClass)) {
                $result[$k] = $this->bindData($data);
            }
        }

        $result = $this->bindDataset($result);

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$result,
            ]
        );

        return $result;
    }

    /**
     * Find records without where conditions and return data set.
     *
     * Same as `$mapper->find(null, 'id', $start, $limit);`
     *
     * @param mixed   $order Order sort, can ba string, array or object.
     *                       Example:
     *                       - 'id ASC' => ORDER BY id ASC
     *                       - array('catid DESC', 'id') => ORDER BY catid DESC, id
     * @param integer $start Limit start number.
     * @param integer $limit Limit rows.
     * @param string  $key   The index key.
     *
     * @return mixed|DataSet Found rows data set.
     */
    public function findAll($order = null, $start = null, $limit = null, $key = null)
    {
        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'order' => &$order,
                'start' => &$start,
                'limit' => &$limit,
            ]
        );

        $result = $this->find([], $order, $start, $limit, $key);

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$result,
            ]
        );

        return $result;
    }

    /**
     * findIterate
     *
     * @param mixed   $conditions    Where conditions, you can use array or Compare object.
     *                               Example:
     *                               - `array('id' => 5)` => id = 5
     *                               - `new GteCompare('id', 20)` => 'id >= 20'
     *                               - `new Compare('id', '%Flower%', 'LIKE')` => 'id LIKE "%Flower%"'
     * @param mixed   $order         Order sort, can ba string, array or object.
     *                               Example:
     *                               - `id ASC` => ORDER BY id ASC
     *                               - `array('catid DESC', 'id')` => ORDER BY catid DESC, id
     * @param integer $start         Limit start number.
     * @param integer $limit         Limit rows.
     * @param string  $key           The index key.
     *
     * @return  \Iterator
     *
     * @throws \Exception
     *
     * @since  3.5.19
     */
    public function findIterate($conditions = [], $order = null, $start = null, $limit = null, $key = null): \Iterator
    {
        if ($conditions instanceof \Traversable) {
            $conditions = iterator_to_array($conditions);
        } elseif (is_object($conditions)) {
            $conditions = get_object_vars($conditions);
        }

        if (!is_array($conditions)) {
            // Load by primary key.
            $keyCount = count($this->getKeyName(true));

            if ($keyCount) {
                if ($keyCount > 1) {
                    throw new \InvalidArgumentException(
                        'Table has multiple primary keys specified, only one primary key value provided.'
                    );
                }

                $conditions = [$this->getKeyName() => $conditions];
            } else {
                throw new \RuntimeException('No primary keys defined.');
            }
        }

        $order = (array) $order;

        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'conditions' => &$conditions,
                'order' => &$order,
                'start' => &$start,
                'limit' => &$limit,
            ]
        );

        // Find data
        $iterator = $this->doFindIterate($conditions, $order, $start, $limit);

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$iterator,
            ]
        );

        foreach ($iterator as $k => $data) {
            $i = $key ? $data->$key : $k;

            if (!($data instanceof $this->dataClass)) {
                yield $i => $this->bindData($data);
            }
        }
    }

    /**
     * Find one record and return a data.
     *
     * Same as `$mapper->find($conditions, 'id', 0, 1);`
     *
     * @param mixed $conditions Where conditions, you can use array or Compare object.
     *                          Example:
     *                          - `array('id' => 5)` => id = 5
     *                          - `new GteCompare('id', 20)` => 'id >= 20'
     *                          - `new Compare('id', '%Flower%', 'LIKE')` => 'id LIKE "%Flower%"'
     * @param mixed $order      Order sort, can ba string, array or object.
     *                          Example:
     *                          - `id ASC` => ORDER BY id ASC
     *                          - `array('catid DESC', 'id')` => ORDER BY catid DESC, id
     *
     * @return mixed|Data Found row data.
     */
    public function findOne($conditions = [], $order = null)
    {
        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'conditions' => &$conditions,
                'order' => &$order,
            ]
        );

        $dataset = $this->find($conditions, $order, 0, 1);

        $result = $dataset[0];

        if (!$result) {
            $class  = $this->dataClass;
            $result = new $class();
        }

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$result,
            ]
        );

        return $result;
    }

    /**
     * Find column as an array.
     *
     * @param string  $column     The column we want to select.
     * @param mixed   $conditions Where conditions, you can use array or Compare object.
     *                            Example:
     *                            - `array('id' => 5)` => id = 5
     *                            - `new GteCompare('id', 20)` => 'id >= 20'
     *                            - `new Compare('id', '%Flower%', 'LIKE')` => 'id LIKE "%Flower%"'
     * @param mixed   $order      Order sort, can ba string, array or object.
     *                            Example:
     *                            - `id ASC` => ORDER BY id ASC
     *                            - `array('catid DESC', 'id')` => ORDER BY catid DESC, id
     * @param integer $start      Limit start number.
     * @param integer $limit      Limit rows.
     * @param string  $key        The index key.
     *
     * @return  mixed
     *
     * @throws \InvalidArgumentException
     */
    public function findColumn($column, $conditions = [], $order = null, $start = null, $limit = null, $key = null)
    {
        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'column' => &$column,
                'conditions' => &$conditions,
                'order' => &$order,
                'start' => &$start,
                'limit' => &$limit,
            ]
        );

        if (!is_string($column)) {
            throw new \InvalidArgumentException('Column name should be string.');
        }

        $dataset = $this->find($conditions, $order, $start, $limit, $key);

        $result = [];

        foreach ($dataset as $k => $data) {
            if ($key === null) {
                $result[] = $data->$column;
            } else {
                $result[$k] = $data->$column;
            }
        }

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$result,
            ]
        );

        return $result;
    }

    /**
     * Find column as an array.
     *
     * @param mixed $conditions   Where conditions, you can use array or Compare object.
     *                            Example:
     *                            - `array('id' => 5)` => id = 5
     *                            - `new GteCompare('id', 20)` => 'id >= 20'
     *                            - `new Compare('id', '%Flower%', 'LIKE')` => 'id LIKE "%Flower%"'
     * @param mixed $order        Order sort, can ba string, array or object.
     *                            Example:
     *                            - `id ASC` => ORDER BY id ASC
     *                            - `array('catid DESC', 'id')` => ORDER BY catid DESC, id
     *
     * @return  mixed
     *
     * @throws \InvalidArgumentException
     */
    public function findResult($conditions = [], $order = null)
    {
        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'conditions' => &$conditions,
                'order' => &$order
            ]
        );

        $classBak = $this->getDataClass();
        $this->setDataClass(Data::class);
        $data = $this->findOne($conditions, $order);

        $result = null;

        if ($data->notNull()) {
            // Get first
            if (is_object($data) && !is_iterable($data)) {
                $data = get_object_vars($data);
            }

            foreach ($data as $datum) {
                $result = $datum;
                break;
            }
        }

        $this->setDataClass($classBak);

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$result,
            ]
        );

        return $result;
    }

    /**
     * copy
     *
     * @param mixed                 $conditions
     * @param array|object|callable $newValue
     * @param bool                  $removeKey
     *
     * @return  mixed|DataSet
     *
     * @since  3.5.7
     */
    public function copy($conditions = [], $newValue = null, bool $removeKey = false)
    {
        $items = $this->find($conditions);

        foreach ($items as $i => $item) {
            $items[$i] = $this->copyOne($item, $newValue, $removeKey);
        }

        return $items;
    }

    /**
     * copyOne
     *
     * @param mixed                 $conditions
     * @param array|object|callable $newValue
     * @param bool                  $removeKey
     *
     * @return  mixed|Data
     *
     * @since  3.5.7
     */
    public function copyOne($conditions = [], $newValue = null, bool $removeKey = false)
    {
        $item = $this->findOne($conditions);

        if ($removeKey) {
            foreach ($this->getKeyName(true) as $key) {
                $item->$key = null;
            }
        }

        if (is_callable($newValue)) {
            $result = $newValue($item, $conditions);

            if ($result) {
                $item = $result;
            }
        } else {
            $newValue = new Data($newValue);

            foreach ($newValue as $key => $value) {
                if ($value !== null) {
                    $item->$key = $value;
                }
            }
        }

        return $this->createOne($item);
    }

    /**
     * Create records by data set.
     *
     * @param mixed $dataset The data set contains data we want to store.
     *
     * @return  mixed|DataSet  Data set data with inserted id.
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    public function create($dataset)
    {
        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'dataset' => &$dataset,
            ]
        );

        if (!($dataset instanceof \Traversable) && !is_array($dataset)) {
            throw new \InvalidArgumentException('DataSet object should be instance of a Traversable');
        }

        $result = $this->doCreate($dataset);

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$result,
            ]
        );

        return $result;
    }

    /**
     * Create one record by data object.
     *
     * @param mixed $data Send a data in and store.
     *
     * @return  mixed|Data Data with inserted id.
     * @throws \InvalidArgumentException
     */
    public function createOne($data)
    {
        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'data' => &$data,
            ]
        );

        $dataset = $this->create($this->bindDataset([$data]));

        $result = $dataset[0];

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$result,
            ]
        );

        return $result;
    }

    /**
     * Update records by data set. Every data depend on this table's primary key to update itself.
     *
     * @param mixed $dataset      Data set contain data we want to update.
     * @param array $condFields   The where condition tell us record exists or not, if not set,
     *                            will use primary key instead.
     * @param bool  $updateNulls  Update empty fields or not.
     *
     * @return mixed|DataSet
     */
    public function update($dataset, $condFields = null, $updateNulls = false)
    {
        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'dataset' => &$dataset,
                'condFields' => &$condFields,
                'updateNulls' => &$updateNulls,
            ]
        );

        if (!($dataset instanceof \Traversable) && !is_array($dataset)) {
            throw new \InvalidArgumentException('DataSet object should be instance of a Traversable');
        }

        // Handling conditions
        $condFields = $condFields ?: $this->getKeyName(true);

        $result = $this->doUpdate($dataset, (array) $condFields, $updateNulls);

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$result,
            ]
        );

        return $result;
    }

    /**
     * Same as update(), just update one row.
     *
     * @param mixed $data         The data we want to update.
     * @param array $condFields   The where condition tell us record exists or not, if not set,
     *                            will use primary key instead.
     * @param bool  $updateNulls  Update empty fields or not.
     *
     * @return mixed|Data
     */
    public function updateOne($data, $condFields = null, $updateNulls = false)
    {
        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'data' => &$data,
                'condFields' => &$condFields,
                'updateNulls' => &$updateNulls,
            ]
        );

        $dataset = $this->update($this->bindDataset([$data]), $condFields, $updateNulls);

        $result = $dataset[0];

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$result,
            ]
        );

        return $result;
    }

    /**
     * Using one data to update multiple rows, filter by where conditions.
     * Example:
     * `$mapper->updateAll(new Data(array('published' => 0)), array('date' => '2014-03-02'))`
     * Means we make every records which date is 2014-03-02 unpublished.
     *
     * @param mixed $data       The data we want to update to every rows.
     * @param mixed $conditions Where conditions, you can use array or Compare object.
     *                          Example:
     *                          - `array('id' => 5)` => id = 5
     *                          - `new GteCompare('id', 20)` => 'id >= 20'
     *                          - `new Compare('id', '%Flower%', 'LIKE')` => 'id LIKE "%Flower%"'
     *
     * @return  boolean
     * @throws \InvalidArgumentException
     */
    public function updateBatch($data, $conditions = [])
    {
        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'data' => &$data,
                'conditions' => &$conditions,
            ]
        );

        $result = $this->doUpdateBatch($data, $conditions);

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$result,
            ]
        );

        return $result;
    }

    /**
     * Flush records, will delete all by conditions then recreate new.
     *
     * @param mixed $dataset    Data set contain data we want to update.
     * @param mixed $conditions Where conditions, you can use array or Compare object.
     *                          Example:
     *                          - `array('id' => 5)` => id = 5
     *                          - `new GteCompare('id', 20)` => 'id >= 20'
     *                          - `new Compare('id', '%Flower%', 'LIKE')` => 'id LIKE "%Flower%"'
     *
     * @return  mixed|DataSet Updated data set.
     */
    public function flush($dataset, $conditions = [])
    {
        // Handling conditions
        if (!is_array($conditions) && !is_object($conditions)) {
            $cond = [];

            foreach ((array) $this->getKeyName(true) as $field) {
                $cond[$field] = $conditions;
            }

            $conditions = $cond;
        }

        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'dataset' => &$dataset,
                'conditions' => &$conditions,
            ]
        );

        $result = $this->doFlush($dataset, (array) $conditions);

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$result,
            ]
        );

        return $result;
    }

    public function sync($dataset, $conditions = [], ?array $compareKeys = null)
    {
        // Handling conditions
        if (!is_array($conditions) && !is_object($conditions)) {
            $cond = [];

            foreach ((array) $this->getKeyName(true) as $field) {
                $cond[$field] = $conditions;
            }

            $conditions = $cond;
        }

        $conditions = (array) $conditions;

        $compareKeys = $compareKeys ?? array_keys($conditions);

        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'dataset' => &$dataset,
                'conditions' => &$conditions,
            ]
        );

        $result = $this->doSync($dataset, (array) $conditions, $compareKeys);

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$result,
            ]
        );

        return $result;
    }

    /**
     * Save will auto detect is conditions matched in data or not.
     * If matched, using update, otherwise we will create it as new record.
     *
     * @param mixed $dataset      The data set contains data we want to save.
     * @param array $condFields   The where condition tell us record exists or not, if not set,
     *                            will use primary key instead.
     * @param bool  $updateNulls  Update empty fields or not.
     *
     * @return  mixed|DataSet Saved data set.
     */
    public function save($dataset, $condFields = null, $updateNulls = false)
    {
        // Handling conditions
        $condFields = $condFields ?: $this->getKeyName(true);

        $condFields = (array) $condFields;

        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'dataset' => &$dataset,
                'condFields' => &$condFields,
                'updateNulls' => &$updateNulls,
            ]
        );

        $datasetClass = $this->datasetClass;

        $createDataset = new $datasetClass();
        $updateDataset = new $datasetClass();

        $fields = $this->getFields();

        foreach ($dataset as $k => $data) {
            if (!($data instanceof $this->dataClass)) {
                $data = $this->bindData($data);
            }

            $update = true;

            // If AI field is empty, use insert.
            foreach ($condFields as $field) {
                $extra = $fields[$field]->Extra ?? '';

                if ($extra === 'auto_increment' && !$data->$field) {
                    $update = false;

                    break;
                }
            }

            // Do save
            if ($update) {
                $updateDataset[] = $data;
            } else {
                $createDataset[] = $data;
            }

            $dataset[$k] = $data;
        }

        $this->create($createDataset);

        $this->update($updateDataset, $condFields, $updateNulls);

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$dataset,
            ]
        );

        return $dataset;
    }

    /**
     * Save only one row.
     *
     * @param mixed $data         The data we want to save.
     * @param array $condFields   The where condition tell us record exists or not, if not set,
     *                            will use primary key instead.
     * @param bool  $updateNulls  Update empty fields or not.
     *
     * @return  mixed|Data Saved data.
     */
    public function saveOne($data, $condFields = null, $updateNulls = false)
    {
        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'data' => &$data,
                'condFields' => &$condFields,
                'updateNulls' => &$updateNulls,
            ]
        );

        $dataset = $this->save($this->bindDataset([$data]), $condFields, $updateNulls);

        $result = $dataset[0];

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$result,
            ]
        );

        return $result;
    }

    /**
     * findOneOrCreate
     *
     * @param array|mixed           $conditions
     * @param array|object|callable $initData
     * @param bool                  $mergeConditions
     *
     * @return  mixed|Data
     *
     * @since  3.5.7
     */
    public function findOneOrCreate($conditions, $initData = null, bool $mergeConditions = true)
    {
        $item = $this->findOne($conditions);

        if ($item->notNull()) {
            return $item;
        }

        if ($mergeConditions && is_array($conditions)) {
            foreach ($conditions as $k => $v) {
                if (!is_numeric($k)) {
                    $item->$k = $v;
                }
            }
        }

        if (is_callable($initData)) {
            $result = $initData($item, $conditions);

            if ($result) {
                $item = $result;
            }
        } else {
            $initData = new Data($initData);

            foreach ($initData as $key => $value) {
                if ($value !== null) {
                    $item->$key = $value;
                }
            }
        }

        return $this->createOne($item);
    }

    /**
     * updateOneOrCreate
     *
     * @param mixed      $data
     * @param mixed      $initData
     * @param array|null $condFields
     * @param bool       $updateNulls
     *
     * @return  mixed|Data
     *
     * @since  3.5.7
     */
    public function updateOneOrCreate($data, $initData = null, ?array $condFields = null, bool $updateNulls = false)
    {
        $condFields = $condFields ?: $this->getKeyName(true);

        $conditions = [];

        foreach ($condFields as $field) {
            if (is_array($data)) {
                $conditions[$field] = $data[$field];
            } else {
                $conditions[$field] = $data->$field;
            }
        }

        $item = $this->findOne($conditions);

        if ($item->notNull()) {
            return $this->updateOne($data, $condFields, $updateNulls);
        }

        $data = new Data($data);

        foreach ($data as $k => $v) {
            $item->$k = $v;
        }

        if (is_callable($initData)) {
            $result = $initData($item, $conditions);

            if ($result) {
                $item = $result;
            }
        } else {
            $initData = new Data($initData);

            foreach ($initData as $key => $value) {
                if ($value !== null) {
                    $item->$key = $value;
                }
            }
        }

        return $this->createOne($item);
    }

    /**
     * Delete records by where conditions.
     *
     * @param mixed $conditions   Where conditions, you can use array or Compare object.
     *                            Example:
     *                            - `array('id' => 5)` => id = 5
     *                            - `new GteCompare('id', 20)` => 'id >= 20'
     *                            - `new Compare('id', '%Flower%', 'LIKE')` => 'id LIKE "%Flower%"'
     *
     * @return  boolean Will be always true.
     */
    public function delete($conditions)
    {
        if ($conditions instanceof \Traversable) {
            $conditions = iterator_to_array($conditions);
        } elseif (is_object($conditions)) {
            $conditions = get_object_vars($conditions);
        }

        if (!is_array($conditions)) {
            // Load by primary key.
            $keyCount = count($this->getKeyName(true));

            if ($keyCount) {
                if ($keyCount > 1) {
                    throw new \InvalidArgumentException(
                        'Table has multiple primary keys specified, only one primary key value provided.'
                    );
                }

                $conditions = [$this->getKeyName() => $conditions];
            } else {
                throw new \RuntimeException('No primary keys defined.');
            }
        }

        // Event
        $this->triggerEvent(
            'onBefore' . ucfirst(__FUNCTION__),
            [
                'conditions' => &$conditions,
            ]
        );

        $result = $this->doDelete($conditions);

        // Event
        $this->triggerEvent(
            'onAfter' . ucfirst(__FUNCTION__),
            [
                'result' => &$result,
            ]
        );

        return $result;
    }

    /**
     * Do find action, this method should be override by sub class.
     *
     * @param array   $conditions Where conditions, you can use array or Compare object.
     * @param array   $orders     Order sort, can ba string, array or object.
     * @param integer $start      Limit start number.
     * @param integer $limit      Limit rows.
     *
     * @param         $key
     *
     * @return  mixed Found rows data set.
     */
    abstract protected function doFind(array $conditions, array $orders, $start, $limit, $key);

    /**
     * doFindIterate
     *
     * @param array   $conditions Where conditions, you can use array or Compare object.
     * @param array   $orders     Order sort, can ba string, array or object.
     * @param integer $start      Limit start number.
     * @param integer $limit      Limit rows.
     *
     * @return  \Iterator
     *
     * @since  3.5.19
     */
    abstract protected function doFindIterate(array $conditions, $order, ?int $start, ?int $limit): \Iterator;

    /**
     * Do create action, this method should be override by sub class.
     *
     * @param mixed $dataset The data set contains data we want to store.
     *
     * @return  mixed  Data set data with inserted id.
     */
    abstract protected function doCreate($dataset);

    /**
     * Do update action, this method should be override by sub class.
     *
     * @param mixed $dataset      Data set contain data we want to update.
     * @param array $condFields   The where condition tell us record exists or not, if not set,
     *                            will use primary key instead.
     * @param bool  $updateNulls  Update empty fields or not.
     *
     *
     * @return  mixed Updated data set.
     */
    abstract protected function doUpdate($dataset, array $condFields, $updateNulls = false);

    /**
     * Do updateAll action, this method should be override by sub class.
     *
     * @param mixed $data       The data we want to update to every rows.
     * @param mixed $conditions Where conditions, you can use array or Compare object.
     *
     * @return  boolean
     */
    abstract protected function doUpdateBatch($data, array $conditions);

    /**
     * Do flush action, this method should be override by sub class.
     *
     * @param mixed $dataset    Data set contain data we want to update.
     * @param mixed $conditions Where conditions, you can use array or Compare object.
     *
     * @return  mixed Updated data set.
     */
    abstract protected function doFlush($dataset, array $conditions);

    /**
     * doSync
     *
     * @param mixed      $dataset     Data set contain data we want to update.
     * @param array      $conditions  Where conditions, you can use array or Compare object.
     * @param array|null $compareKeys Thr compare keys to check update, keep or delete.
     *
     * @return  array
     *
     * @since  3.5.21
     */
    abstract protected function doSync($dataset, array $conditions, ?array $compareKeys = null): array;

    /**
     * Do delete action, this method should be override by sub class.
     *
     * @param mixed $conditions Where conditions, you can use array or Compare object.
     *
     * @return  boolean Will be always true.
     */
    abstract protected function doDelete(array $conditions);

    /**
     * Get table fields.
     *
     * @param string $table Table name.
     *
     * @return  array
     */
    abstract public function getFields($table = null);

    /**
     * Get table name.
     *
     * @return  string Table name.
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * Set table name.
     *
     * @param string $table Table name.
     *
     * @return  AbstractDataMapper  Return self to support chaining.
     */
    public function setTable($table)
    {
        $this->table = $table;

        return $this;
    }

    /**
     * Bind a record into data.
     *
     * @param mixed $data The data we want to bind.
     *
     * @return  object
     *
     * @throws \UnexpectedValueException
     */
    protected function bindData($data)
    {
        $dataClass = $this->dataClass;
        $object    = new $dataClass();

        if ($object instanceof DataInterface) {
            return $object->bind($data);
        }

        foreach ((array) $data as $field => $value) {
            $object->$field = $value;
        }

        return $object;
    }

    /**
     * Bind records into data set.
     *
     * @param mixed $dataset Data set we want to bind.
     *
     * @return  object Data set object.
     *
     * @throws \UnexpectedValueException
     * @throws \InvalidArgumentException
     */
    protected function bindDataset($dataset)
    {
        $datasetClass = $this->datasetClass;
        $object       = new $datasetClass();

        if ($object instanceof DataSetInterface) {
            return $object->bind($dataset);
        }

        if ($dataset instanceof \Traversable) {
            $dataset = iterator_to_array($dataset);
        } elseif (is_object($dataset)) {
            $dataset = [$dataset];
        } elseif (!is_array($dataset)) {
            throw new \InvalidArgumentException(sprintf('Need an array or object in %s::%s()', __CLASS__, __METHOD__));
        }

        foreach ($dataset as $data) {
            $object[] = $data;
        }

        return $object;
    }

    /**
     * Get data class.
     *
     * @return  string Dat class.
     */
    public function getDataClass()
    {
        return $this->dataClass;
    }

    /**
     * Set data class.
     *
     * @param string $dataClass Data class.
     *
     * @return  AbstractDataMapper  Return self to support chaining.
     */
    public function setDataClass($dataClass)
    {
        $this->dataClass = $dataClass;

        return $this;
    }

    /**
     * Get data set class.
     *
     * @return  string Data set class.
     */
    public function getDatasetClass()
    {
        return $this->datasetClass;
    }

    /**
     * Set Data set class.
     *
     * @param string $datasetClass Dat set class.
     *
     * @return  AbstractDataMapper  Return self to support chaining.
     */
    public function setDatasetClass($datasetClass)
    {
        $this->datasetClass = $datasetClass;

        return $this;
    }

    /**
     * To use transaction or not.
     *
     * @param boolean $yn Yes or no, keep default that we get this value.
     *
     * @return  boolean
     */
    public function useTransaction($yn = null)
    {
        if ($yn !== null) {
            $this->useTransaction = (bool) $yn;
        }

        return $this->useTransaction;
    }

    /**
     * triggerEvent
     *
     * @param string|Event $event
     * @param array        $args
     *
     * @return  Event
     */
    public function triggerEvent($event, $args = [])
    {
        $dispatcher = $this->getDispatcher();

        if (!$dispatcher instanceof DispatcherInterface) {
            return null;
        }

        $args['mapper'] = $this;

        $event = $this->dispatcher->triggerEvent($event, $args);

        $innerListener = [$this, $event->getName()];

        if (!$event->isStopped() && method_exists($this, $event->getName())) {
            call_user_func($innerListener, $event);
        }

        return $event;
    }

    /**
     * Method to get property Dispatcher
     *
     * @return  DispatcherInterface
     */
    public function getDispatcher()
    {
        if (!$this->dispatcher && class_exists('Windwalker\Event\Dispatcher')) {
            $this->dispatcher = new Dispatcher();

            if (is_subclass_of($this, 'Windwalker\Evebt\DispatcherAwareInterface')) {
                ListenerMapper::add($this);
            }
        }

        return $this->dispatcher;
    }

    /**
     * Method to set property dispatcher
     *
     * @param DispatcherInterface $dispatcher
     *
     * @return  static  Return self to support chaining.
     */
    public function setDispatcher(DispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;

        return $this;
    }

    /**
     * Method to get the primary key field name for the table.
     *
     * @param boolean $multiple   True to return all primary keys (as an array) or false to return just the first one
     *                            (as a string).
     *
     * @return  array|mixed  Array of primary key field names or string containing the first primary key field.
     *
     * @since   3.0
     */
    public function getKeyName($multiple = false)
    {
        // Count the number of keys
        if (count($this->keys)) {
            if ($multiple) {
                // If we want multiple keys, return the raw array.
                return $this->keys;
            } else {
                // If we want the standard method, just return the first key.
                return $this->keys[0];
            }
        }

        return '';
    }

    /**
     * Validate that the primary key has been set.
     *
     * @return  boolean  True if the primary key(s) have been set.
     *
     * @since   3.0
     */
    public function hasPrimaryKey()
    {
        $empty = true;

        foreach ($this->keys as $key) {
            $empty = $empty && !$this->$key;
        }

        return !$empty;
    }

    /**
     * getIterator
     *
     * @return  \ArrayIterator|\Traversable
     *
     * @since  3.5.19
     */
    public function getIterator()
    {
        return $this->findIterate();
    }
}
