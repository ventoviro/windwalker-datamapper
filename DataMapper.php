<?php
/**
 * Part of Windwalker project. 
 *
 * @copyright  Copyright (C) 2014 - 2015 LYRASOFT. All rights reserved.
 * @license    GNU Lesser General Public License version 3 or later.
 */

namespace Windwalker\DataMapper;

use Windwalker\Database\Driver\AbstractDatabaseDriver;
use Windwalker\Database\Query\QueryHelper;
use Windwalker\DataMapper\Entity\Entity;
use Windwalker\Query\Query;
use Windwalker\Query\QueryInterface;

/**
 * Main Database Mapper class.
 *
 * @method  $this  leftJoin($alias, $table, $condition = null, $prefix = null)
 * @method  $this  rightJoin($alias, $table, $condition = null, $prefix = null)
 * @method  $this  innerJoin($alias, $table, $condition = null, $prefix = null)
 * @method  $this  outerJoin($alias, $table, $condition = null, $prefix = null)
 *
 * @see  QueryHelper
 *
 * @method  $this  addTable($alias, $table, $condition = null, $joinType = 'LEFT', $prefix = null)
 * @method  $this  removeTable($alias)
 *
 * @see  QueryInterface
 * @see  Query
 *
 * @method  $this  call($columns)
 * @method  $this  group($columns)
 * @method  $this  having($conditions, ...$args)
 * @method  $this  orHaving($conditions)
 * @method  $this  order($columns)
 * @method  $this  limit($limit = null, $offset = null)
 * @method  $this  select($columns)
 * @method  $this  where($conditions, ...$args)
 * @method  $this  orWhere($conditions)
 * @method  $this  clear($clause = null)
 * @method  $this  bind($key = null, $value = null, $dataType = \PDO::PARAM_STR, $length = 0, $driverOptions = array())
 */
class DataMapper extends AbstractDataMapper implements DatabaseMapperInterface
{
	/**
	 * The DB adapter.
	 *
	 * @var AbstractDatabaseDriver
	 */
	protected $db = null;

	/**
	 * Property alias.
	 *
	 * @var  string
	 */
	protected $alias;

	/**
	 * Property query.
	 *
	 * @var  QueryInterface
	 */
	protected $query;

	/**
	 * Property queryHelper.
	 *
	 * @var  QueryHelper
	 */
	protected $queryHelper;

	/**
	 * newRelation
	 *
	 * @param string                 $alias
	 * @param string                 $table
	 * @param string                 $keys
	 * @param AbstractDatabaseDriver $db
	 *
	 * @return  static
	 */
	public static function newRelation($alias = null, $table = null, $keys = null, $db = null)
	{
		$instance =  new static($table, $keys, $db);

		return $instance->alias($alias);
	}

	/**
	 * Constructor.
	 *
	 * @param   string                 $table Table name.
	 * @param   string|array           $keys  Primary key, default will be `id`.
	 * @param   AbstractDatabaseDriver $db    Database adapter.
	 */
	public function __construct($table = null, $keys = null, AbstractDatabaseDriver $db = null)
	{
		$this->db = $db ? : DatabaseContainer::getDb();

		parent::__construct($table, $keys);
	}

	/**
	 * Method to set property alias
	 *
	 * @param   string $alias
	 *
	 * @return  static  Return self to support chaining.
	 */
	public function alias($alias)
	{
		$this->alias = $alias ? : $this->alias;

		return $this;
	}

	/**
	 * Do find action.
	 *
	 * @param   array    $conditions  Where conditions, you can use array or Compare object.
	 * @param   array    $orders      Order sort, can ba string, array or object.
	 * @param   integer  $start       Limit start number.
	 * @param   integer  $limit       Limit rows.
	 *
	 * @return  mixed  Found rows data set.
	 */
	protected function doFind(array $conditions, array $orders, $start, $limit)
	{
		$query = $this->getFindQuery($conditions, $orders, $start, $limit);

		$result = $this->db->setQuery($query)->loadAll();

		// Reset query
		$this->query = null;

		return $result;
	}

	/**
	 * getFindQuery
	 *
	 * @param   array   $conditions Where conditions, you can use array or Compare object.
	 * @param   array   $orders     Order sort, can ba string, array or object.
	 * @param   integer $start      Limit start number.
	 * @param   integer $limit      Limit rows.
	 *
	 * @return Query
	 * @throws \Exception
	 */
	protected function getFindQuery(array $conditions, array $orders, $start, $limit)
	{
		$query = $this->getQuery();

		// Check is join or not
		$queryHelper = $this->getQueryHelper();

		$join = count($queryHelper->getTables()) > 1;

		// Build conditions
		if ($join)
		{
			$alias = $this->alias ? : $this->table;

			// Add dot to conditions
			$conds = array();

			foreach ($conditions as $key => $value)
			{
				if (!is_numeric($key))
				{
					$key = strpos($key, '.') !== false ? $key : $alias . '.' . $key;
				}

				$conds[$key] = $value;
			}

			$conditions = $conds;

			// Add dot to orders
			$orders = array_map(function ($value) use ($alias)
			{
			    return strpos($value, '.') !== false ? $value : $alias . '.' . $value;
			}, $orders);
		}

		// Conditions.
		QueryHelper::buildWheres($query, $conditions);

		// Loop ordering
		foreach ($orders as $order)
		{
			$query->order($order);
		}

		// Add tables to query
		if ($join)
		{
			$query = $queryHelper->registerQueryTables($query);
		}
		else
		{
			if (!$this->table)
			{
				throw new \Exception('Please give me a table name~!');
			}

			$query->from($this->table);
		}

		// Build query
		if ($start || $limit)
		{
			$query->limit($limit, $start);
		}

		if (!$query->select)
		{
			if ($join)
			{
				$query->select($queryHelper->getSelectFields());
			}
			else
			{
				$query->select('*');
			}
		}

		return $query;
	}

	/**
	 * Do create action.
	 *
	 * @param  mixed $dataset The data set contains data we want to store.
	 *
	 * @return mixed
	 *
	 * @throws \Exception
	 * @throws \Throwable
	 */
	protected function doCreate($dataset)
	{
		!$this->useTransaction ? : $this->db->getTransaction(true)->start();

		try
		{
			foreach ($dataset as $k => $data)
			{
				if (!($data instanceof $this->dataClass))
				{
					$data = $this->bindData($data);
				}

				$entity = new Entity($this->getFields($this->table), $data);

				$entity = $this->prepareDefaultValue($entity);

				$pk = $this->getKeyName();

				$this->db->getWriter()->insertOne($this->table, $entity, $pk);

				$data->$pk = $entity->$pk;

				$dataset[$k] = $data;
			}
		}
		catch (\Exception $e)
		{
			!$this->useTransaction ? : $this->db->getTransaction(true)->rollback();

			throw $e;
		}
		catch (\Throwable $e)
		{
			!$this->useTransaction ? : $this->db->getTransaction(true)->rollback();

			throw $e;
		}

		!$this->useTransaction ? : $this->db->getTransaction(true)->commit();

		return $dataset;
	}

	/**
	 * Do update action.
	 *
	 * @param   mixed $dataset      Data set contain data we want to update.
	 * @param   array $condFields   The where condition tell us record exists or not, if not set,
	 *                              will use primary key instead.
	 * @param   bool  $updateNulls  Update empty fields or not.
	 *
	 * @return  mixed
	 *
	 * @throws \Exception
	 * @throws \Throwable
	 */
	protected function doUpdate($dataset, array $condFields, $updateNulls = false)
	{
		!$this->useTransaction ? : $this->db->getTransaction(true)->start();

		try
		{
			foreach ($dataset as $k => $data)
			{
				if (!($data instanceof $this->dataClass))
				{
					$data = $this->bindData($data);
				}

				$entity = new Entity($this->getFields($this->table), $data);

				if ($updateNulls)
				{
					$entity = $this->prepareDefaultValue($entity);
				}

				$this->db->getWriter()->updateOne($this->table, $entity, $condFields, $updateNulls);

				$dataset[$k] = $data;
			}
		}
		catch (\Exception $e)
		{
			!$this->useTransaction ? : $this->db->getTransaction(true)->rollback();

			throw $e;
		}
		catch (\Throwable $e)
		{
			!$this->useTransaction ? : $this->db->getTransaction(true)->rollback();

			throw $e;
		}

		!$this->useTransaction ? : $this->db->getTransaction(true)->commit();

		return $dataset;
	}

	/**
	 * Do updateAll action.
	 *
	 * @param   mixed $data       The data we want to update to every rows.
	 * @param   mixed $conditions Where conditions, you can use array or Compare object.
	 *
	 * @return  boolean
	 *
	 * @throws \Exception
	 * @throws \Throwable
	 */
	protected function doUpdateBatch($data, array $conditions)
	{
		!$this->useTransaction ? : $this->db->getTransaction(true)->start();

		try
		{
			$result = $this->db->getWriter()->updateBatch($this->table, $data, $conditions);
		}
		catch (\Exception $e)
		{
			!$this->useTransaction ? : $this->db->getTransaction(true)->rollback();

			throw $e;
		}
		catch (\Throwable $e)
		{
			!$this->useTransaction ? : $this->db->getTransaction(true)->rollback();

			throw $e;
		}

		!$this->useTransaction ? : $this->db->getTransaction(true)->commit();

		return $result;
	}

	/**
	 * Do flush action, this method should be override by sub class.
	 *
	 * @param   mixed $dataset    Data set contain data we want to update.
	 * @param   mixed $conditions Where conditions, you can use array or Compare object.
	 *
	 * @return  mixed
	 *
	 * @throws \Exception
	 * @throws \Throwable
	 */
	protected function doFlush($dataset, array $conditions)
	{
		!$this->useTransaction ? : $this->db->getTransaction(true)->start();

		try
		{
			if (!$this->delete($conditions))
			{
				throw new \RuntimeException(sprintf('Delete row fail when updating relations table: %s', $this->table));
			}

			if (!$this->create($dataset))
			{
				throw new \RuntimeException(sprintf('Insert row fail when updating relations table: %s', $this->table));
			}
		}
		catch (\Exception $e)
		{
			!$this->useTransaction ? : $this->db->getTransaction(true)->rollback();

			throw $e;
		}
		catch (\Throwable $e)
		{
			!$this->useTransaction ? : $this->db->getTransaction(true)->rollback();

			throw $e;
		}

		!$this->useTransaction ? : $this->db->getTransaction(true)->commit();

		return $dataset;
	}

	/**
	 * Do delete action, this method should be override by sub class.
	 *
	 * @param   mixed $conditions Where conditions, you can use array or Compare object.
	 *
	 * @return  boolean
	 *
	 * @throws \Exception
	 * @throws \Throwable
	 */
	protected function doDelete(array $conditions)
	{
		!$this->useTransaction ? : $this->db->getTransaction(true)->start();

		try
		{
			$result = $this->db->getWriter()->delete($this->table, $conditions);
		}
		catch (\Exception $e)
		{
			!$this->useTransaction ? : $this->db->getTransaction(true)->rollback();

			throw $e;
		}
		catch (\Throwable $e)
		{
			!$this->useTransaction ? : $this->db->getTransaction(true)->rollback();

			throw $e;
		}

		!$this->useTransaction ? : $this->db->getTransaction(true)->commit();

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
	 *
	 * @return  mixed
	 *
	 * @throws \InvalidArgumentException
	 */
	public function findColumn($column, $conditions = array(), $order = null, $start = null, $limit = null)
	{
		$this->select($column);

		return parent::findColumn($column, $conditions, $order, $start, $limit);
	}

	/**
	 * Get DB adapter.
	 *
	 * @return  AbstractDatabaseDriver Db adapter.
	 */
	public function getDb()
	{
		return $this->db;
	}

	/**
	 * Set db adapter.
	 *
	 * @param   AbstractDatabaseDriver $db Db adapter.
	 *
	 * @return  DataMapper  Return self to support chaining.
	 */
	public function setDb(AbstractDatabaseDriver $db)
	{
		$this->db = $db;

		return $this;
	}

	/**
	 * Get table fields.
	 *
	 * @param string $table Table name.
	 *
	 * @return  array
	 */
	public function getFields($table = null)
	{
		if ($this->fields !== null)
		{
			return $this->fields;
		}

		$table = $table ? : $this->table;

		$fields = $this->db->getTable($table)->getColumnDetails();

		foreach ($fields as $field)
		{
			if (strtolower($field->Null) == 'no' && $field->Default === null
				&& $field->Key != 'PRI' && $this->getKeyName() != $field->Field)
			{
				$type = $field->Type;

				list($type,) = explode('(', $type, 2);
				$type = strtolower($type);

				$field->Default = $this->db->getTable($table)->getDataType()->getDefaultValue($type);
			}

			$field = (object) $field;
			$this->fields[$field->Field] = $field;
		}

		return $this->fields;
	}

	/**
	 * If creating item or updating with null values, we must check all NOT NULL
	 * fields are using valid default value instead NULL value. This helps us get rid of
	 * this Mysql warning in STRICT_TRANS_TABLE mode.
	 *
	 * @param Entity $entity
	 *
	 * @return  Entity
	 */
	protected function prepareDefaultValue(Entity $entity)
	{
		foreach ($entity->getFields() as $field => $detail)
		{
			// This field is null and the db column is not nullable, use db default value.
			if ($entity[$field] === null && strtolower($detail->Null) == 'no')
			{
				$entity[$field] = $detail->Default;
			}
		}

		return $entity;
	}

	/**
	 * Method to get property Query
	 *
	 * @param bool $new
	 *
	 * @return QueryInterface
	 */
	public function getQuery($new = false)
	{
		if (!$this->query || $new)
		{
			$this->query = $this->db->getQuery(true);
		}

		return $this->query;
	}

	/**
	 * Method to set property query
	 *
	 * @param   QueryInterface $query
	 *
	 * @return  static  Return self to support chaining.
	 */
	public function setQuery(QueryInterface $query)
	{
		$this->query = $query;

		return $this;
	}

	/**
	 * Method to get property QueryHelper
	 *
	 * @return  QueryHelper
	 */
	public function getQueryHelper()
	{
		if (!$this->queryHelper)
		{
			$this->queryHelper = new QueryHelper($this->db);

			if ($this->table)
			{
				$this->queryHelper->addTable($this->alias ? : $this->table, $this->table);
			}
		}

		return $this->queryHelper;
	}

	/**
	 * Method to set property queryHelper
	 *
	 * @param   QueryHelper $queryHelper
	 *
	 * @return  static  Return self to support chaining.
	 */
	public function setQueryHelper(QueryHelper $queryHelper)
	{
		$this->queryHelper = $queryHelper;

		return $this;
	}

	/**
	 * reset
	 *
	 * @return  static
	 */
	public function reset()
	{
		$this->query = null;
		$this->queryHelper = null;

		return $this;
	}

	/**
	 * join
	 *
	 * @param string  $joinType
	 * @param string  $alias
	 * @param string  $table
	 * @param mixed   $condition
	 * @param boolean $prefix
	 *
	 * @return  static
	 */
	public function join($joinType = 'LEFT', $alias, $table, $condition = null, $prefix = null)
	{
		$this->getQueryHelper()->addTable($alias, $table, $condition, $joinType, $prefix);

		return $this;
	}

	/**
	 * __call
	 *
	 * @param   string  $name
	 * @param   array   $args
	 *
	 * @return  mixed
	 */
	public function __call($name, $args)
	{
		$allowMethods = array(
			'call',
			'group',
			'having',
			'order',
			'limit',
			'select',
			'where',
			'bind',
			'clear'
		);

		if (in_array($name, $allowMethods))
		{
			$query = $this->getQuery();

			call_user_func_array(array($query, $name), $args);

			return $this;
		}

		$allowMethods = array(
			'addTable',
			'removeTable'
		);

		if (in_array($name, $allowMethods))
		{
			$query = $this->getQueryHelper();

			call_user_func_array(array($query, $name), $args);

			return $this;
		}

		$allowMethods = array(
			'leftJoin',
			'rightJoin',
			'innerJoin',
			'outerJoin'
		);

		if (in_array($name, $allowMethods))
		{
			$name = str_replace('JOIN', '', strtoupper($name));

			array_unshift($args, $name);

			return call_user_func_array(array($this, 'join'), $args);
		}

		throw new \BadMethodCallException(sprintf('Method %s not exists in %s', $name, get_called_class()));
	}
}
