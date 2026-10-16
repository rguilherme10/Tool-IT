<?php

namespace Tests\Feature\Web;

use App\Enums\RoleKey;
use App\Models\EndpointGroup;
use App\Models\FileTransferPolicy;
use App\Models\User;
use App\Services\Transfers\TransferPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Atenção às barras invertidas: nestes testes os caminhos Windows estão sempre em strings de aspas simples
 * com a barra duplicada ('C:\\Pasta' = C:\Pasta).
 */
class FilePoliciesUiTest extends TestCase
{
    private function admin(): User
    {
        return $this->withTwoFactor($this->userWithRole(RoleKey::Admin));
    }

    /** Corpo válido, como o formulário o envia. */
    private function payload(array $override = []): array
    {
        return $override + [
            'group' => '',
            'send_dirs' => 'C:\\ProgramData\\Toolit\\Inbox',
            'fetch_dirs' => implode("\n", ['C:\\ProgramData\\Toolit\\Outbox', 'C:\\ProgramData\\Toolit\\Logs']),
            'allowed_extensions' => 'txt log csv',
            'max_bytes' => '1048576',
            'allow_overwrite' => '0',
        ];
    }

    public function test_only_global_settings_managers_reach_the_screen(): void
    {
        $policy = FileTransferPolicy::factory()->create();

        $scopedAdmin = $this->withTwoFactor($this->userWithRole(RoleKey::Admin, EndpointGroup::factory()->create()));
        $users = [$scopedAdmin];
        foreach ([RoleKey::Operator, RoleKey::Support, RoleKey::Auditor, RoleKey::Viewer] as $role) {
            $users[] = $this->userWithRole($role);
        }

        foreach ($users as $user) {
            $this->actingAs($user)->get('/settings/file-policies')->assertForbidden();
            $this->actingAs($user)->get('/settings/file-policies/create')->assertForbidden();
            $this->actingAs($user)->post('/settings/file-policies', $this->payload())->assertForbidden();
            $this->actingAs($user)->get('/settings/file-policies/'.$policy->uuid.'/edit')->assertForbidden();
            $this->actingAs($user)->put('/settings/file-policies/'.$policy->uuid, $this->payload())->assertForbidden();
            $this->actingAs($user)->delete('/settings/file-policies/'.$policy->uuid, ['confirm' => 1])->assertForbidden();
        }

        $this->assertSame(1, FileTransferPolicy::count());
        $this->assertSame(104857600, $policy->fresh()->max_bytes);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'like', 'transfer_policy.%')->count());
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/settings/file-policies')->assertRedirect('/login');
        $this->post('/settings/file-policies', $this->payload())->assertRedirect('/login');
        $this->assertSame(0, FileTransferPolicy::count());
    }

    public function test_unknown_or_malformed_policy_ids_are_404(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/settings/file-policies/not-a-uuid/edit')->assertNotFound();
        $this->actingAs($admin)->get('/settings/file-policies/'.fake()->uuid().'/edit')->assertNotFound();
        $this->actingAs($admin)->delete('/settings/file-policies/'.fake()->uuid(), ['confirm' => 1])->assertNotFound();
    }

    public function test_create_global_policy_is_saved_normalized_and_audited(): void
    {
        $admin = $this->admin();
        // NFD ("e" + acento combinante) e contrabarra final: gravados em NFC e sem a contrabarra.
        $nfd = "C:\\Dados\\Rela\u{0063}\u{0327}ao";   // c + cedilha combinante
        $nfc = "C:\\Dados\\Rela\u{00E7}ao";

        $this->actingAs($admin)->post('/settings/file-policies', $this->payload([
            'send_dirs' => $nfd.'\\',
            'allowed_extensions' => "PDF, txt\nzip",
            'allow_overwrite' => '1',
        ]))->assertRedirect('/settings/file-policies');

        $policy = FileTransferPolicy::firstOrFail();
        $this->assertNull($policy->endpoint_group_id);
        $this->assertSame('global', $policy->scope_key);
        $this->assertSame([$nfc], $policy->send_dirs);
        $this->assertSame(['C:\\ProgramData\\Toolit\\Outbox', 'C:\\ProgramData\\Toolit\\Logs'], $policy->fetch_dirs);
        $this->assertSame(['pdf', 'txt', 'zip'], $policy->allowed_extensions);
        $this->assertSame(1048576, $policy->max_bytes);
        $this->assertTrue($policy->allow_overwrite);
        $this->assertSame($admin->id, (int) $policy->updated_by);

        $log = DB::table('audit_logs')->where('action', 'transfer_policy.created')->first();
        $this->assertNotNull($log);
        $this->assertSame((string) $admin->id, $log->actor_id);
        $this->assertSame((string) $policy->id, $log->subject_id);
        $metadata = json_decode($log->metadata, true);
        $this->assertSame('global', $metadata['scope']);
        $this->assertSame([$nfc], $metadata['send_dirs']);
        $this->assertSame($policy->fetch_dirs, $metadata['fetch_dirs']);
        $this->assertSame(['pdf', 'txt', 'zip'], $metadata['allowed_extensions']);
        $this->assertSame(1048576, $metadata['max_bytes']);
        $this->assertTrue($metadata['allow_overwrite']);
    }

    public function test_create_group_policy_and_listing_shows_scope(): void
    {
        $admin = $this->admin();
        $group = EndpointGroup::factory()->create(['name' => 'Financeiro']);

        $this->actingAs($admin)->post('/settings/file-policies', $this->payload(['group' => $group->uuid]))
            ->assertRedirect('/settings/file-policies');

        $policy = FileTransferPolicy::firstOrFail();
        $this->assertSame($group->id, (int) $policy->endpoint_group_id);
        $log = DB::table('audit_logs')->where('action', 'transfer_policy.created')->first();
        $this->assertSame('group:'.$group->uuid, json_decode($log->metadata, true)['scope']);

        FileTransferPolicy::factory()->create();   // global
        $this->actingAs($admin)->get('/settings/file-policies')->assertOk()
            ->assertSeeInOrder(['Padrão global', 'Financeiro'])
            ->assertSee('C:\\ProgramData\\Toolit\\Inbox', false);
    }

    public function test_invalid_directories_are_validation_errors_naming_the_line_and_nothing_is_saved(): void
    {
        $admin = $this->admin();
        $bad = [
            'relative' => 'Pasta\\Sub',
            'unc' => '\\\\servidor\\share\\pasta',
            'dotdot' => 'C:\\Dados\\..\\Outra',
            'ads' => 'C:\\Dados\\a:b',
            'reserved' => 'C:\\Dados\\NUL',
            'forward slash' => 'C:/Dados',
            'char' => 'C:\\Dados\\a&b',
            'trailing double' => 'C:\\Dados\\\\',
            // Proibidos pela política (sintaticamente válidos).
            'drive root' => 'C:\\',
            'windows' => 'C:\\Windows',
            'windows sub' => 'C:\\Windows\\System32',
            'program files' => 'C:\\Program Files',
            'program files x86' => 'D:\\Program Files (x86)\\Foo',
            'users' => 'C:\\Users',
        ];

        foreach ($bad as $label => $dir) {
            foreach (['send_dirs', 'fetch_dirs'] as $field) {
                $this->actingAs($admin)->post('/settings/file-policies', $this->payload([
                    $field => "C:\\Dados\\Valido\n".$dir,
                ]))->assertSessionHasErrors($field);
                $errors = session('errors')->get($field);
                $this->assertStringContainsString('linha 2', $errors[0], "sem a linha para {$label}");
            }
        }

        $this->assertSame(0, FileTransferPolicy::count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'like', 'transfer_policy.%')->count());

        // Um subdiretório de "C:\Users" é permitido (só o diretório inteiro é proibido).
        $this->actingAs($admin)->post('/settings/file-policies', $this->payload(['send_dirs' => 'C:\\Users\\Public\\Toolit']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, FileTransferPolicy::count());
    }

    public function test_directory_lists_reject_duplicates_and_more_than_twenty_entries(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/settings/file-policies', $this->payload([
            'send_dirs' => "C:\\Dados\\A\nc:\\dados\\a",
        ]))->assertSessionHasErrors('send_dirs');

        $many = implode("\n", array_map(fn ($i) => 'C:\\Dados\\P'.$i, range(1, 21)));
        $this->actingAs($admin)->post('/settings/file-policies', $this->payload(['fetch_dirs' => $many]))
            ->assertSessionHasErrors('fetch_dirs');

        $twenty = implode("\n", array_map(fn ($i) => 'C:\\Dados\\P'.$i, range(1, 20)));
        $this->actingAs($admin)->post('/settings/file-policies', $this->payload(['fetch_dirs' => $twenty]))
            ->assertSessionHasNoErrors();
        $this->assertCount(20, FileTransferPolicy::firstOrFail()->fetch_dirs);
    }

    public function test_blank_lines_and_surrounding_spaces_are_ignored_and_an_empty_list_disables_that_direction(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/settings/file-policies', $this->payload([
            'send_dirs' => "\n  C:\\Dados\\A  \r\n\r\n",
            'fetch_dirs' => '',
        ]))->assertSessionHasNoErrors();

        $policy = FileTransferPolicy::firstOrFail();
        $this->assertSame(['C:\\Dados\\A'], $policy->send_dirs);
        $this->assertSame([], $policy->fetch_dirs);
    }

    public function test_executable_and_malformed_extensions_are_rejected_never_dropped(): void
    {
        $admin = $this->admin();

        foreach (['exe', 'dll', 'bat', 'ps1', 'js', 'lnk', 'reg', 'sys', 'EXE', 'Ps1'] as $ext) {
            $this->actingAs($admin)->post('/settings/file-policies', $this->payload(['allowed_extensions' => 'txt '.$ext]))
                ->assertSessionHasErrors('allowed_extensions');
        }
        foreach (['.pdf', 'tar.gz', 'a/b', 'ççç', str_repeat('a', 11), 'a-b', "pd\0f"] as $ext) {
            $this->actingAs($admin)->post('/settings/file-policies', $this->payload(['allowed_extensions' => 'txt '.$ext]))
                ->assertSessionHasErrors('allowed_extensions');
        }
        // Com envio habilitado a lista de extensões não pode ficar vazia.
        $this->actingAs($admin)->post('/settings/file-policies', $this->payload(['allowed_extensions' => '']))
            ->assertSessionHasErrors('allowed_extensions');

        $this->assertSame(0, FileTransferPolicy::count());

        // Dez caracteres e números passam; repetidas são unificadas.
        $this->actingAs($admin)->post('/settings/file-policies', $this->payload(['allowed_extensions' => '7z '.str_repeat('a', 10).' 7Z']))
            ->assertSessionHasNoErrors();
        $this->assertSame(['7z', str_repeat('a', 10)], FileTransferPolicy::firstOrFail()->allowed_extensions);
    }

    public function test_extensions_may_be_empty_when_sending_is_disabled(): void
    {
        $this->actingAs($this->admin())->post('/settings/file-policies', $this->payload(['send_dirs' => '', 'allowed_extensions' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame([], FileTransferPolicy::firstOrFail()->allowed_extensions);
    }

    public function test_max_bytes_must_be_between_one_byte_and_one_gib(): void
    {
        $admin = $this->admin();

        foreach (['0', '-1', '1073741825', '9999999999999', '1.5', 'abc', '', '1e3'] as $value) {
            $this->actingAs($admin)->post('/settings/file-policies', $this->payload(['max_bytes' => $value]))
                ->assertSessionHasErrors('max_bytes');
        }
        $this->assertSame(0, FileTransferPolicy::count());

        $this->actingAs($admin)->post('/settings/file-policies', $this->payload(['max_bytes' => '1']))->assertSessionHasNoErrors();
        $this->assertSame(1, FileTransferPolicy::firstOrFail()->max_bytes);

        $group = EndpointGroup::factory()->create();
        $this->actingAs($admin)->post('/settings/file-policies', $this->payload(['group' => $group->uuid, 'max_bytes' => '1073741824']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1073741824, FileTransferPolicy::where('endpoint_group_id', $group->id)->firstOrFail()->max_bytes);
    }

    public function test_unknown_group_is_a_validation_error(): void
    {
        $this->actingAs($this->admin())->post('/settings/file-policies', $this->payload(['group' => fake()->uuid()]))
            ->assertSessionHasErrors('group');
        $this->actingAs($this->admin())->post('/settings/file-policies', $this->payload(['group' => 'not-a-uuid']))
            ->assertSessionHasErrors('group');
        $this->assertSame(0, FileTransferPolicy::count());
    }

    public function test_one_policy_per_scope_duplicate_is_a_validation_error_not_a_500(): void
    {
        $admin = $this->admin();
        $group = EndpointGroup::factory()->create();

        $this->actingAs($admin)->post('/settings/file-policies', $this->payload())->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/settings/file-policies', $this->payload())->assertSessionHasErrors('group');

        $this->actingAs($admin)->post('/settings/file-policies', $this->payload(['group' => $group->uuid]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/settings/file-policies', $this->payload(['group' => $group->uuid]))->assertSessionHasErrors('group');

        $this->assertSame(2, FileTransferPolicy::count());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'transfer_policy.created')->count());
    }

    public function test_service_turns_a_unique_violation_race_into_a_validation_error(): void
    {
        $admin = $this->admin();
        $group = EndpointGroup::factory()->create();
        FileTransferPolicy::factory()->forGroup($group)->create();
        $attributes = ['send_dirs' => [], 'fetch_dirs' => [], 'allowed_extensions' => [], 'max_bytes' => 10, 'allow_overwrite' => false];

        try {
            app(TransferPolicyService::class)->create($admin, $group, $attributes);
            $this->fail('Deveria recusar o escopo duplicado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('group', $e->errors());
        }
        $this->assertSame(1, FileTransferPolicy::count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'transfer_policy.created')->count());
    }

    public function test_update_changes_values_audits_with_before_and_cannot_change_scope(): void
    {
        $admin = $this->admin();
        $group = EndpointGroup::factory()->create();
        $other = EndpointGroup::factory()->create();
        $policy = FileTransferPolicy::factory()->forGroup($group)->create();
        $before = $policy->only(['send_dirs', 'fetch_dirs', 'allowed_extensions', 'max_bytes', 'allow_overwrite']);

        $this->actingAs($admin)->put('/settings/file-policies/'.$policy->uuid, $this->payload([
            'group' => $other->uuid,   // ignorado: o escopo não muda
            'send_dirs' => 'D:\\Troca\\Entrada',
            'allowed_extensions' => 'pdf',
            'max_bytes' => '2048',
            'allow_overwrite' => '1',
        ]))->assertRedirect('/settings/file-policies');

        $policy->refresh();
        $this->assertSame($group->id, (int) $policy->endpoint_group_id);
        $this->assertSame('group:'.$group->id, $policy->scope_key);
        $this->assertSame(['D:\\Troca\\Entrada'], $policy->send_dirs);
        $this->assertSame(['pdf'], $policy->allowed_extensions);
        $this->assertSame(2048, $policy->max_bytes);
        $this->assertTrue($policy->allow_overwrite);
        $this->assertSame($admin->id, (int) $policy->updated_by);
        $this->assertSame(0, FileTransferPolicy::where('endpoint_group_id', $other->id)->count());

        $log = DB::table('audit_logs')->where('action', 'transfer_policy.updated')->first();
        $this->assertNotNull($log);
        $metadata = json_decode($log->metadata, true);
        $this->assertSame('group:'.$group->uuid, $metadata['scope']);
        $this->assertSame(['D:\\Troca\\Entrada'], $metadata['send_dirs']);
        $this->assertSame(2048, $metadata['max_bytes']);
        $this->assertSame($before, $metadata['before']);
    }

    public function test_update_runs_the_same_validation(): void
    {
        $admin = $this->admin();
        $policy = FileTransferPolicy::factory()->create();

        $this->actingAs($admin)->put('/settings/file-policies/'.$policy->uuid, $this->payload(['send_dirs' => 'C:\\Windows']))
            ->assertSessionHasErrors('send_dirs');
        $this->actingAs($admin)->put('/settings/file-policies/'.$policy->uuid, $this->payload(['allowed_extensions' => 'exe']))
            ->assertSessionHasErrors('allowed_extensions');
        $this->actingAs($admin)->put('/settings/file-policies/'.$policy->uuid, $this->payload(['max_bytes' => '0']))
            ->assertSessionHasErrors('max_bytes');

        $this->assertSame(['C:\\ProgramData\\Toolit\\Inbox'], $policy->fresh()->send_dirs);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'transfer_policy.updated')->count());
    }

    public function test_delete_requires_confirmation_removes_and_audits(): void
    {
        $admin = $this->admin();
        $group = EndpointGroup::factory()->create();
        $policy = FileTransferPolicy::factory()->forGroup($group)->create();

        $this->actingAs($admin)->delete('/settings/file-policies/'.$policy->uuid)->assertSessionHasErrors('confirm');
        $this->assertSame(1, FileTransferPolicy::count());

        $this->actingAs($admin)->delete('/settings/file-policies/'.$policy->uuid, ['confirm' => 1])->assertRedirect('/settings/file-policies');

        $this->assertSame(0, FileTransferPolicy::count());
        $log = DB::table('audit_logs')->where('action', 'transfer_policy.deleted')->first();
        $this->assertNotNull($log);
        $this->assertSame((string) $policy->id, $log->subject_id);
        $metadata = json_decode($log->metadata, true);
        $this->assertSame('group:'.$group->uuid, $metadata['scope']);
        $this->assertSame($policy->send_dirs, $metadata['send_dirs']);
        $this->assertSame(104857600, $metadata['max_bytes']);
    }

    public function test_new_form_offers_the_spec_defaults_and_they_pass_validation(): void
    {
        $admin = $this->admin();
        $group = EndpointGroup::factory()->create(['name' => 'Matriz']);

        $page = $this->actingAs($admin)->get('/settings/file-policies/create')->assertOk()
            ->assertSee('C:\\ProgramData\\Toolit\\Inbox', false)
            ->assertSee('C:\\ProgramData\\Toolit\\Outbox', false)
            ->assertSee('C:\\ProgramData\\Toolit\\Logs', false)
            ->assertSee('txt log csv json xml pdf zip 7z png jpg docx xlsx')
            ->assertSee('value="104857600"', false)
            ->assertSee('Padrão global')
            ->assertSee('Matriz');
        $html = $page->getContent();
        $this->assertStringContainsString('value="'.$group->uuid.'"', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="allow_overwrite"[^>]*\bchecked\b/', $html);

        // Submete exatamente o que o formulário traz (textareas decodificadas).
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $fields = [];
        foreach (['send_dirs', 'fetch_dirs', 'allowed_extensions'] as $name) {
            foreach ($dom->getElementsByTagName('textarea') as $textarea) {
                if ($textarea->getAttribute('name') === $name) {
                    $fields[$name] = trim($textarea->textContent);
                }
            }
        }
        $this->assertSame('C:\\ProgramData\\Toolit\\Inbox', $fields['send_dirs']);
        $this->assertSame("C:\\ProgramData\\Toolit\\Outbox\nC:\\ProgramData\\Toolit\\Logs", str_replace("\r", '', $fields['fetch_dirs']));

        $this->actingAs($admin)->post('/settings/file-policies', $fields + ['group' => '', 'max_bytes' => '104857600', 'allow_overwrite' => '0'])
            ->assertSessionHasNoErrors()->assertRedirect('/settings/file-policies');

        $policy = FileTransferPolicy::firstOrFail();
        $this->assertSame(['C:\\ProgramData\\Toolit\\Inbox'], $policy->send_dirs);
        $this->assertSame(['C:\\ProgramData\\Toolit\\Outbox', 'C:\\ProgramData\\Toolit\\Logs'], $policy->fetch_dirs);
        $this->assertSame(['txt', 'log', 'csv', 'json', 'xml', 'pdf', 'zip', '7z', 'png', 'jpg', 'docx', 'xlsx'], $policy->allowed_extensions);
        $this->assertFalse($policy->allow_overwrite);
    }

    public function test_form_repopulates_with_old_input_after_an_error(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->from('/settings/file-policies/create')
            ->post('/settings/file-policies', $this->payload(['send_dirs' => 'C:\\Windows', 'max_bytes' => '12345']))
            ->assertRedirect('/settings/file-policies/create');

        $this->actingAs($admin)->get('/settings/file-policies/create')->assertOk()
            ->assertSee('C:\\Windows', false)
            ->assertSee('value="12345"', false)
            ->assertSee('linha 1');
    }

    public function test_edit_form_shows_values_and_a_fixed_scope(): void
    {
        $admin = $this->admin();
        $group = EndpointGroup::factory()->create(['name' => 'Financeiro']);
        $policy = FileTransferPolicy::factory()->forGroup($group)->create(['max_bytes' => 5000]);

        $html = $this->actingAs($admin)->get('/settings/file-policies/'.$policy->uuid.'/edit')->assertOk()
            ->assertSee('Financeiro')
            ->assertSee('value="5000"', false)
            ->assertSee('C:\\ProgramData\\Toolit\\Outbox', false)
            ->getContent();
        $this->assertStringNotContainsString('<select name="group"', $html);
    }

    public function test_output_is_escaped(): void
    {
        $admin = $this->admin();
        $group = EndpointGroup::factory()->create(['name' => '<script>alert(1)</script>']);
        // Gravado direto (como se viesse de uma versão antiga): a tela não pode confiar no conteúdo.
        $policy = FileTransferPolicy::factory()->forGroup($group)->create([
            'send_dirs' => ['C:\\<img src=x onerror=alert(2)>'],
            'allowed_extensions' => ['<b>x</b>'],
        ]);

        foreach (['/settings/file-policies', '/settings/file-policies/'.$policy->uuid.'/edit'] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringNotContainsString('<b>x</b>', $html);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        }
        $this->actingAs($admin)->get('/settings/file-policies/create')->assertOk()->assertDontSee('<script>alert(1)', false);
    }

    public function test_pages_use_no_inline_scripts_or_styles(): void
    {
        $admin = $this->admin();
        $policy = FileTransferPolicy::factory()->create();

        foreach (['/settings/file-policies', '/settings/file-policies/create', '/settings/file-policies/'.$policy->uuid.'/edit'] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/i', $html);
            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
        }
    }

    public function test_empty_listing_explains_that_transfers_are_off_without_a_policy(): void
    {
        $this->actingAs($this->admin())->get('/settings/file-policies')->assertOk()
            ->assertSee('Sem política de transferência');
    }

    public function test_navigation_link_only_for_global_settings_managers(): void
    {
        $this->actingAs($this->admin())->get('/')->assertOk()->assertSee(route('file-policies.index'), false);

        $operator = $this->userWithRole(RoleKey::Operator);
        $this->actingAs($operator)->get('/')->assertOk()->assertDontSee(route('file-policies.index'), false);

        $scopedAdmin = $this->withTwoFactor($this->userWithRole(RoleKey::Admin, EndpointGroup::factory()->create()));
        $this->actingAs($scopedAdmin)->get('/')->assertOk()->assertDontSee(route('file-policies.index'), false);
    }
}
