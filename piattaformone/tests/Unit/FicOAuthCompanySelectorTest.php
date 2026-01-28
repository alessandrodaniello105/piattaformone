<?php

namespace Tests\Unit;

use App\Models\FicAccount;
use App\Services\FicOAuthCompanySelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FicOAuthCompanySelectorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Empty normalized list throws.
     */
    public function test_select_throws_when_normalized_empty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Company ID not found in API response');

        FicOAuthCompanySelector::select([], 5);
    }

    /**
     * No existing fic_account for team: returns first company.
     */
    public function test_select_returns_first_company_when_no_existing_account(): void
    {
        $normalized = [
            ['id' => 1543167, 'name' => 'Company A'],
            ['id' => 1550348, 'name' => 'Company B'],
        ];

        $result = FicOAuthCompanySelector::select($normalized, 5);

        $this->assertSame(1543167, $result['id']);
        $this->assertSame('Company A', $result['name']);
    }

    /**
     * Existing fic_account for team, company in list: returns that company (reconnect).
     */
    public function test_select_uses_existing_team_company_when_in_list(): void
    {
        FicAccount::factory()->create([
            'company_id' => 1550348,
            'company_name' => 'Company B',
            'tenant_id' => '5',
        ]);

        $normalized = [
            ['id' => 1543167, 'name' => 'Company A'],
            ['id' => 1550348, 'name' => 'Company B'],
        ];

        $result = FicOAuthCompanySelector::select($normalized, 5);

        $this->assertSame(1550348, $result['id']);
        $this->assertSame('Company B', $result['name']);
    }

    /**
     * Existing fic_account for team, company NOT in list: throws clear error.
     */
    public function test_select_throws_when_team_company_not_in_list(): void
    {
        FicAccount::factory()->create([
            'company_id' => 1550348,
            'company_name' => 'Company B',
            'tenant_id' => '5',
        ]);

        $normalized = [
            ['id' => 1543167, 'name' => 'Company A'],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Hai autorizzato con un account Fatture in Cloud che non include l'azienda di questo team");
        $this->expectExceptionMessage('attesa: ID 1550348');
        $this->expectExceptionMessage('1550348');
        $this->expectExceptionMessage('1543167');

        FicOAuthCompanySelector::select($normalized, 5);
    }

    /**
     * tenant_id null, no existing account: returns first company.
     */
    public function test_select_returns_first_when_tenant_null(): void
    {
        $normalized = [
            ['id' => 1543167, 'name' => 'Company A'],
        ];

        $result = FicOAuthCompanySelector::select($normalized, null);

        $this->assertSame(1543167, $result['id']);
        $this->assertSame('Company A', $result['name']);
    }
}
