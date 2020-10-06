<?php

namespace Expreql\Expreql;

abstract class Model implements Queryable
{

	/**
	 * Register the table columns name
	 * 
	 * TODO(alexandre): Should we use `DESCRIBE` instead of asking the user to 
	 *
	 * @var array
	 */
	public static $fields = [];

	/**
	 * @var string
	 * 
	 * The name of the database table which the model represents
	 */
	public static $table;

	/**
	 * @var string
	 */
	public static $primary_key;

	public static $has_many = [];

	public static $has_one = [];

	public static $belongs_to = [];

	/**
	 * @param array $row A PDO result row
	 */
	public function __construct(array $row = [])
	{
		// Register the columns on the model instance
		foreach ($row as $column_key => $column_value) {
			if (in_array($column_key, static::$fields)) {
				$this->$column_key = $column_value;
			}
		}
	}

	/**
	 * This function can be used to avoid name collisions on tables columns.
	 * 
	 * @param string $field The field of the model colliding
	 * 
	 * @return string
	 */
	public static function field(string $field): string
	{
		return static::$table . "." . $field;
	}

	public static function insert(array $fields)
	{
		$connection = Database::get_connection();
		$query_builder = new QueryBuilder(QueryType::INSERT, $connection);
		$query_builder->table(static::$table);
		$query_builder->fields($fields);
		return $query_builder->execute();
	}

	public static function select(array $fields = null)
	{
		$connection = Database::get_connection();
		$query_builder = new QueryBuilder(QueryType::SELECT, $connection);
		$query_builder->model(static::class);
		$query_builder->table(static::$table);
		$query_builder->fields($fields);
		return $query_builder;
	}

	public static function find($value)
	{
		$connection = Database::get_connection();
		$query_builder = new QueryBuilder(QueryType::SELECT, $connection);
		$query_builder->model(static::class);
		$query_builder->table(static::$table);
		$query_builder->where(static::field(static::$primary_key), $value);
		return $query_builder;
	}

	public static function update(array $fields)
	{
		$connection = Database::get_connection();
		$query_builder = new QueryBuilder(QueryType::UPDATE, $connection);
		$query_builder->table(static::$table);
		$query_builder->fields($fields);
		return $query_builder;
	}

	public static function delete()
	{
		$connection = Database::get_connection();
		$query_builder = new QueryBuilder(QueryType::DELETE, $connection);
		$query_builder->table(static::$table);
		return $query_builder;
	}
}
