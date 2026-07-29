<?php

namespace Tests\Feature;

use App\Http\Middleware\ValidateBearerToken;
use App\Models\Gratitude\GratitudeTermCondition;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GratitudeTermConditionTest extends TestCase
{
    use RefreshDatabase;

    public function test_terms_and_conditions_page_is_available(): void
    {
        $this->withoutVite();

        $this->actingAs(User::factory()->create())
            ->get('/gratitude/terms-conditions')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page->component('Gratitude/TermsConditions')
            );
    }

    public function test_terms_and_conditions_can_be_created_listed_deactivated_and_deleted(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $user = User::factory()->create();

        $createResponse = $this->actingAs($user)
            ->postJson('/internal-api/gratitude/terms-conditions', [
                'title' => 'Redemptions',
                'content' => 'Redemption requests must be submitted within 15 days.',
                'status' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('term.title', 'Redemptions')
            ->assertJsonPath('term.status', true);

        $termId = $createResponse->json('term.id');

        $this->actingAs($user)
            ->getJson('/internal-api/gratitude/terms-conditions')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $termId);

        $this->actingAs($user)
            ->patchJson("/internal-api/gratitude/terms-conditions/{$termId}/status", [
                'status' => false,
            ])
            ->assertOk()
            ->assertJsonPath('term.status', false);

        $this->assertDatabaseHas('gratitude_terms_conditions', [
            'id' => $termId,
            'status' => false,
        ]);

        $this->actingAs($user)
            ->deleteJson("/internal-api/gratitude/terms-conditions/{$termId}")
            ->assertOk();

        $this->assertDatabaseMissing('gratitude_terms_conditions', [
            'id' => $termId,
        ]);
    }

    public function test_external_api_only_returns_active_terms_in_display_order(): void
    {
        $this->withoutMiddleware(ValidateBearerToken::class);

        GratitudeTermCondition::create([
            'content' => 'Second active term.',
            'status' => true,
            'sort_order' => 2,
        ]);
        GratitudeTermCondition::create([
            'content' => 'Inactive term.',
            'status' => false,
            'sort_order' => 1,
        ]);
        GratitudeTermCondition::create([
            'content' => 'First active term.',
            'status' => true,
            'sort_order' => 1,
        ]);

        $this->getJson('/api/v1/gratitude/terms-conditions')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.content', 'First active term.')
            ->assertJsonPath('1.content', 'Second active term.');
    }

    public function test_external_api_can_create_update_and_delete_terms(): void
    {
        $this->withoutMiddleware(ValidateBearerToken::class);

        $createResponse = $this->postJson('/api/v1/gratitude/terms-conditions', [
            'title' => 'Redemption rules',
            'content' => 'Points may be redeemed on eligible journeys.',
            'status' => true,
            'sort_order' => 5,
        ])
            ->assertCreated()
            ->assertJsonPath('term.title', 'Redemption rules');

        $termId = $createResponse->json('term.id');

        $this->putJson("/api/v1/gratitude/terms-conditions/{$termId}", [
            'content' => 'Points may only be redeemed on eligible journeys.',
            'status' => false,
        ])
            ->assertOk()
            ->assertJsonPath('term.content', 'Points may only be redeemed on eligible journeys.')
            ->assertJsonPath('term.status', false);

        $this->deleteJson("/api/v1/gratitude/terms-conditions/{$termId}")
            ->assertOk();

        $this->assertDatabaseMissing('gratitude_terms_conditions', [
            'id' => $termId,
        ]);
    }

    public function test_benefits_import_synchronizes_fineprint_without_resetting_status(): void
    {
        $user = User::factory()->create();
        $developerRole = Role::firstOrCreate([
            'name' => 'Developer',
            'guard_name' => 'web',
        ]);
        $user->assignRole($developerRole);

        Http::fake([
            'https://artinvoyage.com/wp-json/api/all-gratitude-benefits' => Http::sequence()
                ->push([
                    'benefits' => [],
                    'fineprint_list' => [
                        ['fineprint_item' => 'Points are calculated on a land basis only.'],
                        ['fineprint_item' => 'International flights are excluded.'],
                    ],
                ])
                ->push([
                    'benefits' => [],
                    'fineprint_list' => [
                        ['fineprint_item' => 'Updated land-basis condition.'],
                        ['fineprint_item' => 'International flights are excluded.'],
                    ],
                ]),
        ]);

        $this->actingAs($user)
            ->getJson('/internal-api/gratitude/migrate-benefits/data')
            ->assertOk()
            ->assertJsonPath('terms_imported', 2);

        $firstTerm = GratitudeTermCondition::where(
            'source_key',
            'art-in-voyage-fineprint-1'
        )->firstOrFail();
        $firstTerm->update(['status' => false]);

        $this->actingAs($user)
            ->getJson('/internal-api/gratitude/migrate-benefits/data')
            ->assertOk()
            ->assertJsonPath('terms_imported', 2);

        $this->assertDatabaseCount('gratitude_terms_conditions', 2);
        $this->assertDatabaseHas('gratitude_terms_conditions', [
            'source_key' => 'art-in-voyage-fineprint-1',
            'content' => 'Updated land-basis condition.',
            'status' => false,
        ]);
    }
}
