<?php

require 'vendor/autoload.php';

use Expreql\Expreql\Model;
use Expreql\Expreql\Database;

use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertIsInt;
use function PHPUnit\Framework\assertNotNull;

class Exercise extends Model
{
    public static $table = 'exercises';

    public static $primary_key = 'id';

    public static $fields = [
        'id',
        'title',
        'state',
    ];

    public static $has_many = [
        Question::class => 'exercises_id',
        Fulfillment::class => 'exercises_id',
    ];
}

class Question extends Model
{
    public static $table = 'questions';

    public static $primary_key = 'id';

    public static $fields = [
        'id',
        'label',
        'type',
        'exercises_id',
    ];

    public static $has_many = [
        Response::class => 'questions_id'
    ];

    public static $has_one = [
        Exercise::class => 'exercises_id'
    ];
}

class Fulfillment extends Model
{
    public static $table = 'fulfillments';

    public static $primary_key = 'id';

    public static $fields = [
        'id',
        'timestamp',
    ];

    public static $has_many = [
        Response::class => 'fulfillments_id'
    ];

    public static $has_one = [
        Exercise::class => 'exercises_id'
    ];
}

class Response extends Model
{
    public static $table = 'responses';

    public static $primary_key = 'id';

    public static $fields = [
        'id',
        'text',
        'questions_id',
        'fulfillments_id',
    ];

    public static $has_one = [
        Question::class => 'questions_id',
        Fulfillment::class => 'fulfillments_id',
    ];
}


class ModelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $config = parse_ini_file("config.ini");

        Database::set_config($config);
        $connection = Database::get_connection();

        $schema_query = file_get_contents("./data/tests_schema.sql");
        $connection->query($schema_query);

        $data_query = file_get_contents("./data/tests_data.sql");
        $connection->query($data_query);
    }

    public function testSimpleSelect()
    {
        $exercises = Exercise::select()->execute();

        assertNotNull($exercises);
    }

    public function testSimpleUpdate()
    {
        $affected_rows = Exercise::update([
            'title' => 'Updated title',
            'state' => 'closed'
        ])->where('id', 9)->execute();
    
        $exercise = Exercise::select()->where('id', 9)->execute();

        assertEquals(1, $affected_rows);
        assertEquals('Updated title', $exercise[0]->title);
        assertEquals('closed', $exercise[0]->state);
    }

    public function testGetFieldWithTableName()
    {
        assertEquals('exercises.title', Exercise::field('title'));
    }

    public function testJoinMany()
    {
        $exercise_with_question = Exercise::select()->join(Question::class)
            ->where('exercises.id', 2)->execute();

        assertNotNull($exercise_with_question);
    }

    public function testCountExercises()
    {
        $exercises = Exercise::select()->execute();

        assertIsInt($exercises->count());
    }

    public function testCountExerciseQuestions()
    {
        $exercise = Exercise::select()->where(Exercise::field('id'), 2)
            ->join(Question::class)->execute();

        assertIsInt($exercise[0]->questions->count());
        assertEquals(1, $exercise->count(), 'Only one top level model should exist');
    }

    public function testJoinWithNoJoinedRows()
    {
        $exercise = Exercise::select()->join(Question::class)
            ->where(Exercise::field('id'), 1)->execute();

        assertCount(0, $exercise[0]->questions);
    }

    public function testJoinMultipleModels()
    {
        $exercise = Exercise::select()->join([
            Fulfillment::class,
            Question::class,
        ])->where(Exercise::field('id'), 8)->execute();

        assertNotNull($exercise[0]->fulfillments);
        assertNotNull($exercise[0]->questions);
    }

    public function testNestedJoin()
    {
        $exercise = Exercise::select()->join([
            Question::class,
            Fulfillment::class  => [
                Response::class,
            ]
        ])->where(Exercise::field('id'), 8)->execute();

        assertNotNull($exercise[0]->fulfillments);
        assertNotNull($exercise[0]->questions);
        assertNotNull($exercise[0]->fulfillments[0]->responses);
    }

    public function testNestedJoinThreeLevelsDeep()
    {
        $exercise = Exercise::select()->join([
            Fulfillment::class => [
                Response::class => [
                    Question::class
                ]
            ]
        ])->where(Exercise::field('id'), 8)->execute();

        assertInstanceOf(Question::class, $exercise[0]->fulfillments[0]->responses[1]->questions[0]);
        assertEquals(1, $exercise[0]->fulfillments[0]->responses[1]->questions->count(), 'Response contains a single question object');
    }

    public function testSaveModel()
    {
        $exercise = new Exercise();
        $exercise->title = 'Exercise 22';
        $exercise->state = 'building';
        $exercise->save();

        assertEquals(22, $exercise->id);
    }

    public function testFindByPk()
    {
        $exercise = Exercise::find_by_pk(19);

        assertEquals(19, $exercise->id);
        assertEquals('Exercise 19', $exercise->title);
        assertEquals('closed', $exercise->state);
    }
}
