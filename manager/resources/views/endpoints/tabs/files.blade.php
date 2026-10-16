@php
    use App\Enums\TransferDirection;
    use App\Enums\TransferStatus;
    use App\Support\Transfers\TransferDisplay;

    $userId = (int) auth()->id();
    // Marcador de posição das rotas com {transfer}: o JS troca pelo UUID da transferência (a rota exige um UUID válido).
    $nilId = '00000000-0000-0000-0000-000000000000';
    $sendOn = $maySend && $hasPolicy && count($sendDirs) > 0;
    $fetchOn = $mayFetch && $hasPolicy && count($fetchDirs) > 0;

    $stateClass = [
        'uploading' => 'bg-sky-100 text-sky-800',
        'ready' => 'bg-sky-100 text-sky-800',
        'transferring' => 'bg-indigo-100 text-indigo-800',
        'verifying' => 'bg-indigo-100 text-indigo-800',
        'completed' => 'bg-emerald-100 text-emerald-800',
        'failed' => 'bg-red-100 text-red-800',
        'cancelled' => 'bg-slate-200 text-slate-700',
        'expired' => 'bg-slate-200 text-slate-700',
    ];
@endphp

@if (! $hasPolicy)
    <div role="note" class="mb-4 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
        <strong class="font-medium">Sem política de transferência.</strong>
        Envio e recuperação de arquivos estão desligados para este computador até que um administrador defina uma política para o grupo dele.
    </div>
@endif

<div class="mb-6 grid gap-4 lg:grid-cols-2">
    @if ($permSend)
        <section class="rounded-lg border border-slate-200 bg-white p-4" aria-labelledby="enviar-arquivo">
            <h2 id="enviar-arquivo" class="mb-1 text-base font-semibold">Enviar arquivo</h2>
            <p class="mb-3 text-xs text-slate-500">Do seu computador para uma pasta permitida deste computador. O arquivo só é gravado no destino: nada é aberto nem iniciado.</p>

            @if ($sendOn)
                <form data-transfer-upload novalidate class="flex flex-col gap-3"
                      data-store-url="{{ route('transfers.store', ['uuid' => $endpoint->uuid]) }}"
                      data-status-url="{{ route('transfers.upload.status', ['transfer' => $nilId]) }}"
                      data-chunk-url="{{ route('transfers.upload', ['transfer' => $nilId, 'n' => 0]) }}"
                      data-complete-url="{{ route('transfers.upload.complete', ['transfer' => $nilId]) }}"
                      data-cancel-url="{{ route('transfers.cancel', ['transfer' => $nilId]) }}"
                      data-id-placeholder="{{ $nilId }}"
                      data-chunk-size="{{ $chunkBytes }}"
                      data-max-bytes="{{ $maxBytes }}">
                    <label class="text-sm">Pasta de destino
                        <select name="dir" required class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                            @foreach ($sendDirs as $dir)
                                <option value="{{ $dir }}">{{ $dir }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm">Arquivo
                        <input type="file" name="file" required class="mt-1 block w-full text-sm">
                    </label>
                    <label class="text-sm">Nome no destino <span class="text-slate-500">(opcional)</span>
                        <input type="text" name="sub_name" maxlength="255" autocomplete="off" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                    </label>

                    <p class="text-xs text-slate-500">
                        Tamanho máximo: {{ TransferDisplay::bytes($maxBytes) }}.
                        @if (count($allowedExtensions) > 0)
                            Extensões permitidas:
                            @foreach ($allowedExtensions as $ext)<code class="mr-1 rounded bg-slate-100 px-1">.{{ $ext }}</code>@endforeach
                        @else
                            Nenhuma extensão liberada pela política: o servidor vai recusar o envio.
                        @endif
                        {{ $allowOverwrite ? 'Um arquivo de mesmo nome no destino será substituído.' : 'Um arquivo de mesmo nome no destino não é substituído.' }}
                        Programas e scripts são sempre recusados.
                    </p>

                    <p data-resume-note hidden class="rounded border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900"></p>

                    <div data-upload-progress hidden>
                        <progress data-upload-bar max="100" value="0" class="h-3 w-full" aria-label="Progresso do envio"></progress>
                    </div>
                    <p data-upload-status role="status" aria-live="polite" hidden class="text-sm text-slate-700"></p>
                    <p data-upload-error role="alert" hidden class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"></p>

                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" data-upload-submit class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700 disabled:opacity-50">Enviar</button>
                        <button type="button" data-upload-cancel hidden class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100">Cancelar envio</button>
                        <button type="button" data-upload-reset hidden class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100">Começar outro envio</button>
                    </div>
                    <noscript><p class="text-sm text-red-800">O envio de arquivos precisa de JavaScript ativado.</p></noscript>
                </form>
                <script src="{{ asset('js/transfer-upload.js') }}" defer></script>
            @elseif (! $hasPolicy)
                <p class="text-sm text-slate-600">Sem política de transferência: envio desligado.</p>
            @elseif (count($sendDirs) === 0)
                <p class="text-sm text-slate-600">Envio desligado: a política deste computador não tem pasta de destino.</p>
            @else
                <p class="text-sm text-slate-600">Você não pode criar tarefas neste computador.</p>
            @endif
        </section>
    @endif

    @if ($permFetch)
        <section class="rounded-lg border border-slate-200 bg-white p-4" aria-labelledby="recuperar-arquivo">
            <h2 id="recuperar-arquivo" class="mb-1 text-base font-semibold">Recuperar arquivo</h2>
            <p class="mb-3 text-xs text-slate-500">Do computador para você: o agente envia o arquivo no próximo contato e você baixa aqui depois de concluído.</p>

            @if ($fetchOn)
                <form method="POST" action="{{ route('transfers.fetch', ['uuid' => $endpoint->uuid]) }}" class="flex flex-col gap-3">
                    @csrf
                    <label class="text-sm">Caminho do arquivo no computador
                        <input type="text" name="path" required maxlength="400" autocomplete="off" value="{{ old('path') }}"
                               placeholder="{{ $fetchDirs[0] }}\arquivo.log" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 font-mono text-sm">
                    </label>
                    <p class="text-xs text-slate-500">
                        Pastas permitidas:
                        @foreach ($fetchDirs as $dir)<code class="mr-1 rounded bg-slate-100 px-1 break-all">{{ $dir }}</code>@endforeach
                        Tamanho máximo: {{ TransferDisplay::bytes($maxBytes) }}.
                    </p>
                    <div>
                        <button class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Recuperar</button>
                    </div>
                </form>
            @elseif (! $hasPolicy)
                <p class="text-sm text-slate-600">Sem política de transferência: recuperação desligada.</p>
            @elseif (count($fetchDirs) === 0)
                <p class="text-sm text-slate-600">Recuperação desligada: a política deste computador não tem pasta de origem.</p>
            @else
                <p class="text-sm text-slate-600">Você não pode criar tarefas neste computador.</p>
            @endif
        </section>
    @endif
</div>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
                <th class="px-3 py-2 font-medium">Arquivo</th><th class="px-3 py-2 font-medium">Estado</th>
                <th class="px-3 py-2 font-medium">Tamanho</th><th class="px-3 py-2 font-medium">SHA-256</th>
                <th class="px-3 py-2 font-medium">Operador</th><th class="px-3 py-2 font-medium">Datas (UTC)</th>
                <th class="relative px-3 py-2 font-medium"><span class="sr-only">Ações</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($transfers as $t)
                @php
                    $isSend = $t->direction === TransferDirection::Send;
                    // Rascunho de envio: só o criador cancela (como no serviço); depois da tarefa vale permissão da direção + tasks.cancel.
                    $mayCancel = ! $t->status->isFinal() && ($isSend && $t->status === TransferStatus::Uploading
                        ? $permSend && (int) $t->created_by === $userId
                        : ($isSend ? $permSend : $permFetch) && $permCancel);
                    $mayDownload = ! $isSend && $permFetch && $t->status === TransferStatus::Completed;
                    $mayResume = $isSend && $t->status === TransferStatus::Uploading && (int) $t->created_by === $userId && $sendOn;
                @endphp
                <tr data-transfer-row>
                    <td class="px-3 py-2">
                        <div class="font-medium break-all">{{ $t->original_name }}</div>
                        <div class="text-xs text-slate-500">{{ $t->direction->label() }} · <span class="font-mono break-all">{{ $t->remote_path }}</span></div>
                    </td>
                    <td class="px-3 py-2">
                        <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs {{ $stateClass[$t->status->value] }}">{{ $t->status->label() }}</span>
                        @if ($t->status === TransferStatus::Uploading)
                            <div class="mt-1 text-xs text-slate-500">{{ (int) $t->chunks_received }} de {{ (int) $t->chunks_total }} partes</div>
                        @endif
                        @if ($t->error)
                            <div class="mt-1 text-xs break-words text-slate-500">{{ $t->error }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ TransferDisplay::bytes((int) $t->size) }}</td>
                    <td class="px-3 py-2 font-mono text-xs text-slate-600">
                        @if ($t->sha256)
                            <span title="{{ $t->sha256 }}">{{ TransferDisplay::shortHash($t->sha256) }}</span>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ $t->creator?->name ?? '—' }}</td>
                    <td class="px-3 py-2 text-xs whitespace-nowrap text-slate-600">
                        <div>Criada {{ $t->created_at->format('d/m/Y H:i') }}</div>
                        <div>Atualizada {{ $t->updated_at->format('d/m/Y H:i') }}</div>
                    </td>
                    <td class="px-3 py-2">
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @if ($mayDownload && $t->purged_at === null)
                                <a href="{{ route('transfers.download', ['transfer' => $t->uuid]) }}" class="rounded bg-slate-900 px-2 py-1 text-xs text-white hover:bg-slate-700">Baixar</a>
                            @elseif ($mayDownload)
                                <span class="text-xs text-slate-500">Arquivo apagado pela retenção</span>
                            @endif
                            @if ($mayResume)
                                <button type="button" data-resume-transfer="{{ $t->uuid }}" data-name="{{ $t->original_name }}"
                                        data-size="{{ (int) $t->size }}" data-chunks-total="{{ (int) $t->chunks_total }}"
                                        data-chunk-size="{{ (int) $t->chunk_size }}"
                                        class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100">Retomar</button>
                            @endif
                            @if ($mayCancel)
                                <form method="POST" action="{{ route('transfers.cancel', ['transfer' => $t->uuid]) }}">@csrf
                                    <button class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100">Cancelar</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-8 text-center text-slate-500">Nenhuma transferência para este computador.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $transfers->links() }}</div>
<p class="mt-3 text-xs text-slate-500">O agente consulta tarefas a cada cerca de 10 segundos. As transferências são conferidas por SHA-256; os arquivos ficam guardados por {{ (int) config('toolit.transfers.retention_days', 7) }} dias e os registros permanecem.</p>
