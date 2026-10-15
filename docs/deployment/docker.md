# Implantação em Docker (Manager)

> **Estado: escrito, NÃO executado.** O ambiente de desenvolvimento não tem Docker, então nenhum `docker build`/`docker compose up` rodou. O que foi verificado está em [`test-evidence.md`](../test-evidence.md); o resto é pendência **L25**. Faça a primeira implantação num servidor de teste e corrija este guia com o que divergir.

Arquivos em [`deploy/docker/`](../../deploy/docker): `compose.yaml`, `Dockerfile`, `entrypoint.sh`, `nginx/default.conf`, `php/{php.ini,fpm.conf}`, `mysql/init/01-users.sh`, `.env.example`, `make-selfsigned.sh`. Decisão: ADR-020.

## Arquitetura
```
Internet/LAN ──443──▶ web (nginx, TLS, usuário sem privilégio, FS somente leitura)
                          │ FastCGI :9000 (rede backend; não publicada)
                          ▼
                       app (PHP-FPM) ──▶ mysql (8.4)      migrate (one-shot, usuário de migração)
                          │          └─▶ redis (7, senha, AOF)
              queue (queue:work) · scheduler (schedule:work)   ← mesma imagem, outro papel
```
- **Uma imagem** (`toolit/manager`) em vários papéis; o `web` é outro alvo do mesmo Dockerfile (traz o `public/`).
- **Só `web` publica portas** (443; 80 apenas redireciona). MySQL, Redis e PHP-FPM ficam na rede `backend`.
- **Scheduler é obrigatório**: sem ele nenhum alerta abre/fecha e a retenção não roda (L21).
- **Migrações com outro usuário**: o serviço `migrate` usa `DB_MIGRATE_*` (ALL no schema); a aplicação usa `DB_*` (só DML: sem DDL, sem `TRIGGER`, sem `DROP`). Isso é o que o guia do Laragon pedia (`server.md` §2), já automatizado.
- **IP real do cliente**: o nginx *sobrescreve* `X-Forwarded-For` com `$remote_addr` e o app confia nele via `TRUSTED_PROXIES` (coberto por teste automatizado). Sem isso, limites de taxa, bloqueio de login e auditoria veriam sempre o IP do contêiner.

## Pré-requisitos
Linux com Docker Engine ≥ 24 e Compose v2 (`docker compose`), 2 vCPU / 4 GB RAM / 20 GB (a validar — task 013), nome DNS interno apontando para o servidor e **certificado da CA corporativa** para esse nome (o Agent recusa HTTP e certificados não confiáveis).

## Primeira implantação
```bash
cd deploy/docker
cp .env.example .env && chmod 600 .env        # preencher APP_URL, senhas (MySQL x3, Redis)
mkdir certs                                   # fullchain.pem + privkey.pem da CA corporativa
chown 101 certs/privkey.pem && chmod 640 certs/privkey.pem   # nginx roda como uid 101

# APP_KEY: gerar uma vez e colar no .env (guardar cópia FORA do servidor)
docker compose build app
APP_KEY=temporaria docker compose run --rm --no-deps app php artisan key:generate --show

docker compose up -d --build
docker compose ps                              # todos "healthy"; migrate: "exited (0)"
```
Primeiro administrador (a senha é pedida de forma oculta; depois ative o TOTP no login):
```bash
docker compose exec -it app php artisan toolit:create-admin --name="Fulano" --email="fulano@empresa.local"
```
Para **testar** sem a CA corporativa: `./make-selfsigned.sh toolit.empresa.local` (o navegador avisa; o Agent não aceita).

## Verificação pós-implantação
1. `docker compose ps` — `web`, `app`, `mysql`, `redis` healthy; `queue` e `scheduler` rodando.
2. `curl -k https://<host>/up` → 200; `curl -sI http://<host>/` → 301 para HTTPS.
3. `docker compose exec app php artisan about` — ambiente `production`, debug off, cache/sessão/fila `redis`, banco `mysql`.
4. Login do administrador + TOTP; "Verificar integridade da cadeia" em Auditoria.
5. `docker compose logs scheduler` mostra `alerts:evaluate` a cada minuto.
6. Token de registro (tela "Novo agente") e `agent-smoke` contra o servidor (`tests/integration`).
7. **Primeira prova de MySQL e Redis do projeto (L1):** rodar a suíte contra um banco de teste **separado** — nunca o de produção.

## Operação
| Tarefa | Comando |
|---|---|
| Logs | `docker compose logs -f web app queue scheduler` (Laravel escreve em stderr) |
| Atualizar | `git pull && docker compose up -d --build` (o `migrate` roda antes do app novo subir) |
| Reverter | reimplantar a tag anterior (`TOOLIT_TAG`); migrações não são desfeitas automaticamente — ver task 014 |
| Backup do banco | `docker compose exec -T mysql sh -c 'mysqldump --single-transaction -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > toolit-$(date +%F).sql` (copiar para fora do servidor) |
| Restaurar | `docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < toolit-AAAA-MM-DD.sql` |
| Renovar certificado | trocar `certs/*.pem` e `docker compose restart web` |
| Parar sem perder dados | `docker compose down` (**sem** `-v`; `-v` apaga banco, Redis e storage) |

## Pontos de atenção
- `.env`, `certs/` e a `APP_KEY` não entram no Git. Perder a `APP_KEY` invalida sessões e os segredos TOTP cifrados.
- O usuário da aplicação ainda tem `UPDATE`/`DELETE` em `audit_logs` (o *trigger* de append-only é a barreira); revogar por tabela exige conceder tabela a tabela depois das migrações — pendência registrada em L25.
- Em produção o Laravel valida senhas contra *Have I Been Pwned* (`uncompromised`, só envia prefixo de hash); sem saída para a internet a verificação é simplesmente ignorada.
- Atrás de outro balanceador/WAF na frente do `web`, `TRUSTED_PROXIES` continua correto (nginx redefine o cabeçalho), mas então o IP registrado será o do balanceador: encaminhe o IP via PROXY protocol/ajuste o nginx (`real_ip_header`) antes de pôr um.
- Reinício do host: `restart: unless-stopped` religa web/app/queue/scheduler/MySQL/Redis. O `migrate` (sem política de restart) só roda de novo no próximo `docker compose up`; é idempotente.
