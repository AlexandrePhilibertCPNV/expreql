<?php

namespace Expreql\Expreql;

use Exception;
use PDO;
use ReflectionClass;

abstract class QueryType
{
    const SELECT = 0;
    const INSERT = 1;
    const UPDATE = 2;
    const DELETE = 3;
}

abstract class Op
{
    const or = 'OR';
    const and = 'AND';
}

class QueryBuilder
{

    /**
     * Holds the fully quallified class name
     * 
     * @var string
     */
    private $model;

    private $pdo;

    private $query_type;

    private $table;

    private $fields;

    private $values;

    private $where;

    private $order_by;

    private $limit;

    private $offset;

    /**
     * Contains models that are being joined on the base model
     * 
     * @var array
     */
    private $join;

    /**
     * @var PDOStatement The statement PDO has prepared
     */
    public $statement;

    public function __construct(int $query_type, $pdo)
    {
        $this->query_type = $query_type;
        $this->pdo = $pdo;

        return $this;
    }

    public function model(string $model)
    {
        $this->model = $model;

        return $this;
    }

    public function table(string $table)
    {
        $this->table = $table;

        return $this;
    }

    /**
     * @param array ...$args
     * 
     * @return QueryBuilder
     */
    public function where(...$args)
    {
        $length = count($args);

        switch ($length) {
            case 1:
                if (!is_array($args)) {
                    throw new Exception('Invalid where syntax, argument must be an array');
                }
                $this->where[] = [Op::and, $args[0]];
                break;
            case 2:
            case 3:
                // Single clause e.g. where('price', 20) or ('price', '<', 50)
                $this->where[] = [Op::and, [$args]];
                break;
            default:
                throw new Exception('Unsupported number of arguments');
                break;
        }

        return $this;
    }

    public function where_or(...$args): QueryBuilder
    {
        $length = count($args);

        switch ($length) {
            case 1:
                if (!is_array($args)) {
                    throw new Exception('Invalid where syntax, argument must be an array');
                }
                $this->where[] = [Op::or, $args[0]];
                break;
            case 2:
            case 3:
                // Single clause e.g. where('price', 20) or ('price', '<', 50)
                $this->where[] = [Op::or, [$args]];
                break;
            default:
                throw new Exception('Unsupported number of arguments');
                break;
        }

        return $this;
    }

    /**
     * TODO: This needs to only be usable on SELECT QueryTypes
     */
    public function order_by(string $field, string $keyword)
    {
        $formatted = strtoupper($keyword);
        switch ($formatted) {
            case 'ASC':
            case 'DESC':
                $this->order_by = [$field => $formatted];
                break;
            default:
                throw new Exception("order by keyword not supported");
        }

        return $this;
    }

    /**
     * @param int $number
     * 
     * @return QueryBuilder
     */
    public function limit(int $number): QueryBuilder
    {
        $this->limit = $number;

        return $this;
    }

    /**
     * @param int $number
     * 
     * @return QueryBuilder
     */
    public function offset(int $number): QueryBuilder
    {
        $this->offset = $number;

        return $this;
    }

    /**
     * @param $field The fields to group by
     * 
     * @return QueryBuilder
     */
    public function group_by($field)
    {
        $this->group_by = $field;

        return $this;
    }

    /**
     * @param array $fields The fields to select or update
     * 
     * @return QueryBuilder
     */
    public function fields(array $fields = null)
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * @param Model|array class The fully qualified class name
     * 
     * example: User::class
     * 
     * @return QueryBuilder
     */
    public function join($class)
    {
        if (is_array($class)) {
            $this->join = $class;
            return $this;
        }

        $this->join = [$class];
        return $this;
    }

    /**
     * Call this function to execute built queries
     * 
     * @return ArrayObject|int Records from the request that was built
     */
    public function execute()
    {
        $this->build();

        if (isset($this->values)) {
            $this->statement->execute($this->values);
        } else {
            $this->statement->execute();
        }

        switch ($this->query_type) {
            case QueryType::INSERT:
            case QueryType::UPDATE:
                $stmt = $this->pdo->prepare(
                    "SELECT * FROM `" . $this->table . "` WHERE id = ?"
                );

                $stmt->execute([$this->pdo->lastInsertID()]);
                return $stmt->fetchAll(PDO::FETCH_CLASS);
            case QueryType::DELETE:
                return  $this->statement->rowCount();
            case QueryType::SELECT:
                // Here we are mapping the result from fetch to a Model
                $fetch_result = $this->statement->fetchAll(PDO::FETCH_NAMED);
                $query_result = new QueryResult();

                // Create all base models
                foreach ($fetch_result as $row) {

                    // We can skip this row if we already have a base instance
                    foreach ($query_result as $model_instance) {
                        $primary_key = $this->model::$primary_key;
                        if (
                            is_array($row[$primary_key]) &&
                            in_array($model_instance->$primary_key, $row[$primary_key])
                        ) {
                            continue 2;
                        }
                        if ($model_instance->$primary_key == $row[$primary_key]) {
                            continue 2;
                        }
                    }

                    $flatten_row = array_map(function($column) {
                        if (is_array($column)) {
                            return $column[0];
                        }
                        return $column;
                    }, $row);

                    $model = new $this->model($flatten_row);
                    $query_result->append($model);
                }

                if (!isset($this->join)) {
                    // We can return what we got as there are no join to perform
                    return $query_result;
                }

                foreach ($this->join as $join_index => $join) {
                    // Get the foregin key to map the base model primary key to
                    // the join model foreign key
                    $foreign_key = $this->model::$has_many[$join];
                    $primary_key = $this->model::$primary_key;
                    $join_table_name = $join::$table;

                    // Iterate a second time, this time creating joined models
                    foreach ($fetch_result as $row) {
                        if (!isset($row[$foreign_key])) {
                            // We have not joined any rows, create empty QueryResult
                            // property on each object in order to not have null value
                            foreach ($query_result as $model_instance) {
                                if (is_array($row[$primary_key])) {
                                    $row_primary_key = $row[$primary_key][0];
                                } else {
                                    $row_primary_key = $row[$primary_key];
                                }
                                if ($model_instance->$primary_key == $row_primary_key) {
                                    $join_query_result = new QueryResult();
                                    $model_instance->$join_table_name = $join_query_result;
                                    break;
                                }
                            }
                            continue;
                        }
                        // Find the base model to which we will be adding joined object
                        foreach ($query_result as $model_instance) {
                            if (is_array($row[$foreign_key])) {
                                // TODO: Index could be greater than 1
                                if ($model_instance->$primary_key == $row[$foreign_key][1]) {
                                    $base_model = $model_instance;
                                    break;
                                }
                            }
                            if ($model_instance->$primary_key == $row[$foreign_key]) {
                                $base_model = $model_instance;
                                break;
                            }
                        }

                        // flatten the row to remove
                        $flatten_row = array_map(function($column) use ($join_index) {
                            if (is_array($column)) {
                                return $column[$join_index];
                            }
                            return $column;
                        }, $row);

                        $join_model = new $join($flatten_row);

                        // Joined models should also be a QueryResult in order to
                        // perform methods on them
                        if (isset($base_model->$join_table_name)) {
                            $base_model->$join_table_name->append($join_model);
                        } else {
                            $join_query_result = new QueryResult();
                            $join_query_result->append($join_model);
                            $base_model->$join_table_name = $join_query_result;
                        }
                    }
                }

                return $query_result;
        }
    }

    /**
     * Build the query into a PDOStatment that is ready for execution
     * 
     * @return QueryBuilder
     */
    public function build()
    {
        switch ($this->query_type) {
            case QueryType::SELECT:
                $this->handle_select_building();
                break;
            case QueryType::INSERT:
                $this->handle_insert_building();
                break;
            case QueryType::UPDATE:
                $this->handle_update_building();
                break;
            case QueryType::DELETE:
                $this->handle_delete_building();
                break;
        }

        return $this;
    }

    private function handle_select_building()
    {
        $table = $this->table;

        if (isset($this->fields)) {
            $query = "SELECT";

            foreach ($this->fields as $field) {
                // Handle the fields, they could be a function call
                if (is_array($field)) {
                    $query .= " " . $field[0] . "(" . $field[1] . ")";

                    if (isset($field[2])) {
                        $query .= " AS " . $field[2];
                    }

                    $query .= ',';
                } else {
                    $query .= " $field,";
                }
            }

            // Remove trailing comma
            $query = substr($query, 0, strlen($query) - 1);
        } else {
            $query = "SELECT *";
        }

        $query .= " FROM $table";

        if (isset($this->join)) {
            $query .= $this->handle_join_building();
        }

        if (isset($this->where)) {
            $query .= $this->handle_where_building();
        }

        if (isset($this->order_by)) {
            $key = key($this->order_by);
            $query .= " ORDER BY $key " . $this->order_by[$key];
        }

        if (isset($this->group_by)) {
            $query .= " GROUP BY " . $this->group_by;
        }

        if (isset($this->limit)) {
            $query .= " LIMIT " . $this->limit;
        }

        if (isset($this->offset)) {
            $query .= " OFFSET " . $this->offset;
        }

        $this->statement = $this->pdo->prepare($query);
    }

    /**
     * TODO(alexandre): Should we validate the operator used inside conditions ?
     * 
     * @return string
     */
    private function handle_where_building(): string
    {
        $nb_clauses = count($this->where);
        $where = ' WHERE ';

        // A segment is an array of conditions with the first index being the 
        // operator joining two clauses such as `Op::and` or `Op::or`
        foreach ($this->where as $segment) {
            $nb_conditions = count($segment[1]);
            if ($nb_conditions > 1 && $nb_clauses > 1) {
                $where .= "(";
            }

            foreach ($segment[1] as $condition) {
                $length = count($condition);
                if ($length == 2) {
                    // The condition has no operator in it, default to `=`
                    $where .= $condition[0] . " = ? " . $segment[0] . " ";
                    $this->values[] = $condition[1];
                } else if ($length == 3) {
                    // The condition has an operator, use the one specified
                    $where .= $condition[0] . " " . $condition[1] . " ? " . $segment[0] . " ";
                    $this->values[] = $condition[2];
                }
            }
            // Remove trailing operator
            $where = substr($where, 0, strlen($where) - strlen($segment[0]) - 2);

            if ($nb_conditions > 1 && $nb_clauses > 1) {
                $where .= ")";
            }

            $where .= " " . $segment[0] . " ";
        }
        // Remove trailing operator
        $where = substr($where, 0, strlen($where) - strlen($segment[0]) - 2);

        return $where;
    }

    private function handle_join_building()
    {
        $table = $this->table;
        $join_statement = "";

        foreach ($this->join as $join) {
            $join_class = new ReflectionClass($join);
            $join_table = $join_class->getStaticPropertyValue('table');
            $join_primary_key = $join_class->getStaticPropertyValue('primary_key');

            if (array_key_exists($join, $this->model::$has_many)) {
                $join_field = $this->model::$has_many[$join];
                $join_statement .= " LEFT JOIN $join_table ON $table.$join_primary_key = $join_table.$join_field";
            } else if (array_key_exists($join, $this->model::$has_one)) {
                $join_field = $this->model::$primary_key;
                $join_statement .= " INNER JOIN $join_table ON $table.$join_field = $join_table.$join_primary_key";
            } else if (array_key_exists($join, $this->model::$belongs_to)) {
                $join_field = $this->model::$belongs_to[$join];
                $join_statement .= " LEFT JOIN $join_table ON $table.$join_field = $join_table.$join_primary_key";
            }
        }

        return $join_statement;
    }

    private function handle_insert_building()
    {
        $table = $this->table;

        $prepare  = str_repeat('?,', count($this->fields) - 1) . '?';
        $keys = [];
        $values = [];

        foreach ($this->fields as $key => $value) {
            $keys[] = $key;
            $values[] = $value;
        }

        $this->values = $values;
        $this->statement = $this->pdo->prepare("INSERT INTO `$table` (`" . implode("`, `", $keys) . "`) VALUES ($prepare)");
    }

    private function handle_update_building()
    {
        $table = $this->table;
        $query = "UPDATE $table SET";

        foreach ($this->fields as $key => $value) {
            $this->values[] = $value;
            $query .=  " $key = ?,";
        }

        $query = substr($query, 0, strlen($query) - 1);

        if (isset($this->where)) {
            $query .= $this->handle_where_building();
        }

        $this->statement = $this->pdo->prepare($query);
    }

    private function handle_delete_building()
    {
        $table = $this->table;
        $query = "DELETE FROM $table";

        if (isset($this->where)) {
            $query .= $this->handle_where_building();
        }

        $this->statement = $this->pdo->prepare($query);
    }
}
