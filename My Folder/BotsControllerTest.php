<?php
 
namespace Tests\Entry\Feature\App\Http\Controllers\BotsController;
 
use App\Contracts\IBotCloseTrading;
use App\Factories\DomainServiceFactory;
use App\Http\Middleware\CacheBalanceControl;
use App\Models\Account;
use App\Models\BotEvent;
use App\Models\Market;
use App\Models\MarketSource;
use App\Models\Role;
use Bots\BotCloseTrading;
use Bots\Entities\Enums\InstrumentType;
use Bots\Entities\Enums\RunnerStatus;
use Bots\Entities\Enums\RunnerType;
use Bots\Entities\Enums\StrategySignal;
use Bots\Entities\Models\Bot;
use Bots\Entities\Models\BotAccountAssociation;
use Carbon\Carbon;
 
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Markets\Api\Binance\Binance;
use Markets\Api\Bitmex\Bitmex;
use Markets\Contracts\IApi;
use Seeders\Markets;
use Silber\Bouncer\BouncerFacade;
use Tests\TestCase;
 
class BotsControllerTest extends TestCase
{
    use  TestTrait;
 
    public const EVENT_JSON_STRUCTURE = [
        'runnerId',
        'signal',
        'strength',
    ];
 
    public const BOT_EVENT_HISTORY_JSON_STRUCTURE = [
        'data' => [
            '*' => [
                'id',
                'runner_id',
                'signal',
                'strength',
                'positions',
                'bot_type',
                'bot_id',
                'created_at',
            ],
        ],
    ];
 
    public const BOTS_INDEX_RESPONSE_STRUCTURE = [
        'data' => [
            '*' => self::SINGLE_BOT_JSON_WITH_DETAILS + [
                'exchange' => self::EXCHANGE_JSON_STRUCTURE,
            ],
        ],
    ];
 
    public const ACCOUNT_ASSOCIATION_JSON_STRUCTURE = [
        'data' => [
            'id',
            'bot_id',
            'account_id',
            'market_id',
            'enabled',
            'selected',
            'multiplier',
            'leverage',
            'account_active',
            'account_name',
            'bot_account_balance',
            'bot_account_positions',
        ],
    ];
 
    public const EXCHANGE_JSON_STRUCTURE = [
        'market_source',
        'symbol',
        'status',
        'base_asset',
        'quote_asset',
        'base_asset_precision',
        'quote_asset_precision',
        'order_types',
        'iceberg_allowed',
        'min_price',
        'max_price',
        'tick_size',
        'min_quantity',
        'max_quantity',
        'step_size',
        'is_inverse',
        'maker_fee',
        'taker_fee',
        'max_leverage',
        'multiplier',
        'volume',
    ];
 
    public const SINGLE_BOT_JSON = [
        'id',
        'bot_id',
        'title',
        'description',
        'type',
        'owner',
        'status',
        'positions',
        'instrument_id',
        'instrument_symbol',
        'instrument_broker',
        'instrument_currency',
        'instrument_type',
        'instrument_rate',
        'instrument_multiplier',
        'associated_accounts' => [
            '*' => [
                'bot_id',
                'account_id',
                'enabled',
                'account_active',
                'account_name',
                'bot_account_balance',
                'bot_account_positions',
            ],
        ],
        'interval' => [
            'id',
            'symbol',
            'desc',
            'milis',
        ],
        'last_event_date',
        'bot_expired',
    ];
 
    public const SINGLE_BOT_JSON_WITH_DETAILS = [
        'id',
        'external_bot_id',
        'title',
        'description',
        'type',
        'owner',
        'status',
        'positions',
        'instrument_id',
        'instrument_symbol',
        'instrument_broker',
        'instrument_currency',
        'instrument_type',
        'instrument_rate',
        'instrument_multiplier',
        'created_at',
        'accounts' => [
            '*' => [
                'account_id',
                'market_source',
                'trading_type',
                'enabled',
                'selected',
                'multiplier',
                'account_active',
                'account_name',
                'base_asset',
                'quote_asset',
                'positions' => [
                    [
                        'symbol',
                        'size',
                        'leverage',
                        'side',
                    ]
                ],
                'bot_account_leverage',
                'bot_account_balance',
                'bot_account_positions',
                'account_balance',
                'account_balance_change',
            ],
        ],
        'interval' => [
            'id',
            'symbol',
            'desc',
            'milis',
        ],
        'last_event_date',
        'bot_expired',
    ];
 
    protected $user;
 
    protected function setUp():void
    {
        parent::setUp();
        $this->seed(Markets::class);
        Bot::query()->forceDelete();
        $this->mockMarket();
 
        Queue::fake();
        Event::fake();
    }
 
    /**
     * @feature Bots Exeria
     * @scenario Auth
     * @case
     *
     * @covers \App\Http\Controllers\BotTradingController::handle
     * @test
     * @param string $signal
     * @param int $strength
     */
    public function receive_hook_event_and_save_minimal(
        $signal = StrategySignal::BUY,
        $strength = 1
    ) {
        $client = $this->createFakeOAuthClient();
        $token = $this->retrievingTokens($client);
 
        $market = Market::byMarketSource(MarketSource::BITMEX)->firstOrFail();
        $bot = $this->createRunningBot(MarketSource::BITMEX);
        $request_data = [
            'runnerId' => $bot->bot_id,
            'signal' => $signal,
            'strength' => $strength,
            'stamp' => 1111,
        ];
 
        $response = $this->postJson(route('bot-trading'), $request_data, [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ]);
        $json = $response->json();
        $response->assertSuccessful();
 
        $this->assertDatabaseHas('bot_events', [
            'runner_id' => $bot->bot_id,
            'bot_id' => $bot->id,
            'signal' => $signal,
            'strength' => $strength,
        ]);
    }

    /**
     * @feature Bots Exeria
     * @scenario Auth
     * @case remove
     * 
     * @suite zestaw 1
     * @suite zestaw 2
     * 
     * @case
     * @covers \App\Http\Controllers\BotTradingController::handle
     * @param string $signal
     * @param int $strength
     * 
     * @test
     */
    public function receive_hook_event_and_save_full(
        $signal = StrategySignal::DO_NOTHING,
        $strength = 1
    ) {
        $client = $this->createFakeOAuthClient();
        $token = $this->retrievingTokens($client);
 
        $market = Market::byMarketSource(MarketSource::BITMEX)->firstOrFail();
        $bot = $this->createRunningBot(MarketSource::BITMEX);
        $request_data = $this->prepareRequestData($bot, $signal, $strength);
 
        $response = $this->postJson(route('bot-trading'), $request_data, [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ]);
        $json = $response->json();
        $response->assertSuccessful();
 
        $this->assertDatabaseHas('bot_events', [
            'runner_id' => $bot->bot_id,
            'bot_id' => $bot->id,
            'signal' => $signal,
            'strength' => $strength,
        ]);
 
        /** @var BotEvent $event */
        $event = BotEvent::all()->first();
 
        self::assertEquals($request_data['runnerId'], $event->runner_id);
        self::assertEquals((object) $request_data['candle'], $event->candle);
        self::assertEquals((object) $request_data['instrument'], $event->instrument);
 
        $request_data['candle'] = (object) $request_data['candle'];
        $request_data['instrument'] = (object) $request_data['instrument'];
        self::assertEquals((object) $request_data, $event->raw_data);
    }

    /**
     * @feature Bots Exeria
     * @scenario Auth
     * @case
     *
     * @covers \App\Http\Controllers\BotTradingController::handle
     * @test
     * @param string $signal
     * @param int $strength
     */
    public function receive_hook_event_and_update_outdated_bot_to_running(
        $signal = StrategySignal::DO_NOTHING,
        $strength = 1
    ) {
        $client = $this->createFakeOAuthClient();
        $token = $this->retrievingTokens($client);
 
        $market = Market::byMarketSource(MarketSource::BITMEX)->firstOrFail();
        $bot = $this->createRunningBot(MarketSource::BITMEX);
        $bot->forceFill(['status' => RunnerStatus::OUTDATED])->save();
        self::assertEquals(RunnerStatus::OUTDATED, $bot->refresh()->status);
 
        $request_data = $this->prepareRequestData($bot, $signal, $strength);
 
        $response = $this->postJson(route('bot-trading'), $request_data, [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ]);
        $json = $response->json();
        $response->assertSuccessful();
 
        $this->assertDatabaseHas('bot_events', [
            'runner_id' => $bot->bot_id,
            'bot_id' => $bot->id,
            'signal' => $signal,
            'strength' => $strength,
        ]);
 
        /** @var BotEvent $event */
        $event = BotEvent::all()->first();
 
        self::assertEquals($request_data['runnerId'], $event->runner_id);
        self::assertEquals(RunnerStatus::RUNNING, $bot->refresh()->status);
    }
 
    /**
     * @feature Bots Exeria
     * @scenario Auth
     * @case
     *
     * @covers \App\Http\Controllers\BotTradingController::handle
     * @test
     * @param string $signal
     * @param int $strength
     */
    public function receive_hook_event_unauthorized(
        $signal = StrategySignal::BUY,
        $strength = 1
    ) {
        $client = $this->createFakeOAuthClient();
        $token = $this->retrievingTokens($client, 'invalid_secret');
 
        /** @var Bot $bot */
        $bot = $this->createRunningBot(MarketSource::BITMEX);
        $request_data = [
            'runnerId' => $bot->bot_id,
            'signal' => $signal,
            'strength' => $strength,
        ];
 
        $response = $this->postJson(route('bot-trading'), $request_data, [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ]);
        $json = $response->json();
        $response->assertStatus(401);
 
        $this->assertDatabaseMissing('bot_events', [
            'runner_id' => $bot->bot_id,
            'signal' => $signal,
            'strength' => $strength,
        ]);
    }
 
    /**
     * @feature Bots
     * @scenario Account Association
     * @case success
     *
     *
     * @covers \App\Http\Controllers\BotsController::associateAccount
     * @test
     * @param string $market_source
     */
    public function associate_account_with_bot_and_activate($market_source = 'bitmex')
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $market = $this->mockMarketSource($market_source);
 
        $bot = $this->createRunningBot($market_source);
        $account = $this->createAccount($market);
 
        $request_data = [
            'bot_id' => $bot->id,
            'account_id' => $account->id,
            'enable' => true,
            'market_source' => $market_source
        ];
 
        $response = $this->postJson(route('bots-associate'), $request_data);
        $json = $response->json();
        $response->assertSuccessful();
        $response->assertJsonStructure(self::ACCOUNT_ASSOCIATION_JSON_STRUCTURE);
        $response->assertJsonFragment([
           'enabled' => true,
           'selected' => true,
        ]);
 
        $accounts = $bot->accountAssociations()->get();
        /** @var BotAccountAssociation $account_associated */
        $account_associated = $accounts->first();
        self::assertEquals(1, $accounts->count());
        self::assertEquals($account->id, $account_associated->account_id);
        self::assertEquals($market->id, $account_associated->market_id);
        self::assertEquals($bot->id, $account_associated->bot_id);
        self::assertEquals($bot->id, $account_associated->bot_id);
        self::assertEquals($now->toDateTimeString(), $account_associated->enabled_at->toDateTimeString());
        self::assertEquals(null, $account_associated->selected_at);
    }
 
    /**
     * @feature Bots
     * @scenario Account Association
     * @case success and activate
     *
     *
     * @covers \App\Http\Controllers\BotsController::associateAccount
     * @test
     * @param string $market_source
     */
    public function associate_account_with_bot_and_activate_automatically($market_source = 'bitmex')
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $market = $this->mockMarketSource($market_source);
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_broker' => $market_source, 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::DEFAULT]);
        $account1 = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id]);
        $account2 = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id]);
        $association = factory(BotAccountAssociation::class)->create(['account_id' => $account1->id, 'bot_id' => $bot->id, 'enabled_at' => Carbon::now()]);
        self::assertTrue($bot->refresh()->isOn());
 
        $request_data = [
            'bot_id' => $bot->id,
            'account_id' => $account2->id,
            'market_source' => $market_source
        ];
 
        $response = $this->postJson(route('bots-associate'), $request_data);
        $json = $response->json();
        $response->assertSuccessful();
        $response->assertJsonStructure(self::ACCOUNT_ASSOCIATION_JSON_STRUCTURE);
 
        $accounts = $bot->userAccountAssociations()->get();
        /** @var BotAccountAssociation $account_associated */
        $account_associated = $accounts->where('account_id', '=', $account2->id)->first();
        self::assertNotEmpty($account_associated);
 
        self::assertEquals($account2->id, $account_associated->account_id);
        self::assertEquals(2, $accounts->count());
        self::assertEquals($account2->market_id, $account_associated->market_id);
        self::assertEquals($bot->id, $account_associated->bot_id);
        self::assertEquals($bot->id, $account_associated->bot_id);
        self::assertEquals($now->toDateTimeString(), $account_associated->enabled_at->toDateTimeString());
        self::assertTrue($bot->refresh()->isOn());
    }
 
    /**
     * @feature Bots
     * @scenario Account Association to REVERSE bot
     * @case positions match
     *
     *
     * @covers \App\Http\Controllers\BotsController::associateAccount
     * @test
     * @param string $market_source
     */
    public function associate_accounts_positions_match($market_source = 'bitmex')
    {
        $this->createUserAndBe();
        $market = $this->mockMarketSource($market_source);
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_broker' => $market_source, 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::REVERSE, 'positions' => 5]);
        $position = $this->mockPosition(5, $bot->instrument_symbol);
        $this->mockPositionRepository(collect([$position]));
 
        $account = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id]);
 
        $request_data = [
            'bot_id' => $bot->id,
            'account_id' => $account->id,
            'enable' => true,
            'market_source' => $market_source
        ];
 
        $response = $this->postJson(route('bots-associate'), $request_data);
        $json = $response->json();
        $response->assertSuccessful();
    }
 
//    /**
//     * @feature Bots
//     * @scenario Account Association to REVERSE bot
//     * @case failed positions not match
//     *
//     *
//     * @covers \App\Http\Controllers\BotsController::associateAccount
//     * @test
//     * @param string $market_source
//     */
//    public function associate_account_error_positions_not_match($market_source = 'bitmex')
//    {
//        $this->markTestSkipped('Position validation was disable - not needed');
//
//        $this->createUserAndBe();
//        $position = $this->mockPosition(3);
//        $this->mockPositionRepository(collect([$position]));
//        $market = $this->mockMarketSource($market_source);
//
//        /** @var Bot $bot */
//        $bot = factory(Bot::class)->create(['instrument_broker' => $market_source, 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::REVERSE, 'positions' => 1]);
//        $account = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id]);
//
//        $request_data = [
//            'bot_id' => $bot->id,
//            'account_id' => $account->id,
//            'enable' => true,
//            'market_source' => $market_source
//        ];
//
//        $response = $this->postJson(route('bots-associate'), $request_data);
//        $json = $response->json();
//        $response->assertStatus(409);
//        $response->assertJsonStructure([
//            'errors' => [
//                'message',
//                'account_positions',
//                'bot_positions',
//                'account_id',
//            ],
//        ]);
//    }
 
    /**
     * @feature Bots
     * @scenario Account Association to REVERSE bot
     * @case positions not match - account associate force
     *
     *
     * @covers \App\Http\Controllers\BotsController::associateAccount
     * @test
     * @param string $market_source
     */
    public function associate_account_when_positions_not_match_force_flag($market_source = 'bitmex')
    {
        $this->createUserAndBe();
        $position = $this->mockPosition(3);
        $this->mockPositionRepository(collect([$position]));
        $market = $this->mockMarketSource($market_source);
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_broker' => $market_source, 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::REVERSE, 'positions' => 1]);
        $account = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id]);
 
        $request_data = [
            'bot_id' => $bot->id,
            'account_id' => $account->id,
            'enable' => true,
            'force' => true,
            'market_source' => $market_source
        ];
 
        $response = $this->postJson(route('bots-associate'), $request_data);
        $json = $response->json();
        $response->assertSuccessful();
 
        $accounts = $bot->accountAssociations()->get();
        /** @var BotAccountAssociation $account_associated */
        $account_associated = $accounts->first();
        self::assertEquals(1, $accounts->count());
        self::assertEquals($account->id, $account_associated->account_id);
    }
 
    /**
     * @feature Bots
     * @scenario Account Association
     * @case success
     *
     *
     * @covers \App\Http\Controllers\BotsController::associateAccount
     * @test
     * @param string $market_source
     */
    public function not_account_with_bot_market_not_match($market_source = 'bitmex')
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $market = $this->mockMarketSource($market_source);
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_broker' => 'invalid_market', 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING]);
        $account = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id]);
 
        $request_data = ['bot_id' => $bot->id, 'account_id' => $account->id, 'enable' => true, 'market_source' => $market_source];
 
        $response = $this->postJson(route('bots-associate'), $request_data);
        $json = $response->json();
        $response->assertStatus(424);
        $response->assertJsonValidationErrors(['market_source']);
 
        self::assertEquals(0, $bot->accountAssociations()->count());
    }
 
    /**
     * @feature Bots
     * @scenario Account Association
     * @case success
     *
     *
     * @covers \App\Http\Controllers\BotsController::associateAccount
     * @test
     * @param string $market_source
     */
    public function error_cannot_associate_other_user_account($market_source = 'bitmex')
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $market = $this->mockMarketSource($market_source);
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_broker' => 'bitmex', 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING]);
        $account = factory(Account::class)->create(['market_id' => $market->id]);
 
        $request_data = ['bot_id' => $bot->id, 'account_id' => $account->id, 'enable' => true, 'market_source' => $market_source];
 
        $response = $this->postJson(route('bots-associate'), $request_data);
        $json = $response->json();
        $response->assertStatus(424);
        $response->assertJsonValidationErrors(['account']);
 
        self::assertEquals(0, $bot->accountAssociations()->count());
    }
 
    public function mark_associated_account_as_selected_DATA_PROVIDER()
    {
        return [
            [true, null, 1],
            [true, null, 1],
            [false, null, 1],
            [false, null, 1],
            [null, 2.1, 2.1],
            [null, 0.2, 0.2],
            [null, null, 1],
        ];
    }
 
    /**
     * @feature Bots
     * @scenario Account Association
     * @case success
     *
     * @dataProvider mark_associated_account_as_selected_DATA_PROVIDER
     * @covers       \App\Http\Controllers\BotsController::associateAccount
     * @test
     * @param $enable
     * @param int|null $multiplayer
     * @param int $multiplayer_expected
     * @param string $market_source
     */
    public function mark_associated_account_as_selected($enable, ?float $multiplayer, ?float $multiplayer_expected, $market_source = 'bitmex')
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $market = $this->mockMarketSource($market_source);
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_broker' => $market_source, 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::DEFAULT]);
        $account = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id]);
 
        $request_data = [
            'bot_id' => $bot->id,
            'account_id' => $account->id,
            'enable' => $enable,
            'multiplier' => $multiplayer,
            'market_source' => $market_source
        ];
 
        $response = $this->postJson(route('bots-associate'), $request_data);
        $json = $response->json();
        $response->assertSuccessful();
        $response->assertJsonStructure(self::ACCOUNT_ASSOCIATION_JSON_STRUCTURE);
        $response->assertJsonFragment([
            'enabled' => $enable ?? false,
            'selected' => true,
            'multiplier' => $multiplayer ?? 1,
        ]);
 
        /** @var BotAccountAssociation $account_associated */
        $account_associated = $bot->accountAssociations()->first();
        self::assertEquals($enable ? $now->toDateTimeString() : null, $enable ? $account_associated->enabled_at->toDateTimeString() : $account_associated->enabled_at);
        self::assertEquals($multiplayer_expected, $account_associated->multiplayer);
    }
 
    /**
     * @feature Bots
     * @scenario Account Association
     * @case success
     *
     *
     * @covers \App\Http\Controllers\BotsController::associateAccount
     * @test
     * @param string $market_source
     */
    public function associate_account_with_bot_and_not_activate($market_source = 'bitmex')
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $market = $this->mockMarketSource($market_source);
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_broker' => $market_source, 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::DEFAULT]);
        $account = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id]);
 
        $request_data = [
            'bot_id' => $bot->id,
            'account_id' => $account->id,
            'enable' => false,
            'market_source' => $market_source
        ];
 
        $response = $this->postJson(route('bots-associate'), $request_data);
        $json = $response->json();
        $response->assertSuccessful();
        $response->assertJsonStructure(self::ACCOUNT_ASSOCIATION_JSON_STRUCTURE);
 
        $accounts = $bot->accountAssociations()->get();
        /** @var BotAccountAssociation $account_associated */
        $account_associated = $accounts->first();
        self::assertEquals(1, $accounts->count());
        self::assertEquals($account->id, $account_associated->account_id);
        self::assertEquals($account->market_id, $account_associated->market_id);
        self::assertEquals($bot->id, $account_associated->bot_id);
        self::assertEquals($bot->id, $account_associated->bot_id);
        self::assertEmpty($account_associated->enabled_at);
    }
 
    /**
     * @feature Bots
     * @scenario Account Association
     * @case success
     *
     *
     * @covers \App\Http\Controllers\BotsController::associateAccount
     * @test
     * @param string $market_source
     */
    public function enable_account_association($market_source = 'bitmex')
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
 
        $market = $this->mockMarketSource($market_source);
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_broker' => $market_source, 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::DEFAULT]);
        $account = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id]);
 
        $account_association = factory(BotAccountAssociation::class)->create(['account_id' => $account->id, 'bot_id' => $bot->id, 'enabled_at' => null]);
 
        $request_data = [
            'bot_id' => $bot->id,
            'account_id' => $account->id,
            'enable' => true,
            'market_source' => $market_source
        ];
 
        $response = $this->postJson(route('bots-associate'), $request_data);
        $json = $response->json();
        $response->assertSuccessful();
        $response->assertJsonStructure(self::ACCOUNT_ASSOCIATION_JSON_STRUCTURE);
 
        $accounts = $bot->accountAssociations()->get();
        /** @var BotAccountAssociation $account_associated */
        $account_associated = $accounts->first();
        self::assertEquals(1, $accounts->count());
        self::assertEquals($now->toDateTimeString(), $account_associated->enabled_at->toDateTimeString());
    }
 
    /**
     * @feature Bots
     * @scenario Account Association
     * @case success
     *
     *
     * @covers \App\Http\Controllers\BotsController::associateAccount
     * @test
     * @param string $market_source
     */
    public function disable_account_association($market_source = 'bitmex')
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $market = $this->mockMarketSource($market_source);
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_broker' => $market_source, 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::DEFAULT]);
        $account = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id]);
 
        $account_association = factory(BotAccountAssociation::class)->create(['account_id' => $account->id, 'bot_id' => $bot->id, 'enabled_at' => Carbon::now()]);
 
        $request_data = [
            'bot_id' => $bot->id,
            'account_id' => $account->id,
            'enable' => false,
            'market_source' => $market_source
        ];
 
        $response = $this->postJson(route('bots-associate'), $request_data);
        $json = $response->json();
        $response->assertSuccessful();
        $response->assertJsonStructure(self::ACCOUNT_ASSOCIATION_JSON_STRUCTURE);
 
        $accounts = $bot->accountAssociations()->get();
        /** @var BotAccountAssociation $account_associated */
        $account_associated = $accounts->first();
        self::assertEquals(1, $accounts->count());
        self::assertEmpty($account_associated->enabled_at);
    }

    /**
     * @feature Bots
     * @scenario Bot switch
     * @case on
     *
     * @feature Test
     * @scenario hytyszy
     * @case something
     *
     * @covers \App\Http\Controllers\BotsController::switchBot
     * @test
     * @param string $market_source
     * @param string $symbol
     */
    public function switch_bot_on(
        $market_source = MarketSource::BITMEX,
        $symbol = 'BTCUSD'
    ) {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $position1 = $this->mockPosition(3, $symbol);
        $this->mockPositionRepository(collect([$position1]));
        $market = $this->mockMarketSource($market_source);
        $this->mockBitmexClient();
        $this->mockBalance($market, 2, ['XBT']);
 
        $bot = $this->mockBot($symbol, $market_source, 3);
        $account1 = $this->createAccount($market);
        $account2 = $this->createAccount($market);
        $one = factory(BotAccountAssociation::class)->create(['account_id' => $account1->id, 'bot_id' => $bot->id, 'enabled_at' => null]);
        $two = factory(BotAccountAssociation::class)->create(['account_id' => $account2->id, 'bot_id' => $bot->id, 'enabled_at' => Carbon::now()->subDays(2)]);
 
        Bot::query()->whereNotIn('id', [$bot->id])->forceDelete();
 
        $request_data = [
            'bot_id' => $bot->id,
            'is_on' => true,
            'market_source' => $market_source
        ];
 
        $response = $this->postJson(route('bots-switch'), $request_data, [CacheBalanceControl::CACHE_CONTROL_HEADER => CacheBalanceControl::NO_CACHE_FLAG]);
        $json = $response->json();
        $response->assertSuccessful();
        $response->assertJsonStructure(['data' => self::SINGLE_BOT_JSON_WITH_DETAILS]);
        $accounts_enabled = collect($json['data']['accounts'])->where('enabled', '=', true)->pluck('account_id');
        self::assertEquals(2, $accounts_enabled->count());
 
        $one->refresh();
        self::assertEquals($now->toDateTimeString(), $one->enabled_at->toDateTimeString());
        $two->refresh();
        self::assertEquals($now->copy()->subDays(2)->toDateTimeString(), $two->enabled_at->toDateTimeString());
    }

    /**
     * @feature Bots
     * @scenario Bot update multiplier
     * @case success
     *
     *
     * @covers \App\Http\Controllers\BotsController::switchBot
     * @test
     * @param string $market_source
     * @param string $symbol
     */
    public function update_bot_multiplier(
        $market_source = MarketSource::BITMEX,
        $symbol = 'BTCUSD'
    ) {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $position1 = $this->mockPosition(3, $symbol);
        $this->mockPositionRepository(collect([$position1]));
        $market = $this->mockMarketSource($market_source);
        $this->mockBitmexClient();
        $this->mockBalance($market, 2, ['XBT']);
 
        $bot = $this->mockBot($symbol, $market_source, 3);
        $account1 = $this->createAccount($market);
        $account2 = $this->createAccount($market);
        /** @var BotAccountAssociation $one */
        /** @var BotAccountAssociation $two */
        $one = factory(BotAccountAssociation::class)->create(['account_id' => $account1->id, 'bot_id' => $bot->id, 'multiplayer' => 1, 'enabled_at' => Carbon::now()]);
        $two = factory(BotAccountAssociation::class)->create(['account_id' => $account2->id, 'bot_id' => $bot->id, 'multiplayer' => 5, 'enabled_at' => Carbon::now()]);
 
        Bot::query()->whereNotIn('id', [$bot->id])->forceDelete();
 
        $request_data = [
            'bot_id' => $bot->id,
            'multiplier' => 5,
            'market_source' => $market_source
        ];
 
        $response = $this->postJson(route('bots-update-multiplier'), $request_data, [CacheBalanceControl::CACHE_CONTROL_HEADER => CacheBalanceControl::NO_CACHE_FLAG]);
        $json = $response->json();
        $response->assertSuccessful();
        $response->assertJsonStructure(['data' => self::SINGLE_BOT_JSON_WITH_DETAILS]);
        $accounts_enabled = collect($json['data']['accounts'])->where('enabled', '=', true)->pluck('account_id');
        self::assertEquals(2, $accounts_enabled->count());
 
        self::assertEquals(5, $one->refresh()->multiplayer);
        self::assertEquals(5, $two->refresh()->multiplayer);
    }
 
//    /**
//     * @feature Bots
//     * @scenario Bot update multiplier
//     * @case positions mismatch
//     *
//     *
//     * @covers \App\Http\Controllers\BotsController::switchBot
//     * @test
//     * @param string $market_source
//     * @param string $symbol
//     */
//    public function update_bot_multiplier_positions_mismatch_throw(
//        $market_source = MarketSource::BITMEX,
//        $symbol = 'BTCUSD'
//    ) {
//        $this->markTestSkipped('Position validation was disable - not needed');
//
//        $now = Carbon::now();
//        Carbon::setTestNow($now);
//        $this->createUserAndBe();
//        $position1 = $this->mockPosition(3, $symbol);
//        $this->mockPositionRepository(collect([$position1]));
//        $market = $this->mockMarketSource($market_source);
//        $this->mockBitmexClient();
//        $this->mockBalance($market, 2, ['XBT']);
//
//        $bot = $this->mockBot($symbol, $market_source, 3, RunnerType::REVERSE);
//        $account1 = $this->createAccount($market);
//        $account2 = $this->createAccount($market);
//        /** @var BotAccountAssociation $one */
//        /** @var BotAccountAssociation $two */
//        $one = factory(BotAccountAssociation::class)->create(['account_id' => $account1->id, 'bot_id' => $bot->id, 'multiplayer' => 2, 'enabled_at' => Carbon::now()]);
//        $two = factory(BotAccountAssociation::class)->create(['account_id' => $account2->id, 'bot_id' => $bot->id, 'multiplayer' => 5, 'enabled_at' => Carbon::now()]);
//
//        Bot::query()->whereNotIn('id', [$bot->id])->forceDelete();
//
//        $request_data = [
//            'bot_id' => $bot->id,
//            'multiplier' => 5,
//        ];
//
//        $response = $this->postJson(route('bots-update-multiplier'), $request_data);
//        $json = $response->json();
//        $response->assertStatus(409);
//
//        self::assertEquals(2, $one->refresh()->multiplayer);
//        self::assertEquals(5, $two->refresh()->multiplayer);
//    }
 
    /**
     * @feature Bots
     * @scenario Bot update multiplier
     * @case positions mismatch - update with force
     *
     *
     * @covers \App\Http\Controllers\BotsController::switchBot
     * @test
     * @param string $market_source
     * @param string $symbol
     */
    public function update_bot_multiplier_positions_mismatch_run_with_force(
        $market_source = MarketSource::BITMEX,
        $symbol = 'BTCUSD'
    ) {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $position1 = $this->mockPosition(3, $symbol);
        $this->mockPositionRepository(collect([$position1]));
        $market = $this->mockMarketSource($market_source);
        $this->mockBitmexClient();
        $this->mockBalance($market, 2, ['XBT']);
 
        $bot = $this->mockBot($symbol, $market_source, 3, RunnerType::REVERSE);
        $account1 = $this->createAccount($market);
        $account2 = $this->createAccount($market);
        /** @var BotAccountAssociation $one */
        /** @var BotAccountAssociation $two */
        $one = factory(BotAccountAssociation::class)->create(['account_id' => $account1->id, 'bot_id' => $bot->id, 'multiplayer' => 6, 'enabled_at' => Carbon::now()]);
        $two = factory(BotAccountAssociation::class)->create(['account_id' => $account2->id, 'bot_id' => $bot->id, 'multiplayer' => 6, 'enabled_at' => Carbon::now()]);
 
        Bot::query()->whereNotIn('id', [$bot->id])->forceDelete();
 
        $request_data = [
            'bot_id' => $bot->id,
            'multiplier' => 6,
            'force' => true,
            'market_source' => $market_source
        ];
 
        $response = $this->postJson(route('bots-update-multiplier'), $request_data);
        $json = $response->json();
        $response->assertStatus(200);
 
        self::assertEquals(6, $one->refresh()->multiplayer);
        self::assertEquals(6, $two->refresh()->multiplayer);
    }
 
    /**
     * @feature Bots
     * @scenario Bot switch
     * @case on success
     *
     *
     * @covers \App\Http\Controllers\BotsController::switchBot
     * @test
     * @param string $market_source
     * @param string $symbol
     */
    public function switch_bot_on_reverse_bot(
        $market_source = MarketSource::BITMEX,
        $symbol = 'BTCUSD'
    ) {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $market = $this->mockMarketSource($market_source);
        $this->mockBitmexClient();
        $this->mockBalance($market, 1, ['XBT']);
        $position = $this->mockPosition(6, $symbol);
        $this->mockPositionRepository(collect([$position]));
 
        $bot = $this->mockBot($symbol, $market_source, 3, RunnerType::REVERSE);
        $account1 = factory(Account::class)->create(['user_id' => $this->user->id, 'active' => true, 'market_id' => $market->id]);
        $one = factory(BotAccountAssociation::class)->create(['account_id' => $account1->id, 'bot_id' => $bot->id, 'enabled_at' => null, 'multiplayer' => 2]);
 
        Bot::query()->whereNotIn('id', [$bot->id])->forceDelete();
 
        $request_data = [
            'bot_id' => $bot->id,
            'is_on' => true,
            'market_source' => $market_source
        ];
 
        $response = $this->postJson(route('bots-switch'), $request_data);
        $json = $response->json();
        $response->assertSuccessful();
        $response->assertJsonStructure(['data' => self::SINGLE_BOT_JSON_WITH_DETAILS]);
 
        $one->refresh();
        self::assertEquals($now->toDateTimeString(), $one->enabled_at->toDateTimeString());
    }
 
//    /**
//     * @feature Bots
//     * @scenario Bot switch
//     * @case failed positions not match
//     *
//     *
//     * @covers \App\Http\Controllers\BotsController::switchBot
//     * @test
//     * @param string $market_source
//     * @param string $symbol
//     */
//    public function switch_bot_on_reverse_bot_validation_error(
//        $market_source = MarketSource::BITMEX,
//        $symbol = 'BTCUSD'
//    ) {
//        $this->markTestSkipped('Position validation was disable - not needed');
//
//        $now = Carbon::now();
//        Carbon::setTestNow($now);
//        $this->createUserAndBe();
//        $market = $this->mockMarketSource($market_source);
//        $this->mockBalance($market, 0, ['XBT']);
//
//        $position = $this->mockPosition(1, $symbol);
//        $this->mockPositionRepository(collect([$position]));
//
//        /** @var Bot $bot */
//        $bot = factory(Bot::class)->create(['instrument_symbol' => $symbol, 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::REVERSE, 'positions' => 3]);
//        $account1 = factory(Account::class)->create(['user_id' => $this->user->id, 'market_id' => $market->id]);
//        $one = factory(BotAccountAssociation::class)->create(['account_id' => $account1->id, 'bot_id' => $bot->id, 'enabled_at' => null, 'multiplayer' => 1]);
//
//        Bot::query()->whereNotIn('id', [$bot->id])->forceDelete();
//
//        $request_data = [
//            'bot_id' => $bot->id,
//            'is_on' => true,
//        ];
//
//        $response = $this->postJson(route('bots-switch'), $request_data);
//        $json = $response->json();
//        $response->assertStatus(409);
//        $response->assertJsonStructure([
//            'errors' => [
//                '*' => [
//                    'message',
//                    'account_positions',
//                    'bot_positions',
//                    'account_id',
//                ],
//            ],
//        ]);
//
//        $response->assertJsonFragment([
//            'account_positions' => 1,
//            'bot_positions' => 3,
//            'account_id' => $account1->id,
//        ]);
//
//        $one->refresh();
//        self::assertEquals(null, $one->enabled_at);
//    }
 
    /**
     * @feature Bots
     * @scenario Bot switch
     * @case positions not match - on by force
     *
     *
     * @covers \App\Http\Controllers\BotsController::switchBot
     * @test
     * @param string $market_source
     * @param string $symbol
     */
    public function switch_bot_on_reverse_associate_force(
        $market_source = MarketSource::BITMEX,
        $symbol = 'BTCUSD'
    ) {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $market = $this->mockMarketSource($market_source);
        $this->mockBalance($market, 0, ['XBT']);
 
        $position = $this->mockPosition(1, $symbol);
        $this->mockPositionRepository(collect([$position]));
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_symbol' => $symbol, 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::REVERSE, 'positions' => 3]);
        $account1 = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id]);
        $one = factory(BotAccountAssociation::class)->create(['account_id' => $account1->id, 'bot_id' => $bot->id, 'enabled_at' => null, 'multiplayer' => 1]);
        Bot::query()->whereNotIn('id', [$bot->id])->forceDelete();
 
        $request_data = [
            'bot_id' => $bot->id,
            'is_on' => true,
            'force' => true,
            'market_source' => $market_source
        ];
 
        $response = $this->postJson(route('bots-switch'), $request_data);
        $json = $response->json();
        $response->assertStatus(200);
 
        $one->refresh();
        self::assertEquals($now->toDateTimeString(), $one->enabled_at->toDateTimeString());
    }
 
    /**
     * @feature Bots
     * @scenario Bot switch
     * @case off
     *
     *
     * @covers \App\Http\Controllers\BotsController::switchBot
     * @test
     * @param string $market_source
     * @param string $symbol
     */
    public function switch_bot_off(
        $market_source = MarketSource::BITMEX,
        $symbol = 'BTCUSD'
    ) {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $market = $this->mockMarketSource($market_source);
        $this->mockBalance($market, 2, ['XBT']);
        $this->mockBitmexClient();
 
        $position = $this->mockPosition(100, $symbol);
        $this->mockPositionRepository(collect([$position]));
 
        $bot = $this->mockBot($symbol, $market_source, 1);
        $account1 = $this->createAccount($market);
        $account2 = $this->createAccount($market);
        $one = factory(BotAccountAssociation::class)->create(['account_id' => $account1->id, 'bot_id' => $bot->id, 'enabled_at' => null]);
        $two = factory(BotAccountAssociation::class)->create(['account_id' => $account2->id, 'bot_id' => $bot->id, 'enabled_at' => Carbon::now()->subDays(2)]);
 
        Bot::query()->whereNotIn('id', [$bot->id])->forceDelete();
 
        $request_data = [
            'bot_id' => $bot->id,
            'is_on' => false,
            'market_source' => $market_source
        ];
 
        $response = $this->postJson(route('bots-switch'), $request_data, [CacheBalanceControl::CACHE_CONTROL_HEADER => CacheBalanceControl::NO_CACHE_FLAG]);
        $json = $response->json();
        $response->assertSuccessful();
        $response->assertJsonStructure(['data' => self::SINGLE_BOT_JSON_WITH_DETAILS]);
 
        $accounts_enabled = collect($json['data']['accounts'])->where('enabled', '=', true)->pluck('account_id');
        $accounts_disabled = collect($json['data']['accounts'])->where('enabled', '=', false)->pluck('account_id');
        self::assertEquals(0, $accounts_enabled->count());
        self::assertEquals(2, $accounts_disabled->count());
 
        $one->refresh();
        self::assertEquals(null, $one->enabled_at);
        $two->refresh();
        self::assertEquals(null, $two->enabled_at);
    }
 
    /**
     * @feature Bots
     * @scenario Account Association forbidden
     * @case success
     *
     *
     * @covers \App\Http\Controllers\BotsController::associateAccount
     * @test
     */
    public function prevent_associate_account()
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        BouncerFacade::sync(Role::first())->abilities([]);
        BouncerFacade::sync($this->user)->abilities([]);
        self::assertFalse($this->user->can('bot-associate-account'));
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::DEFAULT]);
        $account = factory(Account::class)->create(['user_id' => $this->user->id]);
        $request_data = ['bot_id' => $bot->id, 'account_id' => $account->id, 'enable' => true, 'market_source' => MarketSource::BITMEX];
 
        $response = $this->postJson(route('bots-associate'), $request_data);
        $json = $response->json();
        $response->assertForbidden();
 
        $accounts = $bot->accountAssociations()->get();
        /** @var BotAccountAssociation $account_associated */
        self::assertEquals(0, $accounts->count());
    }
 
    /**
     * @feature Bots
     * @scenario Account Association forbidden
     * @case success
     *
     *
     * @covers \App\Http\Controllers\BotsController::associateAccount
     * @test
     * @param string $market_source
     */
    public function allow_associate_account($market_source = 'bitmex')
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $market = $this->mockMarketSource($market_source);
        BouncerFacade::sync($this->user)->abilities([]);
        BouncerFacade::allow($this->user)->to('bot-associate-account', \App\Models\Bot::class);
        self::assertTrue($this->user->can('bot-associate-account', \App\Models\Bot::class));
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_broker' => $market_source, 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::DEFAULT]);
        $account = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id]);
        $request_data = ['bot_id' => $bot->id, 'account_id' => $account->id, 'enable' => true, 'market_source' => $market_source];
 
        $response = $this->postJson(route('bots-associate'), $request_data);
        $json = $response->json();
        $response->assertSuccessful();
 
        $accounts = $bot->accountAssociations()->get();
        /** @var BotAccountAssociation $account_associated */
        self::assertEquals(1, $accounts->count());
    }
 
    /**
     * @feature Bots
     * @scenario Account Association
     * @case remove
     *
     *
     * @covers \App\Http\Controllers\BotsController::removeAccountAssociation
     * @test
     * @param string $market_source
     */
    public function remove_associate_account($market_source = 'bitmex')
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $market = $this->mockMarketSource($market_source);
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_broker' => $market_source, 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::DEFAULT]);
        $account = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id]);
        $account_associated = factory(BotAccountAssociation::class)->create(['account_id' => $account->id, 'bot_id' => $bot->id, 'enabled_at' => null]);
        self::assertEquals(1, $bot->accountAssociations()->count());
 
        $request_data = ['bot_id' => $bot->id, 'account_id' => $account->id, 'market_source' => $market_source];
        $response = $this->deleteJson(route('bots-associate'), $request_data);
        $json = $response->json();
        $response->assertSuccessful();
        $response->assertJsonFragment([
            'id' => null,
            'enabled' => false,
            'selected' => false,
        ]);
 
        $accounts = $bot->accountAssociations()->get();
        self::assertEquals(0, $accounts->count());
    }
 
    /**
     * @feature Bots
     * @scenario Account Association
     * @case remove forbidden
     *
     *
     * @covers \App\Http\Controllers\BotsController::removeAccountAssociation
     * @test
     * @param string $market_source
     */
    public function remove_associate_account_forbidden($market_source = 'bitmex')
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_broker' => $market_source, 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::DEFAULT]);
        /** @var BotAccountAssociation $account_associated */
        $account_associated = factory(BotAccountAssociation::class)->create(['bot_id' => $bot->id, 'enabled_at' => null]);
        self::assertEquals(1, $bot->accountAssociations()->count());
 
        $request_data = ['bot_id' => $bot->id, 'account_id' => $account_associated->account_id, 'market_source' => $market_source];
        $response = $this->deleteJson(route('bots-associate'), $request_data);
        $json = $response->json();
        $response->assertStatus(424);
 
        $accounts = $bot->accountAssociations()->get();
        self::assertEquals(1, $accounts->count());
    }
 
    /**
     * @feature Bots
     * @scenario Accounts
     * @case close positions
     *
     *
     * @covers \App\Http\Controllers\BotsController::closePositions
     * @test
     * @param string $market_source
     */
    public function close_accounts_positions_for_all_associated_accounts($market_source = 'bitmex')
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $this->createUserAndBe();
        $market = $this->mockMarketSource($market_source);
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_broker' => $market_source, 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::DEFAULT]);
        $account1 = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id, 'active' => true]);
        $account2 = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id, 'active' => true]);
        $account_associated1 = factory(BotAccountAssociation::class)->create(['account_id' => $account1->id, 'bot_id' => $bot->id, 'enabled_at' => Carbon::now()]);
        $account_associated2 = factory(BotAccountAssociation::class)->create(['account_id' => $account2->id, 'bot_id' => $bot->id, 'enabled_at' => Carbon::now()]);
        self::assertEquals(2, $bot->userAccountAssociations()->count());
 
        $bot_close = \Mockery::mock(BotCloseTrading::class);
        $bot_close->shouldReceive('init')->andReturn($bot_close);
        $bot_close->shouldReceive('close')->andReturn(true);
 
        $factory = \Mockery::mock(DomainServiceFactory::class);
        $factory->shouldReceive('create')->with(IBotCloseTrading::class, [])->andReturn($bot_close);
        $this->app->instance(DomainServiceFactory::class, $factory);
 
        $request_data = ['bot_id' => $bot->id, 'market_source' => $market_source];
        $response = $this->postJson(route('bots-close-positions'), $request_data);
        $json = $response->json();
        $response->assertSuccessful();
        $response->assertJsonFragment(['closed' => true]);
    }
 
    /**
     * @feature Bots
     * @scenario Accounts
     * @case reset
     *
     *
     * @covers \App\Http\Controllers\BotsController::closePositions
     * @test
     * @param string $market_source
     * @param string $symbol
     */
    public function reset_for_all_associated_accounts(
        $market_source = MarketSource::BITMEX,
        $symbol = 'BTCUSD'
    ) {
        $this->createUserAndBe();
 
        $position1 = $this->mockPosition(3, $symbol);
        $this->mockPositionRepository(collect([$position1]));
        $market = $this->mockMarketSource($market_source);
        $this->mockBitmexClient();
        $this->mockBalance($market, 2, ['XBT']);
 
        /** @var Bot $bot */
        $bot = factory(Bot::class)->create(['instrument_broker' => $market_source, 'instrument_symbol' => 'BTCUSD', 'status' => RunnerStatus::RUNNING, 'type' => RunnerType::DEFAULT]);
        $account1 = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id, 'active' => true]);
        $account2 = factory(Account::class)->create(['market_id' => $market->id, 'user_id' => $this->user->id, 'active' => true]);
        /** @var BotAccountAssociation $account_associated1 */
        $account_associated1 = factory(BotAccountAssociation::class)->create([
            'account_id' => $account1->id,
            'bot_id' => $bot->id,
            'enabled_at' => Carbon::now(),
            'bot_account_balance' => 220.10,
            'bot_account_positions' => -10,
        ]);
        /** @var BotAccountAssociation $account_associated2 */
        $account_associated2 = factory(BotAccountAssociation::class)->create([
            'account_id' => $account2->id,
            'bot_id' => $bot->id,
            'enabled_at' => Carbon::now(),
            'bot_account_balance' => 10.10,
            'bot_account_positions' => 10,
        ]);
 
        /** @var BotAccountAssociation $account_associated_not_included */
        $account_associated_not_included = factory(BotAccountAssociation::class)->create([
            'bot_id' => $bot->id,
            'enabled_at' => Carbon::now(),
            'bot_account_balance' => 10.10,
            'bot_account_positions' => 10,
        ]);
        self::assertEquals(2, $bot->userAccountAssociations()->count());
 
        $request_data = ['bot_id' => $bot->id, 'market_source' => $market_source];
        $response = $this->postJson(route('bots-reset'), $request_data);
        $json = $response->json();
        $response->assertJsonStructure(['data' => self::SINGLE_BOT_JSON_WITH_DETAILS]);
        $response->assertSuccessful();
 
        self::assertEquals(0, $account_associated1->refresh()->bot_account_balance);
        self::assertEquals(0, $account_associated1->refresh()->bot_account_positions);
        self::assertEquals(0, $account_associated2->refresh()->bot_account_balance);
        self::assertEquals(0, $account_associated2->refresh()->bot_account_positions);
 
        self::assertEquals(10.10, $account_associated_not_included->refresh()->bot_account_balance);
        self::assertEquals(10, $account_associated_not_included->refresh()->bot_account_positions);
    }
 
    /**
     * @return \Illuminate\Database\Eloquent\Model|mixed
     */
    protected function createBot($currency, $status, $interval, $market_source):Bot
    {
        return factory(Bot::class)
            ->create([
                'instrument_currency' => $currency,
                'status' => $status, 'interval_symbol' => $interval, 'instrument_broker' => $market_source
            ]);
    }
 
    /**
     * @param Market $market
     * @return \Illuminate\Database\Eloquent\Model|mixed
     */
    protected function createAccount(Market $market):Account
    {
        /**
         * @var Account $account
         */
        $account = factory(Account::class)->create(['user_id' => $this->user->id, 'active' => true]);
        $account->markets()->attach($market);
 
        return $account;
    }
 
    /**
     * @param Market $market
     * @param Bot $bot
     * @return \Illuminate\Database\Eloquent\Model|mixed
     */
    protected function createAccountAndAssociateToBot(Market $market, Bot $bot)
    {
        $account = $this->createAccount($market);
        $this->associateAccountToBot($account, $bot);
 
        return $account;
    }
 
    /**
     * @param Market $market
     * @param Bot $bot
     * @return \Illuminate\Database\Eloquent\Model|mixed
     */
    protected function createAccountAndAssociateToBotAndEnable(Market $market, Bot $bot)
    {
        $account = $this->createAccount($market);
        $associated = $this->associateAccountToBot($account, $bot);
        $associated->enabled_at = Carbon::now();
 
        return $account;
    }
 
    /**
     * @param Account $account
     * @param Bot $bot
     * @return \Illuminate\Database\Eloquent\Model|mixed
     */
    protected function associateAccountToBot(Account $account, Bot $bot): BotAccountAssociation
    {
        return factory(BotAccountAssociation::class)->create([
            'account_id' => $account->id,
            'bot_id' => $bot->id,
            'enabled_at' => null
        ]);
    }
 
    private function whenBotInstrumentRateIs(float $rate): void
    {
        $bot = $this->createRunningBot(MarketSource::BINANCE);
        $bot->instrument_symbol = 'symbol';
        $bot->save();
        $this->mockExchangeRate($rate);
    }
 
    private function createRunningBot(string $market_source): Bot
    {
        return factory(Bot::class)->create(
            [
                'status' => RunnerStatus::RUNNING,
                'instrument_type' => InstrumentType::CRYPTO,
                'instrument_broker' => $market_source,
                'instrument_symbol' => 'XBTUSD',
                'type' => RunnerType::DEFAULT,
            ]
        );
    }
 
    /**
     * @param float $rate
     */
    private function mockExchangeRate(float $rate): void
    {
        $market = \Mockery::mock(IApi::class);
        $this->instance(Binance::class, $market);
        $this->instance(Bitmex::class, $market);
        $market->shouldReceive('init');
        $market->shouldReceive('isOpen')->andReturn(false);
        $market->shouldReceive('getRate')->andReturn($rate);
    }
}
