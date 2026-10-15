<?php

namespace Tests\Feature\Web;

use App\Enums\RoleKey;
use App\Models\AlertRule;
use App\Models\EndpointGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupsUiTest extends TestCase
{
    private function admin(): User
    {
        return $this->withTwoFactor($this->userWithRole(RoleKey::Admin));
    }

    public function test_only_global_settings_managers_manage_groups(): void
    {
        $group = EndpointGroup::factory()->create();

        $scopedAdmin = $this->withTwoFactor($this->userWithRole(RoleKey::Admin, EndpointGroup::factory()->create()));
        foreach ([RoleKey::Operator, RoleKey::Support, RoleKey::Auditor, RoleKey::Viewer] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->get('/groups')->assertForbidden();
            $this->actingAs($user)->post('/groups', ['name' => 'x'])->assertForbidden();
            $this->actingAs($user)->put('/groups/'.$group->uuid, ['name' => 'x'])->assertForbidden();
            $this->actingAs($user)->delete('/groups/'.$group->uuid, ['confirm' => 1])->assertForbidden();
        }
        $this->actingAs($scopedAdmin)->get('/groups')->assertForbidden();
        $this->assertSame(2, EndpointGroup::count());
    }

    public function test_create_with_parent_is_audited_and_listed_as_a_tree(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/groups', ['name' => 'Matriz'])->assertRedirect('/groups');
        $parent = EndpointGroup::where('name', 'Matriz')->firstOrFail();

        $this->actingAs($admin)->post('/groups', ['name' => 'Financeiro', 'description' => 'Contas', 'parent' => $parent->uuid])->assertRedirect('/groups');

        $child = EndpointGroup::where('name', 'Financeiro')->firstOrFail();
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'group.created', 'actor_id' => (string) $admin->id]);
        $this->actingAs($admin)->get('/groups')->assertOk()->assertSeeInOrder(['Matriz', '—', 'Financeiro']);
    }

    public function test_validation_and_sibling_name_uniqueness(): void
    {
        $admin = $this->admin();
        $parent = EndpointGroup::factory()->create(['name' => 'Matriz']);
        EndpointGroup::factory()->create(['name' => 'Financeiro', 'parent_id' => $parent->id]);

        $this->actingAs($admin)->post('/groups', ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/groups', ['name' => "a\x00b"])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/groups', ['name' => str_repeat('a', 121)])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/groups', ['name' => 'X', 'parent' => fake()->uuid()])->assertSessionHasErrors('parent');
        $this->actingAs($admin)->post('/groups', ['name' => 'Financeiro', 'parent' => $parent->uuid])->assertSessionHasErrors('name');   // irmão igual

        // O mesmo nome em outro nível é permitido.
        $this->actingAs($admin)->post('/groups', ['name' => 'Financeiro'])->assertRedirect('/groups');
    }

    public function test_a_group_cannot_become_its_own_ancestor(): void
    {
        $admin = $this->admin();
        $a = EndpointGroup::factory()->create();
        $b = EndpointGroup::factory()->create(['parent_id' => $a->id]);
        $c = EndpointGroup::factory()->create(['parent_id' => $b->id]);

        $this->actingAs($admin)->put('/groups/'.$a->uuid, ['name' => $a->name, 'parent' => $a->uuid])->assertSessionHasErrors('parent');
        $this->actingAs($admin)->put('/groups/'.$a->uuid, ['name' => $a->name, 'parent' => $c->uuid])->assertSessionHasErrors('parent');
        $this->assertNull($a->fresh()->parent_id);

        // A tela de edição nem oferece o grupo e seus descendentes como pai.
        $page = $this->actingAs($admin)->get('/groups/'.$a->uuid.'/edit')->assertOk()->getContent();
        $this->assertStringNotContainsString('value="'.$c->uuid.'"', $page);
        $this->assertStringNotContainsString('value="'.$b->uuid.'"', $page);
    }

    public function test_update_records_before_and_after(): void
    {
        $admin = $this->admin();
        $group = EndpointGroup::factory()->create(['name' => 'Antes']);

        $this->actingAs($admin)->put('/groups/'.$group->uuid, ['name' => 'Depois'])->assertRedirect('/groups');

        $this->assertSame('Depois', $group->fresh()->name);
        $log = DB::table('audit_logs')->where('action', 'group.updated')->first();
        $this->assertStringContainsString('Antes', $log->metadata);
        $this->assertStringContainsString('Depois', $log->metadata);
    }

    public function test_deleting_requires_confirmation_and_an_empty_group(): void
    {
        $admin = $this->admin();
        $busy = EndpointGroup::factory()->create();
        $this->activeEndpoint($busy);
        EndpointGroup::factory()->create(['parent_id' => $busy->id]);
        $this->userWithRole(RoleKey::Viewer, $busy);
        AlertRule::factory()->create(['endpoint_group_id' => $busy->id]);

        $response = $this->actingAs($admin)->delete('/groups/'.$busy->uuid, ['confirm' => 1]);
        $response->assertSessionHasErrors('confirm');
        $message = session('errors')->first('confirm');
        foreach (['1 computadores', '1 subgrupos', '1 atribuições de papel', '1 regras de alerta'] as $part) {
            $this->assertStringContainsString($part, $message);
        }
        $this->assertDatabaseHas('endpoint_groups', ['id' => $busy->id]);

        $empty = EndpointGroup::factory()->create();
        $this->actingAs($admin)->delete('/groups/'.$empty->uuid)->assertSessionHasErrors('confirm');   // sem confirmar
        $this->actingAs($admin)->delete('/groups/'.$empty->uuid, ['confirm' => 1])->assertRedirect('/groups');
        $this->assertDatabaseMissing('endpoint_groups', ['id' => $empty->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'group.deleted']);
    }

    public function test_group_ids_in_urls_are_uuids(): void
    {
        $group = EndpointGroup::factory()->create();

        $this->actingAs($this->admin())->get('/groups/'.$group->id.'/edit')->assertNotFound();
    }

    public function test_group_name_is_escaped(): void
    {
        EndpointGroup::factory()->create(['name' => '<script>alert(1)</script>']);

        $html = $this->actingAs($this->admin())->get('/groups')->getContent();

        $this->assertStringNotContainsString('<script>alert', $html);
    }
}
