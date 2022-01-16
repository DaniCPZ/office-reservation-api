<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Office;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

class OfficeImageControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @test
    */
    public function itShouldUploadsImageAnImageAndStoresItUnderTheOffice()
    {
        Storage::fake('public');
        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable $user
         */
        $user = User::factory()->create();
        $office = Office::factory()->for($user)->create();
        Sanctum::actingAs($user, ['*']);
        $response = $this->post(
            sprintf('/api/offices/%s/images', $office->id),
            [
              'image' => UploadedFile::fake()->image('image.jpg')
            ]
        );
        $response->assertCreated();
        Storage::assertExists(
            $response->json('data.path')
        );
    }

    /**
     * @test
    */
    public function itDeletesAnImages()
    {
        Storage::put('/office_image.jpg', 'empty');

        $user = User::factory()->create();
        $office = Office::factory()->for($user)->create();
        $office->images()->create([
            'path' => 'image.jpg'
        ]);
        $image = $office->images()->create([
            'path' => 'office_image.jpg'
        ]);
        Sanctum::actingAs($user, ['*']);
        $response = $this->deleteJson("/api/offices/{$office->id}/images/{$image->id}");

        $response->assertOk();
        $this->assertModelMissing($image);
        Storage::assertMissing('office_image.jpg');
    }

    /**
     * @test
    */
    public function itDoesntDeleteTheOnlyImage()
    {
        $user = User::factory()->create();
        $office = Office::factory()->for($user)->create();
        $image = $office->images()->create([
            'path' => 'office_image.jpg'
        ]);
        Sanctum::actingAs($user, ['*']);
        $response = $this->deleteJson("/api/offices/{$office->id}/images/{$image->id}");

        $response->assertUnprocessable();
    }

    /**
     * @test
     */
    public function itDoesntDeleteImageThatBelongsToAnotherResource()
    {
        $user = User::factory()->create();
        $office = Office::factory()->for($user)->create();
        $office2 = Office::factory()->for($user)->create();
        $image = $office->images()->create([
            'path' => 'office_image.jpg'
        ]);
        Sanctum::actingAs($user, ['*']);
        $response = $this->deleteJson("/api/offices/{$office2->id}/images/{$image->id}");

        $response->assertNotFound();
    }

    /**
     * @test
    */
    public function itDoesntDeleteTheFeaturedImage()
    {
        $user = User::factory()->create();
        $office = Office::factory()->for($user)->create();
        $office->images()->create([
            'path' => 'office_image.jpg'
        ]);
        
        $image = $office->images()->create([
            'path' => 'office_image.jpg'
        ]);
        $office->update([
            'featured_image_id' => $image->id
        ]);
        
        Sanctum::actingAs($user, ['*']);
        $response = $this->deleteJson("/api/offices/{$office->id}/images/{$image->id}");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['image' => 'Cannot delete the featured image.']);
    }
}
