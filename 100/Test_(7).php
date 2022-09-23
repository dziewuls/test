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
    * @feature feature 31244
    * @scenario ingredients
    * @case Get Categories response
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
    * @feature feature 44
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
     * @feature feature 399999
     * @scenario ingredientsnb jjjjjj
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
        ];
    }
}
