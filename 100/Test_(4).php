<?php

namespace Tests\Feature\Http\Controllers\IngredientController;

use App\Models\Ingredient;
use App\Models\RecipeIngredient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class Test1 extends TestCase
{
    use DatabaseTransactions, TestTrait;



    protected function setUp(): void
    {
        parent::setUp();
        $this->createAndBeUser();
    }

    /**
     * @feature file_4
     * @scenario ingredients zmieniony
     * @case Get Categories response
     * @suite: Get Ingredients 1

     * @test
     */
    public function index_json_structure()
    {
        $response = $this->json('get', route('ingredients.index'), [
            'search' => 'test'
        ])->assertOk();

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'name',
                ]
            ]
        ]);
    }

    /**
     * @feature file_4
     * @scenario ingredients zmieniony
     * @case Get empty list because of nothing found
     * @suite: Get Ingredients

     * @test
     * @dataProvider unknownSearchEntryData
     */
    public function index_get_empty_result($search)
    {
        $response = $this->json('get', route('ingredients.index'), compact('search'));

        $data = $response->decodeResponseJson('data');

        $this->assertEmpty($data);
    }

    /**
     * @feature file_4
     * @scenario ingredients zmieniony
     * @case Get empty list because of nothing found
     * @suite: Get Ingredients

     * @test
     * @dataProvider searchEntryData
     */
    public function index_get_found_ingredients($search, $searchable_name)
    {
        factory(Ingredient::class)->create([
            'name' => $searchable_name
        ]);
        $response = $this->json('get', route('ingredients.index'), compact('search'));

        $data = $response->decodeResponseJson('data');

        $this->assertFound($searchable_name, $data);
    }

    public function searchEntryData()
    {
        return [
            'match beginning' => [ 'search' => 'some', 'result' => 'some text'],
            'match ending' => [ 'search' => 'text', 'result' => 'some text'],
            'match middle' => [ 'search' => 'me ex', 'result' => 'some text'],
            'match lowercase' => [ 'search' => 'text', 'result' => 'some TExt'],
            'match Uppercase' => [ 'search' => 'TEXT', 'result' => 'some text'],
        ];
    }
}
