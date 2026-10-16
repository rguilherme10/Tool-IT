// Testes do envio em partes (public/js/transfer-upload.js) com o runner embutido do Node:
//   node --test manager/tests/js/transfer-upload.test.mjs
// Sem dependências e sem navegador: o script, fora do navegador (sem `document`), só expõe as funções em
// globalThis.__transferUpload. A parte de tela (DOM) NÃO é coberta aqui — ela é conferida no navegador.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const scriptPath = fileURLToPath(new URL('../../public/js/transfer-upload.js', import.meta.url));
vm.runInThisContext(readFileSync(scriptPath, 'utf8'), { filename: scriptPath });
const u = globalThis.__transferUpload;

const NIL = '00000000-0000-0000-0000-000000000000';
const ID = '11111111-2222-3333-4444-555555555555';
const urls = {
    store: '/endpoints/e1/transfers',
    status: `/transfers/${NIL}/upload`,
    chunk: `/transfers/${NIL}/upload/0`,
    complete: `/transfers/${NIL}/upload/complete`,
    cancel: `/transfers/${NIL}/cancel`,
    placeholder: NIL,
};

// --- funções puras ------------------------------------------------------------------------------

test('chunkCount e chunkRange cobrem o arquivo inteiro sem sobra nem lacuna', () => {
    assert.equal(u.chunkCount(0, 1024), 0);
    assert.equal(u.chunkCount(1, 1024), 1);
    assert.equal(u.chunkCount(1024, 1024), 1);
    assert.equal(u.chunkCount(1025, 1024), 2);
    assert.equal(u.chunkCount(2500, 1024), 3);

    for (const [size, chunk] of [[1, 1024], [1024, 1024], [2048, 1024], [2500, 1024], [10_485_761, 1_048_576]]) {
        const total = u.chunkCount(size, chunk);
        let expectedStart = 0;
        for (let i = 0; i < total; i++) {
            const { start, end } = u.chunkRange(i, size, chunk);
            assert.equal(start, expectedStart);
            assert.ok(end > start && end - start <= chunk);
            expectedStart = end;
        }
        assert.equal(expectedStart, size);
    }
    assert.deepEqual(u.chunkRange(2, 2500, 1024), { start: 2048, end: 2500 });
});

test('pendingChunks devolve só o que falta, em ordem', () => {
    assert.deepEqual(u.pendingChunks(4, []), [0, 1, 2, 3]);
    assert.deepEqual(u.pendingChunks(4, [2, 0]), [1, 3]);
    assert.deepEqual(u.pendingChunks(3, [0, 1, 2]), []);
    assert.deepEqual(u.pendingChunks(3, [7]), [0, 1, 2]);
});

test('repetição: rede e 5xx repetem até 3 tentativas; 4xx nunca', () => {
    assert.equal(u.MAX_ATTEMPTS, 3);
    assert.equal(u.shouldRetry(0, 1), true);
    assert.equal(u.shouldRetry(503, 2), true);
    assert.equal(u.shouldRetry(503, 3), false);
    assert.equal(u.shouldRetry(0, 3), false);
    for (const status of [400, 401, 403, 404, 409, 413, 415, 419, 422, 429]) {
        assert.equal(u.shouldRetry(status, 1), false, `status ${status}`);
    }
    assert.deepEqual([1, 2, 3].map(u.backoffMs), [500, 1000, 2000]);
});

test('messageFor: português, mensagem do servidor quando existe, sessão expirada e 5xx fixos', () => {
    assert.match(u.messageFor(0, null), /Sem conexão/);
    assert.match(u.messageFor(419, { message: 'CSRF token mismatch.' }), /sessão expirou/);
    assert.match(u.messageFor(401, null), /sessão expirou/);
    assert.match(u.messageFor(503, { message: 'Server Error' }), /erro 503/);
    assert.equal(u.messageFor(422, { message: 'O diretório não é permitido.', code: 'x' }), 'O diretório não é permitido.');
    assert.equal(u.messageFor(422, { message: 'The given data was invalid.', errors: { name: ['Extensão não permitida pela política.'] } }), 'Extensão não permitida pela política.');
    assert.match(u.messageFor(403, null), /permissão/);
    assert.match(u.messageFor(404, {}), /não encontrada/);
    assert.match(u.messageFor(418, null), /Erro inesperado \(418\)/);
});

test('formatBytes combina com o formato do painel (base 1024, vírgula decimal)', () => {
    assert.equal(u.formatBytes(0), '0 B');
    assert.equal(u.formatBytes(1023), '1023 B');
    assert.equal(u.formatBytes(1536), '1,5 KiB');
    assert.equal(u.formatBytes(5_242_880), '5 MiB');
    assert.equal(u.formatBytes(104_857_600), '100 MiB');
});

test('montagem de URLs troca o UUID de marcação e o índice da parte', () => {
    assert.equal(u.idUrl(urls.status, NIL, ID), `/transfers/${ID}/upload`);
    assert.equal(u.chunkUrl(urls.chunk, NIL, ID, 7), `/transfers/${ID}/upload/7`);
    assert.equal(u.chunkUrl(urls.chunk, NIL, ID, 0), `/transfers/${ID}/upload/0`);
    assert.equal(u.idUrl(urls.complete, NIL, ID), `/transfers/${ID}/upload/complete`);
});

test('matchesResume exige o mesmo nome e tamanho', () => {
    const file = new File([new Uint8Array(10)], 'a.zip');
    assert.equal(u.matchesResume(file, { name: 'a.zip', size: 10 }), true);
    assert.equal(u.matchesResume(file, { name: 'b.zip', size: 10 }), false);
    assert.equal(u.matchesResume(file, { name: 'a.zip', size: 11 }), false);
    assert.equal(u.matchesResume(null, { name: 'a.zip', size: 10 }), false);
});

// --- fluxo com servidor simulado ----------------------------------------------------------------

const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

/** Servidor simulado das rotas de transferência; `hooks` altera respostas por teste. */
function fakeServer({ size, chunkSize = 1024, received = [], hooks = {} }) {
    const total = Math.ceil(size / chunkSize);
    const stored = new Map(received.map((n) => [n, null]));
    const calls = [];
    const fetchImpl = async (url, init) => {
        const call = { url, method: init.method, headers: init.headers, body: init.body };
        calls.push(call);
        const hook = hooks[`${init.method} ${url.replace(ID, ':id')}`] ?? hooks[init.method];
        if (hook) {
            const r = await hook(call, calls);
            if (r) return r;
        }
        if (init.method === 'POST' && url === urls.store) {
            return json(201, { transfer_id: ID, status: 'uploading', chunk_size: chunkSize, chunks_total: total });
        }
        if (init.method === 'GET') {
            return json(200, { transfer_id: ID, status: 'uploading', chunk_size: chunkSize, chunks_total: total, received_chunks: [...stored.keys()] });
        }
        if (init.method === 'PUT') {
            const n = Number(url.split('/').pop());
            stored.set(n, Buffer.from(await init.body.arrayBuffer()));
            return json(200, { received_chunks: [...stored.keys()].sort((a, b) => a - b) });
        }
        if (url.endsWith('/upload/complete')) {
            const missing = [...Array(total).keys()].filter((n) => !stored.has(n));
            if (missing.length) return json(422, { message: `Faltam ${missing.length} parte(s) do arquivo.`, code: 'transfer.incomplete', missing_chunks: missing });
            return json(200, { transfer_id: ID, status: 'ready', sha256: 'a'.repeat(64) });
        }
        return json(404, {});
    };

    return { fetchImpl, calls, stored };
}

function setup(content, serverOptions = {}) {
    const file = new File([content], 'relatorio.pdf');
    const server = fakeServer({ size: content.length, ...serverOptions });
    const sleeps = [];
    const client = u.createClient({ fetchImpl: server.fetchImpl, csrf: 'tok123', sleep: async (ms) => { sleeps.push(ms); } });
    const progress = [];
    const run = (extra = {}) => u.uploadFile({
        client, urls, file, dir: 'C:\\ProgramData\\Toolit\\Inbox', subName: '', maxBytes: 1 << 20,
        onProgress: (done, total) => progress.push([done, total]), ...extra,
    });

    return { file, server, sleeps, progress, run };
}

test('envio novo: cria, manda todas as partes com os cabeçalhos certos e conclui', async () => {
    const content = Buffer.from(Array.from({ length: 2500 }, (_, i) => i % 251));
    const { server, progress, run } = setup(content);

    const result = await run();

    assert.equal(result.status, 'ready');
    assert.deepEqual(progress.at(-1), [3, 3]);
    assert.deepEqual(Buffer.concat([0, 1, 2].map((n) => server.stored.get(n))), content);

    const [store, ...rest] = server.calls;
    assert.equal(store.method, 'POST');
    assert.equal(store.headers['X-CSRF-TOKEN'], 'tok123');
    assert.equal(store.headers['Content-Type'], 'application/json');
    assert.equal(store.headers.Accept, 'application/json');
    assert.deepEqual(JSON.parse(store.body), { name: 'relatorio.pdf', size: 2500, dir: 'C:\\ProgramData\\Toolit\\Inbox' });

    const puts = rest.filter((c) => c.method === 'PUT');
    assert.deepEqual(puts.map((c) => c.url), [0, 1, 2].map((n) => `/transfers/${ID}/upload/${n}`));
    for (const put of puts) {
        assert.equal(put.headers['Content-Type'], 'application/octet-stream');
        assert.equal(put.headers['X-CSRF-TOKEN'], 'tok123');
    }
    assert.equal(rest.at(-1).url, `/transfers/${ID}/upload/complete`);
});

test('5xx numa parte: repete com espera e segue; só essa parte é repetida', async () => {
    const content = Buffer.alloc(2048, 7);
    let failed = 0;
    const { server, sleeps, run } = setup(content, {
        hooks: { PUT: (call) => (call.url.endsWith('/1') && failed++ < 2 ? json(503, { message: 'Server Error' }) : null) },
    });

    await run();

    assert.deepEqual(sleeps, [500, 1000]);
    assert.equal(server.calls.filter((c) => c.method === 'PUT' && c.url.endsWith('/1')).length, 3);
    assert.equal(server.calls.filter((c) => c.method === 'PUT' && c.url.endsWith('/0')).length, 1);
});

test('rede caindo nas 3 tentativas falha com a mensagem de rede', async () => {
    const content = Buffer.alloc(1500, 1);
    const { sleeps, run } = setup(content, {
        hooks: { PUT: () => { throw new TypeError('fetch failed'); } },
    });

    await assert.rejects(run(), (e) => e instanceof u.UploadError && e.status === 0 && /Sem conexão/.test(e.message));
    assert.deepEqual(sleeps, [500, 1000]);
});

test('4xx não repete e mostra a mensagem do servidor', async () => {
    const content = Buffer.alloc(1500, 1);
    const { server, sleeps, run } = setup(content, {
        hooks: { PUT: () => json(409, { message: 'A transferência não está recebendo o arquivo.', code: 'transfer.conflict' }) },
    });

    await assert.rejects(run(), (e) => e.status === 409 && e.message === 'A transferência não está recebendo o arquivo.' && e.code === 'transfer.conflict');
    assert.deepEqual(sleeps, []);
    assert.equal(server.calls.filter((c) => c.method === 'PUT').length, 1);
});

test('erro de validação na criação não deixa rascunho e mostra a mensagem em português', async () => {
    const content = Buffer.alloc(100, 1);
    const { run, server } = setup(content, {
        hooks: { POST: () => json(422, { message: 'The given data was invalid.', errors: { name: ['Extensão não permitida pela política de transferência.'] } }) },
    });
    let created = false;

    await assert.rejects(run({ onCreated: () => { created = true; } }), /Extensão não permitida/);
    assert.equal(created, false);
    assert.equal(server.calls.length, 1);
});

test('retomar: consulta o status e envia só as partes que faltam', async () => {
    const content = Buffer.alloc(4096 + 100, 9); // 5 partes
    const { server, run } = setup(content, { received: [0, 2, 3] });

    await run({ resume: { transferId: ID, name: 'relatorio.pdf', size: content.length } });

    assert.equal(server.calls[0].method, 'GET');
    assert.equal(server.calls[0].url, `/transfers/${ID}/upload`);
    assert.deepEqual(server.calls.filter((c) => c.method === 'PUT').map((c) => c.url.split('/').pop()), ['1', '4']);
    assert.equal(server.calls.some((c) => c.method === 'POST' && c.url === urls.store), false);
});

test('retomar com outro arquivo ou envio já encerrado é recusado sem enviar nada', async () => {
    const content = Buffer.alloc(2048, 1);
    const wrong = setup(content);
    await assert.rejects(wrong.run({ resume: { transferId: ID, name: 'outro.pdf', size: 2048 } }), /não é o arquivo/);
    assert.equal(wrong.server.calls.length, 0);

    const closed = setup(content, { hooks: { GET: () => json(200, { status: 'ready', chunk_size: 1024, chunks_total: 2, received_chunks: [] }) } });
    await assert.rejects(closed.run({ resume: { transferId: ID, name: 'relatorio.pdf', size: 2048 } }), (e) => e.status === 409 && /não está mais em andamento/.test(e.message));
    assert.equal(closed.server.calls.some((c) => c.method === 'PUT'), false);
});

test('conclusão com 422 transfer.incomplete reenvia só as partes que faltam e conclui', async () => {
    const content = Buffer.alloc(3000, 3); // 3 partes
    let first = true;
    const { server, run } = setup(content, {
        hooks: {
            PUT: (call) => {
                // O servidor "perde" a parte 1 na primeira vez: responde 200, mas não guarda.
                if (call.url.endsWith('/1') && first) {
                    first = false;
                    return json(200, { received_chunks: [0] });
                }
                return null;
            },
        },
    });
    const result = await run();

    assert.equal(result.status, 'ready');
    assert.equal(server.calls.filter((c) => c.method === 'PUT' && c.url.endsWith('/1')).length, 2);
    assert.equal(server.calls.filter((c) => c.url.endsWith('/upload/complete')).length, 2);
});

test('cancelamento pedido para o envio entre as partes', async () => {
    const content = Buffer.alloc(3000, 3);
    let stop = false;
    const { server, run } = setup(content);

    await assert.rejects(run({ shouldStop: () => stop, onProgress: (done) => { if (done >= 1) stop = true; } }), (e) => e instanceof u.UploadCancelled);
    assert.equal(server.calls.filter((c) => c.method === 'PUT').length, 1);
});

test('arquivo vazio ou acima do limite é barrado antes de falar com o servidor', async () => {
    const empty = setup(Buffer.alloc(0));
    await assert.rejects(empty.run(), /vazio/);
    assert.equal(empty.server.calls.length, 0);

    const big = setup(Buffer.alloc(5000, 1));
    await assert.rejects(big.run({ maxBytes: 4096 }), /limite da política/);
    assert.equal(big.server.calls.length, 0);
});

test('resposta 200 que não é JSON (login/redirect) vira erro, não sucesso', async () => {
    const content = Buffer.alloc(100, 1);
    const { run } = setup(content, { hooks: { POST: () => new Response('<html>login</html>', { status: 200 }) } });

    await assert.rejects(run(), /sessão pode ter expirado/);
});
