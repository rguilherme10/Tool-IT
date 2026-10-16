/*
 * Envio de arquivo em partes (chunks) com retomada — aba "Arquivos" do computador (task 009).
 *
 * Arquivo estático, sem dependências e sem build (o `npm run build` não roda no share de rede).
 * A CSP do Manager só aceita script do próprio domínio: não há JS inline na página; a configuração chega
 * por atributos `data-*` do formulário e o token CSRF pela <meta name="csrf-token">.
 *
 * Fluxo: POST transfers.store -> (PUT transfers.upload por parte, até 3 tentativas em falha de rede/5xx)
 *        -> POST transfers.upload.complete. Para retomar, GET transfers.upload.status devolve as partes já
 *        recebidas e só as que faltam são enviadas.
 *
 * Nunca registra o conteúdo do arquivo (nem nada em console); textos da tela só entram por textContent.
 * O servidor é a autoridade (política, extensão, tamanho, caminho): as checagens daqui são só conforto.
 */
(function () {
    'use strict';

    var MAX_ATTEMPTS = 3;          // tentativas por requisição (1 + 2 repetições)
    var BACKOFF_BASE_MS = 500;     // 500 ms, 1 s, 2 s...
    var COMPLETE_ROUNDS = 3;       // conclusão -> reenvio das partes que o servidor disse que faltam -> conclusão

    // ---------------------------------------------------------------------------------------------
    // Funções puras (testadas em tests/js/transfer-upload.test.mjs)
    // ---------------------------------------------------------------------------------------------

    /** Quantidade de partes de um arquivo de `size` bytes. */
    function chunkCount(size, chunkSize) {
        if (!(size > 0) || !(chunkSize > 0)) {
            return 0;
        }
        return Math.ceil(size / chunkSize);
    }

    /** Intervalo [start, end) em bytes da parte `index`; a última parte leva o resto. */
    function chunkRange(index, size, chunkSize) {
        var start = index * chunkSize;
        return { start: start, end: Math.min(start + chunkSize, size) };
    }

    /** Índices (crescentes) de 0..total-1 que ainda não foram recebidos. */
    function pendingChunks(total, received) {
        var have = {};
        (received || []).forEach(function (n) { have[n] = true; });
        var out = [];
        for (var i = 0; i < total; i++) {
            if (!have[i]) {
                out.push(i);
            }
        }
        return out;
    }

    /** Falha de rede (status 0) ou 5xx pode repetir; 4xx nunca (o servidor recusou de propósito). */
    function isRetryable(status) {
        return status === 0 || status >= 500;
    }

    function shouldRetry(status, attempt, maxAttempts) {
        return isRetryable(status) && attempt < (maxAttempts || MAX_ATTEMPTS);
    }

    /** Espera antes da tentativa seguinte, depois da tentativa `attempt` (1, 2, ...). */
    function backoffMs(attempt) {
        return BACKOFF_BASE_MS * Math.pow(2, Math.max(0, attempt - 1));
    }

    /** Primeira mensagem de validação do Laravel ({errors: {campo: [msg]}}), se houver. */
    function firstValidationMessage(body) {
        if (!body || typeof body.errors !== 'object' || body.errors === null) {
            return null;
        }
        var keys = Object.keys(body.errors);
        for (var i = 0; i < keys.length; i++) {
            var list = body.errors[keys[i]];
            if (Array.isArray(list) && typeof list[0] === 'string' && list[0] !== '') {
                return list[0];
            }
        }
        return null;
    }

    /** Mensagem em português para o operador a partir do status HTTP e do corpo JSON do servidor. */
    function messageFor(status, body) {
        if (status === 0) {
            return 'Sem conexão com o servidor. Verifique a rede; o envio pode ser retomado.';
        }
        if (status === 401 || status === 419) {
            return 'Sua sessão expirou. Entre de novo, abra esta aba e retome o envio.';
        }
        if (status >= 500) {
            return 'O servidor não conseguiu processar o pedido (erro ' + status + '). Tente de novo; o envio pode ser retomado.';
        }
        var validation = firstValidationMessage(body);
        if (validation) {
            return validation;
        }
        if (body && typeof body.message === 'string' && body.message !== '') {
            return body.message;
        }
        switch (status) {
            case 403: return 'Você não tem permissão para esta operação.';
            case 404: return 'Transferência não encontrada: ela pode ter sido cancelada ou removida.';
            case 409: return 'A transferência não está em um estado que aceite esta operação.';
            case 413: return 'Parte do arquivo grande demais para o servidor.';
            case 415: return 'O servidor recusou o tipo de conteúdo da parte.';
            case 422: return 'O servidor recusou os dados enviados.';
            case 429: return 'Muitas requisições em pouco tempo. Aguarde um instante e tente de novo.';
            default: return 'Erro inesperado (' + status + ').';
        }
    }

    /** 1536 -> "1,5 KiB". */
    function formatBytes(n) {
        if (!(n >= 1024)) {
            return String(n || 0) + ' B';
        }
        var units = ['KiB', 'MiB', 'GiB', 'TiB'];
        var value = n / 1024;
        var i = 0;
        while (value >= 1024 && i < units.length - 1) {
            value /= 1024;
            i++;
        }
        return value.toFixed(1).replace(/\.0$/, '').replace('.', ',') + ' ' + units[i];
    }

    /** Troca o UUID de marcação do modelo de URL pelo UUID da transferência. */
    function idUrl(template, placeholder, id) {
        return template.split(placeholder).join(id);
    }

    /** O modelo da parte termina em `/0`; troca pelo índice da parte. */
    function chunkUrl(template, placeholder, id, n) {
        return idUrl(template, placeholder, id).replace(/\/0$/, '/' + n);
    }

    /** O arquivo escolhido é o mesmo do envio a retomar (nome e tamanho)? */
    function matchesResume(file, resume) {
        return !!file && !!resume && file.name === resume.name && file.size === resume.size;
    }

    function percent(done, total) {
        return total > 0 ? Math.min(100, Math.floor((done * 100) / total)) : 0;
    }

    // ---------------------------------------------------------------------------------------------
    // Erros e cliente HTTP (com repetição)
    // ---------------------------------------------------------------------------------------------

    function UploadError(message, status, code, body) {
        this.name = 'UploadError';
        this.message = message;
        this.status = status || 0;
        this.code = code || null;
        this.body = body || null;
    }
    UploadError.prototype = Object.create(Error.prototype);
    UploadError.prototype.constructor = UploadError;

    function UploadCancelled() {
        this.name = 'UploadCancelled';
        this.message = 'Envio cancelado.';
    }
    UploadCancelled.prototype = Object.create(Error.prototype);
    UploadCancelled.prototype.constructor = UploadCancelled;

    function defaultSleep(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function readJson(response) {
        return response.json().catch(function () { return null; });
    }

    /**
     * Cliente das rotas de transferência. `fetchImpl` e `sleep` são injetáveis (testes).
     * Sempre pede JSON (Accept) para que sessão expirada vire 401/419 em vez de redirecionar para o login.
     */
    function createClient(options) {
        var fetchImpl = options.fetchImpl;
        var sleep = options.sleep || defaultSleep;
        var csrf = options.csrf || '';

        async function request(method, url, init) {
            init = init || {};
            var headers = {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest'
            };
            if (init.contentType) {
                headers['Content-Type'] = init.contentType;
            }

            for (var attempt = 1; ; attempt++) {
                var status = 0;
                var body = null;
                try {
                    var response = await fetchImpl(url, {
                        method: method,
                        headers: headers,
                        body: init.body,
                        credentials: 'same-origin',
                        signal: init.signal
                    });
                    status = response.status;
                    body = await readJson(response);
                } catch (e) {
                    if (e && e.name === 'AbortError') {
                        throw e;
                    }
                    status = 0;   // falha de rede
                }

                if (status >= 200 && status < 300) {
                    if (body === null) {
                        throw new UploadError('Resposta inesperada do servidor. Sua sessão pode ter expirado; recarregue a página.', status);
                    }
                    return { status: status, body: body };
                }
                if (shouldRetry(status, attempt, MAX_ATTEMPTS)) {
                    await sleep(backoffMs(attempt));
                    continue;
                }
                throw new UploadError(messageFor(status, body), status, body && body.code, body);
            }
        }

        return { request: request };
    }

    // ---------------------------------------------------------------------------------------------
    // Fluxo do envio (sem DOM: recebe tudo por parâmetro)
    // ---------------------------------------------------------------------------------------------

    /**
     * @param {object} o
     *   client      createClient(...)
     *   urls        {store, status, chunk, complete, placeholder}
     *   file        File/Blob com `name` e `size` (a leitura é por slice, parte a parte)
     *   dir, subName  destino (só para envio novo)
     *   resume      {transferId, name, size} para retomar um envio em andamento, ou null
     *   maxBytes    teto da política (só conforto; o servidor decide)
     *   onCreated   function(info)   envio criado: {transferId, chunkSize, chunksTotal}
     *   onProgress  function(done, total, bytesDone)
     *   onStage     function(texto)
     *   shouldStop  function(): boolean   cancelamento pedido pelo operador
     *   signal      AbortSignal opcional
     * @returns {Promise<{transfer_id, status, sha256}>}
     */
    async function uploadFile(o) {
        var client = o.client;
        var urls = o.urls;
        var file = o.file;
        var shouldStop = o.shouldStop || function () { return false; };
        var onProgress = o.onProgress || function () {};
        var onStage = o.onStage || function () {};

        if (!file || !(file.size > 0)) {
            throw new UploadError('O arquivo está vazio ou não foi escolhido.');
        }

        var transferId;
        var chunkSize;
        var total;
        var received;

        if (o.resume) {
            if (!matchesResume(file, o.resume)) {
                throw new UploadError('Este não é o arquivo do envio a retomar. Escolha «' + o.resume.name + '» (' + formatBytes(o.resume.size) + ').');
            }
            transferId = o.resume.transferId;
            onStage('Consultando as partes já recebidas…');
            var st = (await client.request('GET', idUrl(urls.status, urls.placeholder, transferId), { signal: o.signal })).body;
            if (st.status !== 'uploading') {
                throw new UploadError('Este envio não está mais em andamento (estado: ' + st.status + ').', 409, 'transfer.conflict');
            }
            chunkSize = Number(st.chunk_size);
            total = Number(st.chunks_total);
            received = Array.isArray(st.received_chunks) ? st.received_chunks.slice() : [];
            if (chunkCount(file.size, chunkSize) !== total) {
                throw new UploadError('O arquivo escolhido não tem o mesmo tamanho do envio a retomar.');
            }
        } else {
            if (o.maxBytes && file.size > o.maxBytes) {
                throw new UploadError('O arquivo (' + formatBytes(file.size) + ') passa do limite da política (' + formatBytes(o.maxBytes) + ').');
            }
            onStage('Iniciando o envio…');
            var payload = { name: file.name, size: file.size, dir: o.dir };
            if (o.subName) {
                payload.sub_name = o.subName;
            }
            var created = (await client.request('POST', urls.store, {
                body: JSON.stringify(payload),
                contentType: 'application/json',
                signal: o.signal
            })).body;
            transferId = created.transfer_id;
            chunkSize = Number(created.chunk_size);
            total = Number(created.chunks_total);
            received = [];
            if (!transferId || !(chunkSize > 0) || chunkCount(file.size, chunkSize) !== total) {
                throw new UploadError('Resposta inesperada do servidor ao iniciar o envio.');
            }
            if (o.onCreated) {
                o.onCreated({ transferId: transferId, chunkSize: chunkSize, chunksTotal: total });
            }
        }

        var done = {};
        received.forEach(function (n) { done[n] = true; });
        var doneCount = function () { return Object.keys(done).length; };

        async function sendParts(list) {
            for (var i = 0; i < list.length; i++) {
                if (shouldStop()) {
                    throw new UploadCancelled();
                }
                var n = list[i];
                var range = chunkRange(n, file.size, chunkSize);
                var res = await client.request('PUT', chunkUrl(urls.chunk, urls.placeholder, transferId, n), {
                    body: file.slice(range.start, range.end),
                    contentType: 'application/octet-stream',
                    signal: o.signal
                });
                done[n] = true;
                if (res.body && Array.isArray(res.body.received_chunks)) {
                    res.body.received_chunks.forEach(function (k) { done[k] = true; });
                }
                onProgress(doneCount(), total, Math.min(file.size, doneCount() * chunkSize));
            }
        }

        onProgress(doneCount(), total, Math.min(file.size, doneCount() * chunkSize));
        onStage('Enviando…');
        await sendParts(pendingChunks(total, Object.keys(done).map(Number)));

        for (var round = 1; ; round++) {
            if (shouldStop()) {
                throw new UploadCancelled();
            }
            onStage('Conferindo o arquivo no servidor…');
            try {
                return (await client.request('POST', idUrl(urls.complete, urls.placeholder, transferId), { signal: o.signal })).body;
            } catch (e) {
                // O servidor diz exatamente quais partes faltam: reenvia só elas e tenta concluir de novo.
                if (e instanceof UploadError && e.code === 'transfer.incomplete' && e.body
                    && Array.isArray(e.body.missing_chunks) && round < COMPLETE_ROUNDS) {
                    onStage('Reenviando partes que faltaram…');
                    await sendParts(e.body.missing_chunks.map(Number));
                    continue;
                }
                throw e;
            }
        }
    }

    var api = {
        MAX_ATTEMPTS: MAX_ATTEMPTS,
        chunkCount: chunkCount,
        chunkRange: chunkRange,
        pendingChunks: pendingChunks,
        isRetryable: isRetryable,
        shouldRetry: shouldRetry,
        backoffMs: backoffMs,
        messageFor: messageFor,
        formatBytes: formatBytes,
        idUrl: idUrl,
        chunkUrl: chunkUrl,
        matchesResume: matchesResume,
        percent: percent,
        UploadError: UploadError,
        UploadCancelled: UploadCancelled,
        createClient: createClient,
        uploadFile: uploadFile
    };

    // Fora do navegador (testes em Node) só expõe as funções; nada de DOM.
    if (typeof document === 'undefined') {
        globalThis.__transferUpload = api;
        return;
    }

    // ---------------------------------------------------------------------------------------------
    // Tela
    // ---------------------------------------------------------------------------------------------

    function init(form) {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var csrf = meta ? meta.getAttribute('content') : '';
        var ds = form.dataset;
        var urls = {
            store: ds.storeUrl,
            status: ds.statusUrl,
            chunk: ds.chunkUrl,
            complete: ds.completeUrl,
            cancel: ds.cancelUrl,
            placeholder: ds.idPlaceholder
        };
        var maxBytes = Number(ds.maxBytes) || 0;

        var el = {
            dir: form.elements.namedItem('dir'),
            file: form.elements.namedItem('file'),
            subName: form.elements.namedItem('sub_name'),
            submit: form.querySelector('[data-upload-submit]'),
            cancel: form.querySelector('[data-upload-cancel]'),
            reset: form.querySelector('[data-upload-reset]'),
            bar: form.querySelector('[data-upload-bar]'),
            barBox: form.querySelector('[data-upload-progress]'),
            status: form.querySelector('[data-upload-status]'),
            error: form.querySelector('[data-upload-error]'),
            note: form.querySelector('[data-resume-note]')
        };

        var client = createClient({ fetchImpl: window.fetch.bind(window), csrf: csrf });
        var state = { busy: false, stop: false, controller: null, resume: null, created: null };

        function show(node, visible) {
            node.hidden = !visible;
        }

        function setText(node, text) {
            node.textContent = text;
            show(node, text !== '');
        }

        function setBusy(busy) {
            state.busy = busy;
            var controls = form.querySelectorAll('input, select, button');
            for (var i = 0; i < controls.length; i++) {
                controls[i].disabled = busy && controls[i] !== el.cancel;
            }
            document.querySelectorAll('[data-resume-transfer]').forEach(function (b) { b.disabled = busy; });
            show(el.cancel, busy);
            if (busy) {
                show(el.reset, false);
            }
            form.setAttribute('aria-busy', busy ? 'true' : 'false');
        }

        function setResume(info) {
            state.resume = info;
            if (info) {
                setText(el.note, 'Retomar «' + info.name + '» (' + formatBytes(info.size) + '): escolha o mesmo arquivo e clique em Enviar; só as partes que faltam serão enviadas.');
                show(el.reset, true);
            } else {
                setText(el.note, '');
                show(el.reset, false);
            }
        }

        function progress(done, total, bytes) {
            show(el.barBox, true);
            el.bar.value = percent(done, total);
            setText(el.status, 'Enviando… ' + done + ' de ' + total + ' partes (' + percent(done, total) + '%)');
        }

        async function start() {
            if (state.busy) {
                return;
            }
            setText(el.error, '');
            var file = el.file.files && el.file.files[0];
            if (!file) {
                setText(el.error, 'Escolha o arquivo a enviar.');
                return;
            }
            if (!state.resume && !el.dir.value) {
                setText(el.error, 'Escolha a pasta de destino.');
                return;
            }
            if (state.resume && !matchesResume(file, state.resume)) {
                setText(el.error, 'Este não é o arquivo do envio a retomar. Escolha «' + state.resume.name + '» (' + formatBytes(state.resume.size) + ') ou clique em "Começar outro envio".');
                return;
            }

            state.stop = false;
            state.controller = typeof AbortController === 'function' ? new AbortController() : null;
            var wasResume = state.resume;
            setBusy(true);
            show(el.barBox, true);
            el.bar.value = 0;

            try {
                var result = await uploadFile({
                    client: client,
                    urls: urls,
                    file: file,
                    dir: el.dir.value,
                    subName: el.subName.value.trim(),
                    resume: wasResume,
                    maxBytes: maxBytes,
                    onCreated: function (info) {
                        state.created = info;
                        setResume({ transferId: info.transferId, name: file.name, size: file.size });
                    },
                    onProgress: progress,
                    onStage: function (text) { setText(el.status, text); },
                    shouldStop: function () { return state.stop; },
                    signal: state.controller ? state.controller.signal : undefined
                });
                el.bar.value = 100;
                setResume(null);
                state.busy = false;   // os controles seguem desabilitados até recarregar; sem isso o beforeunload avisaria no reload
                setText(el.status, 'Envio concluído (SHA-256 ' + String(result.sha256 || '').slice(0, 12) + '…). O agente recebe o arquivo no próximo contato. Atualizando a lista…');
                window.setTimeout(function () { window.location.reload(); }, 900);
            } catch (e) {
                await onFailure(e);
            }
        }

        async function onFailure(e) {
            var cancelled = state.stop || e instanceof UploadCancelled || (e && e.name === 'AbortError');
            var id = state.resume ? state.resume.transferId : null;

            if (cancelled) {
                setText(el.status, '');
                if (id) {
                    try {
                        await client.request('POST', idUrl(urls.cancel, urls.placeholder, id));
                        setText(el.status, 'Envio cancelado.');
                    } catch (ce) {
                        setText(el.error, 'Não foi possível cancelar o envio no servidor: ' + ce.message + ' Use "Cancelar" na lista abaixo.');
                    }
                } else {
                    setText(el.status, 'Envio cancelado.');
                }
                setResume(null);
            } else {
                setText(el.status, '');
                var message = e instanceof UploadError ? e.message : 'Erro inesperado durante o envio.';
                // Conflito/permissão/inexistente: não adianta retomar este envio.
                if (e instanceof UploadError && (e.status === 403 || e.status === 404 || e.status === 409)) {
                    setResume(null);
                } else if (state.resume) {
                    message += ' O envio foi guardado: escolha o mesmo arquivo e clique em Enviar para continuar de onde parou.';
                }
                setText(el.error, message);
            }
            state.controller = null;
            setBusy(false);
            show(el.barBox, false);
            if (state.resume) {
                show(el.reset, true);
            }
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            start();
        });

        el.cancel.addEventListener('click', function () {
            state.stop = true;
            setText(el.status, 'Cancelando…');
            if (state.controller) {
                state.controller.abort();
            }
        });

        el.reset.addEventListener('click', function () {
            setResume(null);
            setText(el.error, '');
            setText(el.status, '');
            el.file.value = '';
        });

        document.querySelectorAll('[data-resume-transfer]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (state.busy) {
                    return;
                }
                var d = button.dataset;
                setText(el.error, '');
                setResume({ transferId: d.resumeTransfer, name: d.name, size: Number(d.size) });
                form.scrollIntoView({ block: 'center' });
                el.file.focus();
            });
        });

        // Fechar a aba no meio do envio perde só o que estiver em trânsito; o aviso evita o susto.
        window.addEventListener('beforeunload', function (event) {
            if (state.busy) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        setBusy(false);
    }

    function boot() {
        var form = document.querySelector('form[data-transfer-upload]');
        if (form) {
            init(form);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
