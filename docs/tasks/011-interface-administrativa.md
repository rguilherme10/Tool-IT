# 011 — Interface administrativa

## Objetivo e contexto
Painel web responsivo e consistente, entregue **incrementalmente**: cada módulo ganha sua tela quando o backend existe.

## Problema
Evitar telas demonstrativas com dados fictícios.

## Escopo
Incluído: layout base, dashboard, lista/detalhe de endpoints (abas), módulos 1–15 do enunciado §13 conforme backend disponível. Módulo sem backend ⇒ página "Não implementado" com estado real. Excluído: app mobile.

## Dependências
003 (base) e cada tarefa de módulo.

## Análise da arquitetura atual
Blade + Vite (skeleton). Decisão: Blade + Alpine.js + Tailwind (já no skeleton) para tabelas/filtros server-side; Livewire só se um módulo exigir interatividade que Alpine não resolva (registrar em ADR). Gráficos: biblioteca leve via npm.

## Arquivos
`resources/views/layouts/app.blade.php`, `resources/views/endpoints/*`, `app/Http/Controllers/Web/*`, `resources/js/*`, componentes Blade (`php artisan make:component`).

## Requisitos funcionais
Listagem: filtros no servidor (hostname, IP, SO, grupo, estado, versão, último contato, alertas), paginação, ordenação. Detalhe: abas Detalhes, Hardware/Software, Métricas, Processos/Serviços, Tarefas, Arquivos, Auditoria. Confirmação para operações críticas; estados de carregamento/erro.

## Não funcionais
Responsivo (≥360 px), acessível (labels, foco, contraste), sem chamadas N+1 (testar com `preventLazyLoading` em dev), atualização por polling (30 s) ou Reverb quando houver.

## Regras
UI nunca é a barreira de segurança: botões escondidos por `@can`, mas **toda rota** autorizada no backend. Dados do endpoint sempre escapados (`{{ }}`); proibido `{!! !!}` em dados externos (teste de varredura).

## Contratos
Rotas web nomeadas; filtros via query string validada por FormRequest.

## Segurança
CSRF, CSP, `SameSite`, MFA, cabeçalhos (002); download de arquivos com `attachment`.

## Estratégia
Layout → lista de endpoints (003) → detalhe → inventário (006) → métricas (007) → tarefas (008) → arquivos (009) → tela (010) → auditoria → usuários/papéis → configurações.

## Erros
Sem permissão (403 com página clara), endpoint inexistente (404), backend indisponível (mensagem + retry), lista vazia (estado vazio real).

## Testes
Feature tests de cada rota (autorização, filtros, paginação), teste de varredura de `{!! !!}`, testes de navegador (Playwright/Dusk) para fluxos críticos quando houver Chrome de teste.

## Aceite
- [ ] Cada tela alimentada por dados reais - [ ] 403 testado por tela - [ ] Filtros server-side testados - [ ] Responsivo verificado manualmente

## Documentação
Manual do administrador com capturas reais.

## Riscos
Escopo de UI cresce sem fim: priorizar fluxos de operação.

## Checklist (por módulo, marcar conforme entregue)
- [x] Layout - [x] Dashboard - [x] Endpoints - [x] Detalhe (incl. mover/desativar) - [x] Inventário - [x] Métricas - [ ] Processos/serviços - [ ] Arquivos - [ ] Tarefas - [ ] Transferências - [ ] Sessões - [x] Alertas e regras - [x] Usuários/permissões/grupos - [ ] Versões - [x] Auditoria (filtros + verificação) - [ ] Configurações

> Telas marcadas: validadas por testes HTTP; revisão manual em navegador/celular pendente (L24).
