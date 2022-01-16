<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class TagControllerTest extends TestCase
{
    public function test_it_should_list_tags()
    {
        $response = $this->get('/api/tags');
        $response->assertStatus(200);
        $this->assertNotNull($response->json('data')[0]['id']);
    	// $this->assertCount(3, $response->json('data'));
    }
}
