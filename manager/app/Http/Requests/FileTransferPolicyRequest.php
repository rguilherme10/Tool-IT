<?php

namespace App\Http\Requests;

use App\Models\EndpointGroup;
use App\Models\FileTransferPolicy;
use App\Support\Transfers\PathGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Criação/edição de política de transferência. Autoriza `settings.manage` (global) e valida CADA diretório com o
 * PathGuard + a lista de diretórios proibidos em política; as listas chegam como texto (uma entrada por linha) e
 * saem normalizadas em policyAttributes().
 */
class FileTransferPolicyRequest extends FormRequest
{
    public const MAX_DIRECTORIES = 20;

    public const MAX_EXTENSIONS = 50;

    public const MAX_BYTES_LIMIT = 1073741824;   // 1 GiB

    /** @var array{send_dirs: list<string>, fetch_dirs: list<string>, allowed_extensions: list<string>} */
    private array $lists = ['send_dirs' => [], 'fetch_dirs' => [], 'allowed_extensions' => []];

    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;   // permissão de sistema: só escopo global
    }

    public function rules(): array
    {
        return [
            // Na edição o escopo é fixo: o campo é ignorado.
            'group' => $this->isMethod('POST') ? ['nullable', 'string', 'uuid', 'exists:endpoint_groups,uuid'] : ['nullable'],
            'send_dirs' => ['nullable', 'string', 'max:20000'],
            'fetch_dirs' => ['nullable', 'string', 'max:20000'],
            'allowed_extensions' => ['nullable', 'string', 'max:2000'],
            'max_bytes' => ['required', 'integer', 'between:1,'.self::MAX_BYTES_LIMIT],
            'allow_overwrite' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['max_bytes' => 'tamanho máximo', 'group' => 'escopo'];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $this->lists['send_dirs'] = $this->parseDirectories('send_dirs', 'Diretórios de envio', $validator);
            $this->lists['fetch_dirs'] = $this->parseDirectories('fetch_dirs', 'Diretórios de busca', $validator);
            $this->lists['allowed_extensions'] = $this->parseExtensions($validator);

            if ($this->lists['send_dirs'] !== [] && $this->lists['allowed_extensions'] === [] && ! $validator->errors()->has('allowed_extensions')) {
                $validator->errors()->add('allowed_extensions', 'Informe ao menos uma extensão permitida, ou deixe a lista de diretórios de envio vazia.');
            }

            if ($this->isMethod('POST') && ! $validator->errors()->has('group')
                && FileTransferPolicy::where('scope_key', FileTransferPolicy::scopeKeyFor($this->scopeGroup()?->id))->exists()) {
                $validator->errors()->add('group', 'Já existe uma política para este escopo. Edite a existente.');
            }
        }];
    }

    /** Grupo do escopo escolhido (só na criação); null = padrão global. */
    public function scopeGroup(): ?EndpointGroup
    {
        $uuid = $this->input('group');

        return is_string($uuid) && $uuid !== '' ? EndpointGroup::where('uuid', $uuid)->first() : null;
    }

    /**
     * Valores validados e normalizados, prontos para o TransferPolicyService.
     *
     * @return array{send_dirs: list<string>, fetch_dirs: list<string>, allowed_extensions: list<string>, max_bytes: int, allow_overwrite: bool}
     */
    public function policyAttributes(): array
    {
        return $this->lists + [
            'max_bytes' => (int) $this->validated('max_bytes'),
            'allow_overwrite' => $this->boolean('allow_overwrite'),
        ];
    }

    /** @return list<string> */
    private function parseDirectories(string $field, string $label, Validator $validator): array
    {
        $raw = $this->input($field);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        // Numeração pelas linhas do campo (inclusive as em branco), para o erro apontar a linha certa.
        $entries = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $index => $line) {
            $line = trim($line, " \t");
            if ($line !== '') {
                $entries[$index + 1] = $line;
            }
        }
        if (count($entries) > self::MAX_DIRECTORIES) {
            $validator->errors()->add($field, "{$label}: no máximo ".self::MAX_DIRECTORIES.' diretórios.');

            return [];
        }

        $accepted = [];
        $seen = [];
        foreach ($entries as $number => $entry) {
            // Uma contrabarra final é só descuido de quem digita; o PathGuard a recusaria.
            $dir = strlen($entry) > 3 && str_ends_with($entry, '\\') ? substr($entry, 0, -1) : $entry;

            $reason = $this->directoryProblem($dir);
            if ($reason === null) {
                $dir = PathGuard::normalizeName($dir);
                $reason = $this->directoryProblem($dir);   // a forma NFC também precisa passar
            }
            if ($reason !== null) {
                $validator->errors()->add($field, "{$label}, linha {$number} (".Str::limit(mb_scrub($entry), 60).'): '.$reason);

                continue;
            }

            $key = strtr($dir, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');   // dobra só ASCII, como o PathGuard
            if (isset($seen[$key])) {
                $validator->errors()->add($field, "{$label}, linha {$number}: diretório repetido (já consta na linha {$seen[$key]}).");

                continue;
            }
            $seen[$key] = $number;
            $accepted[] = $dir;
        }

        return $accepted;
    }

    /** Motivo (em português) pelo qual o diretório não pode entrar numa política, ou null. */
    private function directoryProblem(string $dir): ?string
    {
        if (($code = PathGuard::directoryReason($dir)) !== null) {
            return PathGuard::message($code);
        }
        if (PathGuard::forbiddenPolicyDirectory($dir)) {
            return 'diretório proibido em política (raiz de unidade, C:\\Windows, C:\\Program Files* ou C:\\Users inteiro).';
        }

        return null;
    }

    /** @return list<string> */
    private function parseExtensions(Validator $validator): array
    {
        $raw = $this->input('allowed_extensions');
        $tokens = is_string($raw) ? (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];
        $tokens = array_values(array_unique(array_map('strtolower', $tokens)));   // strtolower: só ASCII
        if (count($tokens) > self::MAX_EXTENSIONS) {
            $validator->errors()->add('allowed_extensions', 'No máximo '.self::MAX_EXTENSIONS.' extensões.');

            return [];
        }

        $accepted = [];
        foreach ($tokens as $token) {
            $shown = Str::limit(mb_scrub($token), 20);
            if (preg_match('/^[a-z0-9]{1,10}$/D', $token) !== 1) {
                $validator->errors()->add('allowed_extensions', "Extensão “{$shown}” inválida: use 1 a 10 letras minúsculas ou números, sem ponto.");
            } elseif (in_array($token, PathGuard::BLOCKED_EXTENSIONS, true)) {
                $validator->errors()->add('allowed_extensions', "A extensão “{$token}” é executável ou script e nunca pode ser transferida.");
            } else {
                $accepted[] = $token;
            }
        }

        return $accepted;
    }
}
