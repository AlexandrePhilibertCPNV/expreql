<?php

namespace Expreql\Expreql;

use Expreql\Expreql\Query;
use PDO;
use PDOStatement;

class InsertQuery extends Query
{
    /**
     * The fields being selected 
     * 
     * @var array
     */
    public array $fields;

    public function build(): PDOStatement
    {
        $table = $this->get_base_table_name();

        $prepare  = str_repeat('?,', count($this->fields) - 1) . '?';
        $keys = [];
        $values = [];

        foreach ($this->fields as $key => $value) {
            $keys[] = $key;
            $values[] = $value;
        }

        $this->values = $values;

        return $this->connection->prepare(
            "INSERT INTO `$table` (`" . implode("`, `", $keys) . "`) VALUES ($prepare)"
        );
    }

    public function execute(): int
    {
        $statement = $this->build();

        if (isset($this->values)) {
            $statement->execute($this->values);
        } else {
            $statement->execute();
        }

        $stmt = $this->pdo->prepare(
            "SELECT * FROM `" . $this->table . "` WHERE id = ?"
        );

        $stmt->execute([$this->pdo->lastInsertID()]);
        return $stmt->fetchAll(PDO::FETCH_CLASS);
    }
}
