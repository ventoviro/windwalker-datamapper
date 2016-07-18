<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2016 LYRASOFT. All rights reserved.
 * @license    GNU General Public License version 2 or later.
 */

namespace Windwalker\DataMapper;

use Windwalker\Data\Data;
use Windwalker\Data\DataSet;
use Windwalker\Database\Driver\AbstractDatabaseDriver;
use Windwalker\Event\DispatcherInterface;
use Windwalker\Event\Event;

/**
 * The AbstractDataMapperProxy class.
 *
 * @see  DataMapper
 * @see  AbstractDataMapper
 *
 * @method  static  DataSet|Data[]  find($conditions = array(), $order = null, $start = null, $limit = null)
 * @method  static  DataSet|Data[]  findAll($order = null, $start = null, $limit = null)
 * @method  static  Data            findOne($conditions = array(), $order = null)
 * @method  static  array           findColumn($column, $conditions = array(), $order = null, $start = null, $limit = null)
 * @method  static  DataSet|Data[]  create($dataset)
 * @method  static  Data            createOne($data)
 * @method  static  DataSet|Data[]  update($dataset, $condFields = null, $updateNulls = false)
 * @method  static  Data            updateOne($data, $condFields = null, $updateNulls = false)
 * @method  static  boolean         updateBatch($data, $conditions = array())
 * @method  static  DataSet|Data[]  flush($dataset, $conditions = array())
 * @method  static  DataSet|Data[]  save($dataset, $condFields = null, $updateNulls = false)
 * @method  static  Data            saveOne($data, $condFields = null, $updateNulls = false)
 * @method  static  boolean         delete($conditions)
 * @method  static  boolean         useTransaction($yn = null)
 * @method  static  Event                triggerEvent($event, $args = array())
 * @method  static  DispatcherInterface  getDispatcher()
 * @method  static  AbstractDataMapper   setDispatcher(DispatcherInterface $dispatcher)
 * @method  static  AbstractDataMapper   addTable($alias, $table, $condition = null, $joinType = 'LEFT', $prefix = null)
 * @method  static  AbstractDataMapper   removeTable($alias)
 * @method  static  DataMapper  call($columns)
 * @method  static  DataMapper  group($columns)
 * @method  static  DataMapper  having($conditions, ...$args)
 * @method  static  DataMapper  innerJoin($table, $condition = array())
 * @method  static  DataMapper  join($type, $table, $conditions)
 * @method  static  DataMapper  leftJoin($table, $condition = array())
 * @method  static  DataMapper  order($columns)
 * @method  static  DataMapper  limit($limit = null, $offset = null)
 * @method  static  DataMapper  outerJoin($table, $condition = array())
 * @method  static  DataMapper  rightJoin($table, $condition = array())
 * @method  static  DataMapper  select($columns)
 * @method  static  DataMapper  where($conditions, ...$args)
 * @method  static  DataMapper  bind($key = null, $value = null, $dataType = \PDO::PARAM_STR, $length = 0, $driverOptions = array())
 *
 * @since  3.0
 */
class AbstractDatabaseMapperProxy
{
	/**
	 * Property table.
	 *
	 * @var  string
	 */
	protected static $table;

	/**
	 * Property alias.
	 *
	 * @var  string
	 */
	protected static $alias;

	/**
	 * Property keys.
	 *
	 * @var  array|string
	 */
	protected static $keys;

	/**
	 * Property dataClass.
	 *
	 * @var  string
	 */
	protected static $dataClass;

	/**
	 * Property dataSetClass.
	 *
	 * @var  string
	 */
	protected static $dataSetClass;

	/**
	 * Property instances.
	 *
	 * @var  DataMapper[]
	 */
	protected static $instances = array();

	/**
	 * is triggered when invoking inaccessible methods in an object context.
	 *
	 * @param $name      string
	 * @param $arguments array
	 *
	 * @return mixed
	 * @link http://php.net/manual/en/language.oop5.overloading.php#language.oop5.overloading.methods
	 */
	public function __call($name, $arguments)
	{
		$instance = static::getInstance();

		return call_user_func_array(array($instance, $name), $arguments);
	}

	/**
	 * is triggered when invoking inaccessible methods in a static context.
	 *
	 * @param $name      string
	 * @param $arguments array
	 *
	 * @return mixed
	 * @link http://php.net/manual/en/language.oop5.overloading.php#language.oop5.overloading.methods
	 */
	public static function __callStatic($name, $arguments)
	{
		$instance = static::getInstance();

		return call_user_func_array(array($instance, $name), $arguments);
	}

	/**
	 * initialise
	 *
	 * @param DatabaseMapperInterface $mapper
	 *
	 * @return  void
	 */
	protected static function init(DatabaseMapperInterface $mapper)
	{
	}

	/**
	 * getInstance
	 *
	 * @param   string  $table
	 *
	 * @return  DatabaseMapperInterface
	 */
	public static function getInstance($table = null)
	{
		$table = $table ? : static::$table;

		if (!isset(static::$instances[$table]))
		{
			static::$instances[$table] = static::createDataMapper($table);
		}

		return static::$instances[$table];
	}

	/**
	 * createDataMapper
	 *
	 * @param   string                 $table Table name.
	 * @param   string|array           $keys  Primary key, default will be `id`.
	 * @param   AbstractDatabaseDriver $db    Database adapter.
	 *
	 * @return DataMapper
	 */
	public static function createDataMapper($table = null, $keys = null, $db = null)
	{
		$table = $table ? : static::$table;
		$keys = $keys ? : static::$keys;

		$mapper = new DataMapper($table, $keys, $db);

		if (static::$alias)
		{
			$mapper->alias(static::$alias);
		}

		if (static::$dataClass)
		{
			$mapper->setDataClass(static::$dataClass);
		}

		if (static::$dataSetClass)
		{
			$mapper->setDatasetClass(static::$dataSetClass);
		}

		$mapper->getDispatcher()->addListener(new static);

		static::init($mapper);

		return $mapper;
	}

	/**
	 * setDataMapper
	 *
	 * @param string                  $table
	 * @param DatabaseMapperInterface $mapper
	 *
	 * @return  void
	 */
	public static function setDataMapper($table, DatabaseMapperInterface $mapper)
	{
		static::$instances[$table] = $mapper;
	}

	/**
	 * reset
	 *
	 * @return  void
	 */
	public static function reset()
	{
		static::$instances = array();
	}
}
