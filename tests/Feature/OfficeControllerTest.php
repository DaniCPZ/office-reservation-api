<?php

namespace Tests\Feature;

use App\Models\Tag;
use Tests\TestCase;
use App\Models\User;
use App\Models\Office;
use App\Models\Reservation;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use App\Notifications\OfficePendingApproval;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Notification;

class OfficeControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    
    public function test_it_lists_all_offices_in_paginated_way()
    {
        Office::factory(3)->create();
    
        $response = $this->get('/api/offices');
    
        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $this->assertNotNull($response->json('data')[0]['id']);
        $this->assertNotNull($response->json('meta'));
        $this->assertNotNull($response->json('links'));	
    }

    public function test_it_should_only_lists_offices_that_are_not_hidden_and_approved()
    {    	
        Office::factory(3)->create();
        Office::factory()->hidden()->create();
        Office::factory()->pending()->create();
    
        $response = $this->get('/api/offices');
    
        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }
    
    /**
     * @test
    */
    public function itListsOfficesIncludingHiddenAndUnapprovedIfFilteringForTheCurrentLoggedInUser()
    {
        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable $user
         */
        $user = User::factory()->create();

        Office::factory(3)->for($user)->create();
        Office::factory()->hidden()->for($user)->create();
        Office::factory()->pending()->for($user)->create();
    
        Sanctum::actingAs($user, ['*']);

        $response = $this->get('/api/offices?user_id='.$user->id);
    
        $response->assertOk();
        $response->assertJsonCount(5, 'data');        
    }

    public function test_it_should_filters_by_user_id()
    {
        Office::factory(3)->create();

        $user = User::factory()->create();
        Office::factory()->for($user)->create();
        
        $response = $this->get(
            '/api/offices?user_id='.$user->id
        );
    
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_it_should_filters_by_visitor_id()
    {
        Office::factory(3)->create();

        $visitor = User::factory()->create();
        
        $office = Office::factory()->create();
        Reservation::factory()->for($office)->for($visitor)->create();
        
        $response = $this->get(
            '/api/offices?visitor_id='.$visitor->id
        );
    
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    /**
     * @test
    */
    public function itFiltersByTags()
    {
        $tags = Tag::factory(3)->create();

        Office::factory()->hasAttached($tags)->create();
        Office::factory()->hasAttached($tags->first())->create();
        Office::factory()->create();

        $response = $this->get(
            '/api/offices?'.http_build_query([
                'tags' => $tags->pluck('id')->toArray()
            ])
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_it_should_includes_images_tags_and_user()
    {
    	$user = User::factory()->create();
        $tag = Tag::factory()->create();
        $office = Office::factory()->for($user)->create();

        $office->tags()->attach($tag);
        $office->images()->create(['path' => 'iamge.jpg']);

        $response = $this->get('/api/offices');
        $response->assertOk();
                
        $this->assertIsArray($response->json('data')[0]['tags']);
        $this->assertCount(1, $response->json('data')[0]['tags']);
        $this->assertIsArray($response->json('data')[0]['images']);
        $this->assertCount(1, $response->json('data')[0]['images']);
        $this->assertEquals($user->id, $response->json('data')[0]['user']['id']);
    }


    public function test_it_should_returns_the_number_of_active_reservations()
    {
        $office = Office::factory()->create();
    	Reservation::factory()->for($office)->create(['status' => Reservation::STATUS_ACTIVE]);
    	Reservation::factory()->for($office)->create(['status' => Reservation::STATUS_CANCELLED]);
        
        $response = $this->get('/api/offices');
        $response->assertOk();
        $this->assertEquals(1, $response->json('data')[0]['reservations_count']);

    }


    public function test_it_should_orders_by_distance_when_coordinates_are_provided()
    {
        $office1 = Office::factory()->create([
            'lat'   => '-38.7200251258884',
            'lng'   => '-62.12444799071852',
            'title' => 'Bahia Blanca'
        ]);

        $office2 = Office::factory()->create([
            'lat' => '-38.034726101258734',
            'lng' => '-57.62863902620025',
            'title' => 'Mar del plata'
        ]);

        $response = $this->get(
            '/api/offices?lat=-34.555526645386024&lng=-59.13253462987742'
        );
        
        $response->assertOk();
        $this->assertEquals($office2->id , $response->json('data')[0]['id']);
        
        $response = $this->get('/api/offices');
        
        $this->assertEquals($office1->id , $response->json('data')[0]['id']);
    }

    public function test_it_should_shows_the_office()
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->create();
        $office = Office::factory()->for($user)->create();

        $office->tags()->attach($tag);
        $office->images()->create(['path' => 'image.jpg']);

        Reservation::factory()->for($office)->create(['status' => Reservation::STATUS_ACTIVE]);
    	Reservation::factory()->for($office)->create(['status' => Reservation::STATUS_CANCELLED]);
        
        $response = $this->get('/api/offices/'.$office->id);    	        

        $response->assertOk();
        $this->assertEquals(1, $response->json('data')['reservations_count']);
        $this->assertIsArray($response->json('data')['tags']);
        $this->assertCount(1, $response->json('data')['tags']);
        $this->assertIsArray($response->json('data')['images']);
        $this->assertCount(1, $response->json('data')['images']);
        $this->assertEquals($user->id, $response->json('data')['user']['id']);
    }

    public function test_it_should_creates_an_office()
    {        
        Notification::fake();
        $admin = User::factory()->create(['is_admin'=>true]);

        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable $user
         */
        $user = User::factory()->createQuietly();
        $tags = Tag::factory(2)->create(); 

        Sanctum::actingAs($user, ['*']);
        $response = $this->postJson('/api/offices', 
            Office::factory()->raw([
                'tags' => $tags->pluck('id')->toArray()
            ])
        );
        $response->assertCreated()
            ->assertJsonPath('data.approval_status', Office::APPROVAL_PENDING)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonCount(2, 'data.tags');
        Notification::assertSentTo($admin, OfficePendingApproval::class);
    }

    public function test_it_not_should_allow_creating_if_scope_is_not_provided()
    {
        $user = User::factory()->createQuietly();
        $token = $user->createToken('test', []);
        $response = $this->postJson('/api/offices', [], [
            'Authorization' => sprintf('Bearer %s', $token->plainTextToken)
        ]);
        $response->assertStatus(403);
    }


    public function test_it_should_updates_an_office()
    {   
        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable $user
         */
        $user = User::factory()->createQuietly();
        $tags = Tag::factory(3)->create(); 
        $anotherTag = Tag::factory()->create(); 
        $office = Office::factory()->for($user)->create();
        $office->tags()->attach($tags);

        Sanctum::actingAs($user, ['*']);
        $response = $this->putJson(sprintf('/api/offices/%s', $office->id),[
            'title' => 'Amazing Office',
            'tags'  => [
                $tags[0]->id,
                $anotherTag->id,
            ]
        ]);
        $response->assertOk()
            ->assertJsonCount(2, 'data.tags')
            ->assertJsonPath('data.tags.0.id', $tags[0]->id)
            ->assertJsonPath('data.tags.1.id', $anotherTag->id)
            ->assertJsonPath('data.title', 'Amazing Office');
    }

    /**
     * @test
    */
    public function itUpdatedTheFeaturedImageOfAnOffice()
    {   
        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable $user
         */
        $user = User::factory()->createQuietly();
        $office = Office::factory()->for($user)->create();
        $image = $office->images()->create([
            'path' => 'image.jpg'
        ]);

        Sanctum::actingAs($user, ['*']);
        $response = $this->putJson(sprintf('/api/offices/%s', $office->id),[
            'featured_image_id' => $image->id
        ]);
        $response->assertOk()
            ->assertJsonPath('data.featured_image_id', $image->id);
    }

    /**
     * @test
    */
    public function itDoesntUpdateFeaturedImageThatBelongsToAnotherOffice()
    {
        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable $user
         */
        $user = User::factory()->createQuietly();
        $office = Office::factory()->for($user)->create();
        $office2 = Office::factory()->for($user)->create();
        $image = $office2->images()->create([
            'path' => 'image.jpg'
        ]);

        Sanctum::actingAs($user, ['*']);
        $response = $this->putJson(sprintf('/api/offices/%s', $office->id),[
            'featured_image_id' => $image->id
        ]);
        $response->assertUnprocessable()
            ->assertInvalid('featured_image_id');
    }

    public function test_it_dont_should_updates_office_that_doesnt_belong_to_user()
    {        
        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable $user
         */
        $user = User::factory()->createQuietly();
        $anotherUser = User::factory()->createQuietly();
        $office = Office::factory()->for($anotherUser)->create();

        Sanctum::actingAs($user, ['*']);
        $response = $this->putJson(sprintf('/api/offices/%s', $office->id),[
            'title' => 'Amazing Office',
        ]);
        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }
    
    public function test_it_should_marks_the_office_as_pending_if_dirty()
    {      
        Notification::fake();  
        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable $user
         */
        $user = User::factory()->createQuietly();
        $admin = User::factory()->create(['is_admin'=>true]);
        $office = Office::factory()->for($user)->create();
        Sanctum::actingAs($user, ['*']);
        $response = $this->putJson(sprintf('/api/offices/%s', $office->id),[
            'price_per_day' => 20_000,
        ]);
        $response->assertOk()
            ->assertJsonPath('data.approval_status', Office::APPROVAL_PENDING);
        Notification::assertSentTo($admin, OfficePendingApproval::class);
    }
    
    /**
     * @test
    */
    public function it_can_delete_office()
    {
        Storage::put('/office_image.jpg', 'empty');

        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable $user
         */
        $user = User::factory()->create(['name'=>'Daniel']);
        $office = Office::factory()->for($user)->create();
        $image = $office->images()->create([
            'path' => 'office_image.jpg'
        ]);
        
        Sanctum::actingAs($user, ['*']);
        $response = $this->deleteJson(sprintf('/api/offices/%s', $office->id));
        $response->assertOk();        
        $this->assertSoftDeleted($office);
        $this->assertModelMissing($image);
        Storage::assertMissing('office_image.jpg');
    }

    /**
     * @test
    */
    public function it_cannot_delete_an_office_that_has_reservations()
    {
        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable $user
         */
        $user = User::factory()->create(['name'=>'Daniel']);
        $office = Office::factory()->for($user)->create();
        Reservation::factory(3)->for($office)->create();
        Sanctum::actingAs($user, ['*']);
        $response = $this->deleteJson(sprintf('/api/offices/%s', $office->id));
        $response->assertStatus(422);
        $this->assertNotSoftDeleted($office);

        // $this->assertModelExists($office);
        // $this->assertDatabaseHas('offices', [
        //     'id'=> $office->id,
        //     'deleted_at' => null,
        // ]);
    }
}
