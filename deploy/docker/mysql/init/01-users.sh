#!/bin/sh
# Executado pela imagem do MySQL só na PRIMEIRA inicialização do volume de dados.
# Cria dois usuários com privilégios diferentes:
#   - aplicação  (DB_USERNAME):          só DML no schema; sem DDL, sem TRIGGER, sem DROP.
#   - migrações  (DB_MIGRATE_USERNAME):  ALL no schema; usado apenas pelo serviço one-shot "migrate".
# O MYSQL_USER/MYSQL_PASSWORD padrão da imagem NÃO é usado (veja compose.yaml): ficaria com ALL.
set -eu

: "${MYSQL_DATABASE:?}" "${APP_DB_USER:?}" "${APP_DB_PASSWORD:?}" "${MIGRATE_DB_USER:?}" "${MIGRATE_DB_PASSWORD:?}"

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE USER '${APP_DB_USER}'@'%' IDENTIFIED BY '${APP_DB_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE, LOCK TABLES, CREATE TEMPORARY TABLES ON \`${MYSQL_DATABASE}\`.* TO '${APP_DB_USER}'@'%';

CREATE USER '${MIGRATE_DB_USER}'@'%' IDENTIFIED BY '${MIGRATE_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\`.* TO '${MIGRATE_DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
