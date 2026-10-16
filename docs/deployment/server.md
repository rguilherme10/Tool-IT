# Instalação do servidor (Manager) — Laragon / Windows

> Para servidor com Docker, veja [`docker.md`](docker.md).

> **Estado: guia de projeto, NÃO validado em um Laragon real.** O host de desenvolvimento não tem MySQL, Redis nem Laragon. Cada passo abaixo deve ser executado e conferido na primeira instalação; corrija este documento com o que for diferente. Fica pendente na task 013/014.

## 1. Requisitos
- Windows com Laragon (Apache **ou** Nginx), PHP ≥ 8.3, MySQL 8.0+ (ou MariaDB 10.6+), Redis, Node 20+ (apenas para compilar o front-end).
- PHP com `openssl` **e** arquivo `openssl.cnf` acessível (variável `OPENSSL_CONF`), `pdo_mysql`, `sodium`, `intl`, `mbstring`, `curl`, `zip`; `phpredis` (ou `predis/predis` via Composer).
- Recursos sugeridos para ~100 endpoints: 2 vCPU, 4 GB RAM, 20 GB (a validar por medição — task 013).

## 2. Banco de dados (MySQL)
```sql
CREATE DATABASE toolit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'toolit'@'127.0.0.1' IDENTIFIED BY '<senha-forte>';
GRANT ALL ON toolit.* TO 'toolit'@'127.0.0.1';   -- necessário apenas para migrar (inclui TRIGGER)
```
Depois de migrar, em produção **reduza** o usuário da aplicação: sem `UPDATE`/`DELETE` em `audit_logs` (ele continua protegido por trigger, mas o privilégio é a segunda camada) e sem `DROP`/`TRIGGER`. Use outro usuário para migrações. Defina senha no `root`; `bind-address=127.0.0.1` se o app estiver na mesma máquina.

## 3. Aplicação
```bash
cd manager
composer install --no-dev --optimize-autoloader
cp .env.example .env     # ajustar abaixo
php artisan key:generate
php artisan migrate --force --seeder=RolesAndPermissionsSeeder   # ou: migrate --force && db:seed --class=RolesAndPermissionsSeeder --force
npm ci && npm run build
php artisan toolit:create-admin --name="..." --email="..."
php artisan config:cache && php artisan route:cache && php artisan view:cache
```
`.env` de produção: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<host>`, `DB_CONNECTION=mysql` + credenciais, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_SECURE_COOKIE=true`. **Guarde `APP_KEY` fora do servidor** (sem ela, sessões e segredos TOTP cifrados são perdidos).

## 4. TLS
O Agent **recusa** HTTP e certificados não confiáveis. Use um certificado emitido por uma CA corporativa (instalada nos endpoints por GPO) para o nome DNS do Manager. O certificado autoassinado do Laragon serve só para desenvolvimento.

## 5. Processos persistentes (a validar)
| Processo | Comando | Como manter |
|---|---|---|
| Fila | `php artisan queue:work --tries=3 --max-time=3600` | NSSM (serviço) ou Agendador de Tarefas |
| Agendador | `php artisan schedule:run` a cada minuto — **obrigatório**: `alerts:evaluate` (1 min), `metrics:prune` (03:15), `alerts:prune` (03:30). Parado = nenhum alerta abre nem fecha (L21) | Agendador de Tarefas |
| Tempo real (task 010) | `php artisan reverb:start` | NSSM |
*(Fila e Reverb ainda não têm uso; o agendador já é necessário para alertas e retenção.)*

## 5.1 Transferência de arquivos: limites de proxy/PHP e disco (task 009)
Os arquivos nunca passam inteiros numa requisição: o navegador manda **PUT de 1 MiB** (`/transfers/{uuid}/upload/{n}`, corpo bruto `application/octet-stream`) e o Agent **PUT de até 2 MiB** (`/api/agent/v1/transfers/{id}/chunks/{n}`; o Manager recusa > 2,5 MiB com `413` antes de verificar a assinatura). Portanto o servidor web/PHP precisa aceitar corpos de **pelo menos 3 MiB**:
- nginx: `client_max_body_size 3m;` (ou maior) no `server` do Manager; o padrão de 1m quebra os chunks do Agent com `413` do próprio nginx.
- Apache: `LimitRequestBody` ≥ 3145728 (ou 0 = sem limite) se estiver definido.
- PHP: `post_max_size` ≥ `3M` (folga; os PUTs são lidos de `php://input`) e `memory_limit` com folga para um chunk em memória (128M sobra). `upload_max_filesize` **não** é usado (não há multipart).
- Disco: `storage/app/private/transfers/` guarda as partes e o arquivo montado (até 2× o tamanho durante a montagem; o envio é recusado se o espaço livre for menor) e os blobs de transferências encerradas por 7 dias (`TOOLIT_TRANSFERS_RETENTION_DAYS`). Sem quota nem alerta de disco (L46): dimensione o volume e monitore-o.
- Agendador: `transfers:prune` diário às 03:45 apaga partes/blobs vencidos e expira uploads abandonados (24 h); `tasks:expire` (a cada minuto) devolve à fila `file.*` cuja tentativa sumiu (é o que permite retomar).
- Limite de requisições do Agent nas rotas de transferência: `TOOLIT_TRANSFER_RATE_LIMIT` (padrão 600/min por instalação; separado dos 120/min das demais rotas).
*Validado só com `php artisan serve` (PHP 8.4, servidor embutido) no E2E de 2026-10-16; nginx/Apache/PHP-FPM reais não foram testados.*

## 6. Rede
Firewall: liberar apenas TCP 443 do Manager para a LAN. MySQL e Redis **não** devem ser expostos à rede. DNS: registro A interno para o nome usado no certificado.

## 7. Verificação pós-instalação
1. `php artisan about` — ambiente `production`, debug off.
2. Login do administrador + ativação do TOTP.
3. `php artisan toolit:enrollment-token --user=<admin>` e `php tests/integration/agent-smoke.php https://<host> <token>` (7/7).
4. `php artisan audit:verify` — cadeia íntegra.
5. `php artisan test` com `DB_CONNECTION=mysql` num banco **de teste** (nunca no de produção) — pendência L1.

## 8. Backup e atualização
Task 014 (backup MySQL + `storage/` + `.env` cifrado; rollback). Até lá: `mysqldump --single-transaction toolit` diário e cópia fora do servidor.
