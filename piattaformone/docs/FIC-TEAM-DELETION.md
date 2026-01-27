# FIC Account Deletion on Team Deletion

## Overview

When a team is deleted, all associated FIC (Fatture in Cloud) accounts are automatically deleted along with their related data.

## Implementation

The deletion logic is implemented in `app/Actions/Jetstream/DeleteTeam.php`.

### What Gets Deleted

When a team is deleted, the following happens in this order:

1. **FIC Accounts** - All `fic_accounts` records where `tenant_id` matches the team ID
2. **FIC Subscriptions** - Automatically deleted via `ON DELETE CASCADE`
3. **FIC Clients** - Automatically deleted via `ON DELETE CASCADE`
4. **FIC Invoices** - Automatically deleted via `ON DELETE CASCADE`
5. **FIC Quotes** - Automatically deleted via `ON DELETE CASCADE`
6. **FIC Suppliers** - Automatically deleted via `ON DELETE CASCADE`
7. **FIC Events** - Automatically deleted via `ON DELETE CASCADE`
8. **Team** - Finally, the team itself is deleted

### Why This Approach?

1. **Data Integrity**: Prevents orphaned FIC accounts in the database
2. **1:1 Relationship**: The system enforces that each FIC company can only be connected to one team
3. **Cascade Benefits**: Once the FIC account is deleted, all related data is automatically cleaned up via database foreign key constraints
4. **Audit Trail**: All deletions are logged for debugging and compliance purposes

## Database Schema

The cascade deletion is implemented using foreign key constraints:

```sql
-- fic_subscriptions
ALTER TABLE fic_subscriptions 
ADD CONSTRAINT fic_subscriptions_fic_account_id_foreign 
FOREIGN KEY (fic_account_id) REFERENCES fic_accounts(id) ON DELETE CASCADE;

-- fic_clients
ALTER TABLE fic_clients 
ADD CONSTRAINT fic_clients_fic_account_id_foreign 
FOREIGN KEY (fic_account_id) REFERENCES fic_accounts(id) ON DELETE CASCADE;

-- Similar constraints exist for fic_invoices, fic_quotes, fic_suppliers, fic_events
```

## Transaction Safety

All deletions happen within a database transaction to ensure:
- Atomicity: Either everything is deleted or nothing is deleted
- Consistency: No partial deletions that could leave the database in an inconsistent state
- Rollback: If any error occurs, all changes are rolled back

## Logging

Each FIC account deletion is logged with the following information:
- Team ID and name
- FIC account ID
- Company ID and name

This creates an audit trail for compliance and debugging purposes.

## Testing

The behavior is covered by the following tests in `tests/Feature/DeleteTeamTest.php`:

1. `test_deleting_team_also_deletes_fic_accounts` - Verifies that deleting a team with a FIC account also deletes the account and all related data
2. `test_deleting_team_with_multiple_fic_accounts_deletes_all` - Verifies that multiple FIC accounts are all deleted when a team is deleted

## Example

```php
// Create a team with FIC accounts
$team = Team::factory()->create();
$ficAccount = FicAccount::factory()->create(['tenant_id' => $team->id]);
$client = FicClient::factory()->create(['fic_account_id' => $ficAccount->id]);

// Delete the team
$team->delete(); // or via the DeleteTeam action

// All of these are now null (deleted):
$team->fresh();      // null
$ficAccount->fresh(); // null
$client->fresh();     // null
```

## Related Files

- `app/Actions/Jetstream/DeleteTeam.php` - Implementation
- `app/Models/Team.php` - Team model with `ficAccounts()` relationship
- `app/Models/FicAccount.php` - FIC account model
- `database/migrations/*_create_fic_*.php` - Database schema with cascade constraints
- `tests/Feature/DeleteTeamTest.php` - Test coverage
