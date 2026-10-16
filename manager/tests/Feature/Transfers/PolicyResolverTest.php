<?php

namespace Tests\Feature\Transfers;

use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Models\FileTransferPolicy;
use App\Services\Transfers\TransferPolicyResolver;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class PolicyResolverTest extends TestCase
{
    private function resolve(Endpoint $endpoint): ?FileTransferPolicy
    {
        return app(TransferPolicyResolver::class)->forEndpoint($endpoint);
    }

    private function endpointIn(?EndpointGroup $group): Endpoint
    {
        return Endpoint::factory()->create(['endpoint_group_id' => $group?->id]);
    }

    public function test_no_policy_anywhere_means_transfers_are_off(): void
    {
        $group = EndpointGroup::factory()->create();

        $this->assertNull($this->resolve($this->endpointIn($group)));
        $this->assertNull($this->resolve($this->endpointIn(null)));
    }

    public function test_global_policy_is_the_fallback(): void
    {
        $global = FileTransferPolicy::factory()->global()->create();
        $group = EndpointGroup::factory()->create();

        $this->assertTrue($global->is($this->resolve($this->endpointIn($group))));
        $this->assertTrue($global->is($this->resolve($this->endpointIn(null))));
    }

    public function test_group_policy_wins_over_global(): void
    {
        FileTransferPolicy::factory()->global()->create();
        $group = EndpointGroup::factory()->create();
        $own = FileTransferPolicy::factory()->forGroup($group)->create();

        $this->assertTrue($own->is($this->resolve($this->endpointIn($group))));
    }

    public function test_nearest_ancestor_with_a_policy_is_used(): void
    {
        $root = EndpointGroup::factory()->create();
        $mid = EndpointGroup::factory()->create(['parent_id' => $root->id]);
        $leaf = EndpointGroup::factory()->create(['parent_id' => $mid->id]);

        FileTransferPolicy::factory()->global()->create();
        $rootPolicy = FileTransferPolicy::factory()->forGroup($root)->create();

        $this->assertTrue($rootPolicy->is($this->resolve($this->endpointIn($leaf))));

        $midPolicy = FileTransferPolicy::factory()->forGroup($mid)->create();
        $this->assertTrue($midPolicy->is($this->resolve($this->endpointIn($leaf))));
        $this->assertTrue($midPolicy->is($this->resolve($this->endpointIn($mid))));
        $this->assertTrue($rootPolicy->is($this->resolve($this->endpointIn($root))));

        $leafPolicy = FileTransferPolicy::factory()->forGroup($leaf)->create();
        $this->assertTrue($leafPolicy->is($this->resolve($this->endpointIn($leaf))));
    }

    public function test_policy_of_another_branch_is_ignored(): void
    {
        $root = EndpointGroup::factory()->create();
        $a = EndpointGroup::factory()->create(['parent_id' => $root->id]);
        $b = EndpointGroup::factory()->create(['parent_id' => $root->id]);
        $child = EndpointGroup::factory()->create(['parent_id' => $b->id]);

        FileTransferPolicy::factory()->forGroup($a)->create();
        FileTransferPolicy::factory()->forGroup($child)->create();   // descendente não vale para o ancestral

        $this->assertNull($this->resolve($this->endpointIn($b)));
        $this->assertNull($this->resolve($this->endpointIn($root)));
        $this->assertNull($this->resolve($this->endpointIn(EndpointGroup::factory()->create())));
    }

    public function test_endpoint_without_group_only_sees_the_global_policy(): void
    {
        $group = EndpointGroup::factory()->create();
        FileTransferPolicy::factory()->forGroup($group)->create();

        $this->assertNull($this->resolve($this->endpointIn(null)));
    }

    public function test_cyclic_group_tree_does_not_loop_forever(): void
    {
        $a = EndpointGroup::factory()->create();
        $b = EndpointGroup::factory()->create(['parent_id' => $a->id]);
        EndpointGroup::query()->whereKey($a->id)->update(['parent_id' => $b->id]);

        $this->assertNull($this->resolve($this->endpointIn($b->fresh())));

        $global = FileTransferPolicy::factory()->global()->create();
        $this->assertTrue($global->is($this->resolve($this->endpointIn($b->fresh()))));
    }

    public function test_scope_key_is_derived_and_unique(): void
    {
        $group = EndpointGroup::factory()->create();

        $this->assertSame('global', FileTransferPolicy::factory()->global()->create()->scope_key);
        $this->assertSame('group:'.$group->id, FileTransferPolicy::factory()->forGroup($group)->create()->scope_key);

        $this->expectException(QueryException::class);
        FileTransferPolicy::factory()->forGroup($group)->create();
    }

    public function test_second_global_policy_is_refused(): void
    {
        FileTransferPolicy::factory()->global()->create();

        $this->expectException(QueryException::class);
        FileTransferPolicy::factory()->global()->create();
    }

    public function test_scope_key_follows_the_group_when_it_changes(): void
    {
        $group = EndpointGroup::factory()->create();
        $policy = FileTransferPolicy::factory()->global()->create();

        $policy->forceFill(['endpoint_group_id' => $group->id])->save();

        $this->assertSame('group:'.$group->id, $policy->fresh()->scope_key);
    }

    public function test_policy_defaults_and_casts(): void
    {
        $policy = FileTransferPolicy::factory()->global()->create();
        $policy = $policy->fresh();

        $this->assertIsArray($policy->send_dirs);
        $this->assertIsArray($policy->fetch_dirs);
        $this->assertIsArray($policy->allowed_extensions);
        $this->assertSame(104857600, $policy->max_bytes);
        $this->assertFalse($policy->allow_overwrite);
        $this->assertNotEmpty($policy->uuid);
        $this->assertSame($policy->uuid, $policy->getRouteKey());
    }

    public function test_policy_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);
        FileTransferPolicy::create(['send_dirs' => ['C:\\x'], 'max_bytes' => 1]);
    }

    public function test_deleting_a_group_removes_its_policy_instead_of_turning_it_global(): void
    {
        $group = EndpointGroup::factory()->create();
        FileTransferPolicy::factory()->forGroup($group)->create();

        $group->delete();

        $this->assertSame(0, FileTransferPolicy::query()->count());
    }

    public function test_config_block_has_the_agreed_defaults(): void
    {
        $this->assertSame(7, config('toolit.transfers.retention_days'));
        $this->assertSame(104857600, config('toolit.transfers.max_bytes'));
        $this->assertSame(1048576, config('toolit.transfers.browser_chunk_bytes'));
        $this->assertSame(2097152, config('toolit.transfers.agent_chunk_bytes'));
    }
}
