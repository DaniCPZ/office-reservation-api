<?php

namespace Tests\Feature;

use App\Models\Office;
use Tests\TestCase;
use App\Models\User;
use App\Models\Reservation;
use App\Notifications\NewHostReservation;
use App\Notifications\NewUserReservation;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;

class UserReservationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    /**
     * @test
    */
    public function itListsReservationsThatBelongToTheUser()
    {
        $user = User::factory()->create();
        [$reservation] = Reservation::factory()->for($user)->count(2)->create();
        $image = $reservation->office->images()->create([
            'path' => 'office_image.jpg'
        ]);
        $reservation->office()->update([
            'featured_image_id' => $image->id
        ]);
        Reservation::factory()->count(3)->create();
        Sanctum::actingAs($user, ['*']);
        $response = $this->getJson('/api/reservations');
        
        $response->assertJsonStructure([ 'data', 'meta', 'links' ])
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([ 'data' => ['*' => ['id', 'office'] ] ])
            ->assertJsonPath('data.0.office.featured_image.id', $image->id);
    }
    
    /**
     * @test
    */
    public function itListsReservationsFilterByDateRange()
    {
        $user = User::factory()->create();
        $fromDate   = '2021-03-03';
        $toDate     = '2021-04-04';
        
        // Whithin the date range
        $reservations = Reservation::factory()->for($user)->createMany([
            [
                'start_date' => '2021-03-01',
                'end_date'  => '2021-03-15',
            ],
            [
                'start_date' => '2021-03-25',
                'end_date'  => '2021-04-15',
            ],
            [
                'start_date' => '2021-03-25',
                'end_date'  => '2021-03-28',
            ],
            [
                'start_date' => '2021-03-01',
                'end_date'  => '2021-05-05',
            ]
        ]);
        // Whithin the date range but belongs to a different user
        Reservation::factory()->create([
            'start_date' => '2021-03-25',
            'end_date'  => '2021-03-28',
        ]);

        // Outside the date range
        Reservation::factory()->for($user)->create([
            'start_date' => '2021-02-25',
            'end_date'  => '2021-03-01',
        ]);
        Reservation::factory()->for($user)->create([
            'start_date' => '2021-05-01',
            'end_date'  => '2021-05-02',
        ]);

        Sanctum::actingAs($user, ['*']);
        
        $query = http_build_query([
            'from_date'     => $fromDate,
            'to_date'    => $toDate,
        ]);
        
        // DB::enableQueryLog();
        $response = $this->getJson("/api/reservations?{$query}");
        
        // dd(DB::getQueryLog());

        $response->assertJsonCount(4, 'data');
        $this->assertEquals(
            $reservations->pluck('id')->toArray(),
            collect($response->json('data'))->pluck('id')->toArray()
        );
    }

    /**
     * @test
    */
    public function itFiltersResultsByStatus()
    {
        $user = User::factory()->create();
        $reservation = Reservation::factory()->for($user)->create([
            'status' => Reservation::STATUS_ACTIVE
        ]);      
        $reservation2 = Reservation::factory()->cancelled()->create();
        
        Sanctum::actingAs($user, ['*']);
        
        $query = http_build_query([
            'status' => Reservation::STATUS_ACTIVE,
        ]);
        
        $response = $this->getJson("/api/reservations?{$query}");
        $response
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reservation->id);        
    }

    /**
     * @test
    */
    public function itFiltersResultsByOffice()
    {
        $user = User::factory()->create();
        $office = Office::factory()->create();
        $reservation = Reservation::factory()->for($office)->for($user)->create();      
        Reservation::factory()->create();
        
        Sanctum::actingAs($user, ['*']);
        
        $query = http_build_query([
            'office_id' => $office->id,
        ]);
        
        $response = $this->getJson("/api/reservations?{$query}");
        $response
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reservation->id);        
    }

    /**
     * @test
    */
    public function itMakesReservations()
    {
        $user = User::factory()->create();

        $office = Office::factory()->create([
            'price_per_day'     => 1000,
            'monthly_discount'  => 10,
        ]);
        
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/reservations', [
            'office_id'     => $office->id, 
            'start_date'    => now()->addDay(1),
            'end_date'      => now()->addDay(40),
        ]);

        $response->assertJsonPath('data.price', 36000)
            ->assertJsonPath('data.status', Reservation::STATUS_ACTIVE )
            ->assertJsonPath('data.office_id', $office->id)
            ->assertJsonPath('data.user_id', $user->id);
    }

    /**
     * @test
    */
    public function itCannotMakeReservationOnNonExistingOffice()
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/reservations', [
            'office_id'     => 1, 
            'start_date'    => now()->addDay(1),
            'end_date'      => now()->addDay(41),
        ]);
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['office_id' => 'Invalid office ID']);
    }
    /**
     * @test
    */
    public function itCannotMakeReservationOnOfficeThatBelongsToTheUser()
    {
        $user = User::factory()->create();
        
        $office = Office::factory()->for($user)->create([
            'price_per_day'     => 1000,
            'monthly_discount'  => 10,
        ]);
        
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/reservations', [
            'office_id'     =>  $office->id, 
            'start_date'    => now()->addDay(1),
            'end_date'      => now()->addDay(42),
        ]);
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['office_id' => 'You cannot make a reservation on your own office']);
    }

    /**
     * @test
    */
    public function itCannotMakeReservationOnOfficeThatIsPendingOrHidden()
    {
        $user = User::factory()->create();

        $office = Office::factory()->create([
            'approval_status' => Office::APPROVAL_PENDING,
        ]);
        
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/reservations', [
            'office_id'     =>  $office->id, 
            'start_date'    => now()->addDay(1),
            'end_date'      => now()->addDay(42),
        ]);
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['office_id' => 'You cannot make a reservation on hidden office']);
    }

     /**
     * @test
    */
    public function itCannotMakeReservationLessThan2Days()
    {
        $user = User::factory()->create();

        $office = Office::factory()->create([
            'price_per_day'     => 1000,
            'monthly_discount'  => 10,
        ]);
        
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/reservations', [
            'office_id'     => $office->id, 
            'start_date'    => now()->addDay(1),
            'end_date'      => now()->addDay(1),
        ]);
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date' => 'You cannot make a reservation for only 1 day']);
    }
    
    /**
     * @test
    */
    public function itMakeReservationFor2days()
    {
        $user = User::factory()->create();

        $office = Office::factory()->create([
            'price_per_day'     => 1000,
            'monthly_discount'  => 10,
        ]);
        
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/reservations', [
            'office_id'     => $office->id, 
            'start_date'    => now()->addDay(1),
            'end_date'      => now()->addDay(2),
        ]);

        $response->assertJsonPath('data.price', 2000)
            ->assertJsonPath('data.status', Reservation::STATUS_ACTIVE )
            ->assertJsonPath('data.office_id', $office->id)
            ->assertJsonPath('data.user_id', $user->id);
    }

    /**
     * @test
    */
    public function itCannotMakeReservationOnSameDay()
    {
        $user = User::factory()->create();

        $office = Office::factory()->create();
        
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/reservations', [
            'office_id'     => $office->id, 
            'start_date'    => now()->toDateString(),
            'end_date'      => now()->addDay(4)->toDateString(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date' => 'The start date must be a date after today.']);
    }

    /**
     * @test
    */
    public function itCannotMakeReservationThatsConflicting()
    {
        $user = User::factory()->create();
        $fromDate   = now()->addDay(2)->toDateString();
        $toDate     = now()->addDay(15)->toDateString();
        
        $office = Office::factory()->create();
        Reservation::factory()->for($office)->create([
            'start_date' => now()->addDay(3),
            'end_date'  => $toDate,
        ]);        
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/reservations', [
            'office_id'     => $office->id, 
            'start_date'    => $fromDate,
            'end_date'      => $toDate,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['office_id' => 'You cannot make a reservation during this time']);
    }

    /**
     * @test
    */
    public function itSendsNotificationsOnNewReservation()
    {
        Notification::fake();

        $user = User::factory()->create();
        $host = User::factory()->create();
        $office = Office::factory()->for($host)->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/reservations', [
            'office_id'     => $office->id, 
            'start_date'    => now()->addDay(1),
            'end_date'      => now()->addDay(3),
        ]);
        Notification::assertSentTo($user, NewUserReservation::class);
        Notification::assertSentTo($host, NewHostReservation::class);
        $response->assertCreated();
    }
}
