# 001 — Arquitetura e requisitos

## Objetivo e contexto
Fixar requisitos, arquitetura, decisões e modelo de ameaças para que as demais tarefas sejam executáveis sem este histórico. Repositório iniciou **vazio** (sem código preexistente a preservar).

## Problema a resolver
Evitar retrabalho por decisões implícitas (identidade do agente, Session 0, escopo de permissões, retenção).

## Escopo
Incluído: `docs/architecture.md`, `decisions.md`, `threat-model.md`, `permissions-matrix.md`, `data-model.md`, `api/agent-api-v1.md`, `known-limitations.md`, `status.md`, planos. Excluído: código.

## Dependências
Nenhuma.

## Análise da arquitetura atual
Repo vazio. Host: Windows 11, PHP 8.4.1, Composer 2.8, .NET SDK 9.0.308 (runtimes 8/9/10), Node 24, sem PostgreSQL/Docker. Composer global com `platform.php=8.1` (armadilha: rebaixa o Laravel).

## Arquivos/módulos
`docs/*`, `README.md`.

## Requisitos funcionais
RF: cobrir todos os módulos do enunciado original (inventário, monitoramento, processos/serviços, tarefas, arquivos, tela somente leitura, API, usuários, UI, auditoria, versões).

## Requisitos não funcionais
100 endpoints; Agent inicia conexões; sem segredos em instalador; LGPD.

## Regras de negócio
Ver `permissions-matrix.md` e `architecture.md`.

## Contratos e estruturas
`docs/api/agent-api-v1.md`, `docs/data-model.md`.

## Segurança
`docs/threat-model.md` revisado a cada tarefa que adiciona superfície.

## Estratégia
1. Levantar ambiente. 2. Escrever docs. 3. Revisar consistência docs × código ao fim de cada fase.

## Cenários de erro
Docs divergirem do código → corrigir docs na mesma tarefa que mudou o código.

## Testes obrigatórios
Revisão: links válidos; todo item do enunciado mapeado a uma tarefa (tabela em `README.md`).

## Critérios de aceite
- [x] Documentos listados existem. - [x] Cada módulo do enunciado tem tarefa dona.

## Documentação a atualizar
`docs/status.md` quando o escopo mudar.

## Riscos e limitações
Dimensionamento de tarefas é estimativa; reavaliar a cada fase.

## Checklist de conclusão
- [x] Repo analisado (vazio) - [x] Arquitetura - [x] ADRs - [x] Threat model - [x] Matriz de permissões - [x] Contrato API v1 - [x] Modelo de dados - [x] Planos
