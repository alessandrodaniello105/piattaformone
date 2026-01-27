<?php

namespace Tests\Feature;

use App\Models\FicAccount;
use App\Models\FicClient;
use App\Models\FicInvoice;
use App\Models\FicSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_teams_can_be_deleted(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $user->ownedTeams()->save($team = Team::factory()->make([
            'personal_team' => false,
        ]));

        $team->users()->attach(
            $otherUser = User::factory()->create(), ['role' => 'test-role']
        );

        $this->delete('/teams/'.$team->id);

        $this->assertNull($team->fresh());
        $this->assertCount(0, $otherUser->fresh()->teams);
    }

    public function test_personal_teams_cant_be_deleted(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $this->delete('/teams/'.$user->currentTeam->id);

        $this->assertNotNull($user->currentTeam->fresh());
    }

    public function test_deleting_team_also_deletes_fic_accounts(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $user->ownedTeams()->save($team = Team::factory()->make([
            'personal_team' => false,
        ]));

        // Create a FIC account for this team
        $ficAccount = FicAccount::factory()->create([
            'tenant_id' => $team->id,
            'company_id' => 12345,
            'company_name' => 'Test Company',
        ]);

        // Create some related data
        $subscription = FicSubscription::factory()->create([
            'fic_account_id' => $ficAccount->id,
        ]);

        $client = FicClient::factory()->create([
            'fic_account_id' => $ficAccount->id,
        ]);

        $invoice = FicInvoice::factory()->create([
            'fic_account_id' => $ficAccount->id,
        ]);

        // Delete the team
        $this->delete('/teams/'.$team->id);

        // Assert team is deleted
        $this->assertNull($team->fresh());

        // Assert FIC account is deleted
        $this->assertNull($ficAccount->fresh());

        // Assert related data is also deleted (cascade)
        $this->assertNull($subscription->fresh());
        $this->assertNull($client->fresh());
        $this->assertNull($invoice->fresh());
    }

    public function test_deleting_team_with_multiple_fic_accounts_deletes_all(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $user->ownedTeams()->save($team = Team::factory()->make([
            'personal_team' => false,
        ]));

        // Create multiple FIC accounts for this team
        $ficAccount1 = FicAccount::factory()->create([
            'tenant_id' => $team->id,
            'company_id' => 11111,
            'company_name' => 'Company 1',
        ]);

        $ficAccount2 = FicAccount::factory()->create([
            'tenant_id' => $team->id,
            'company_id' => 22222,
            'company_name' => 'Company 2',
        ]);

        // Delete the team
        $this->delete('/teams/'.$team->id);

        // Assert team is deleted
        $this->assertNull($team->fresh());

        // Assert both FIC accounts are deleted
        $this->assertNull($ficAccount1->fresh());
        $this->assertNull($ficAccount2->fresh());
    }
}
