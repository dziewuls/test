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
     * @feature feature 68
     * @scenario ingredients
     * @case Get Categories response ddasdsa
     * @suite: Get Ingredients

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
    * @feature feature 419
    * @scenario ingredients
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
    * @feature feature 1104
    * @scenario ingredients
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
            'match Uppercase' => ['search' => 'TEXT', 'result' => 'some text'],
            'mTdupa' => ['search' => 'testsres', 'result' => 'sdfdsf'],
        ];
    }
}
