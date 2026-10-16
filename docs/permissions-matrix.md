# Matriz de permissões

Permissões são strings `modulo.acao` definidas em `App\Enums\Permission` (fonte da verdade). Atribuição: `role_assignments(user, role, endpoint_group nullable)`; `NULL` = global. Toda verificação com endpoint resolve o grupo do endpoint.

| Permissão | Admin | Operador TI | Suporte | Auditor | Consulta |
|---|:-:|:-:|:-:|:-:|:-:|
| `inventory.view` | ✔ | ✔ | ✔ | ✔ | ✔ (limitado*) |
| `inventory.view_sensitive` (software completo, IPs/MACs, usuário logado) | ✔ | ✔ | ✔ | ✔ | – |
| `metrics.view` | ✔ | ✔ | ✔ | ✔ | ✔ |
| `alerts.view` | ✔ | ✔ | ✔ | ✔ | ✔ |
| `alerts.manage` (reconhecer/fechar) | ✔ | ✔ | ✔ | – | – |
| `processes.view` | ✔ | ✔ | ✔ | – | – |
| `processes.kill` | ✔ | ✔ | – | – | – |
| `services.view` | ✔ | ✔ | ✔ | – | – |
| `services.control` | ✔ | ✔ | – | – | – |
| `tasks.view` | ✔ | ✔ | ✔ | ✔ | – |
| `tasks.create` (tipadas de baixo risco) | ✔ | ✔ | ✔ (diagnóstico) | – | – |
| `tasks.create_high_risk` | ✔ | ✔ | – | – | – |
| `tasks.approve` | ✔ | – | – | – | – |
| `tasks.cancel` | ✔ | ✔ | ✔ (próprias) | – | – |
| `powershell.execute` (módulo separado) | ✔ (com aprovação) | – | – | – | – |
| `files.inventory_view` | ✔ | ✔ | ✔ | ✔ | – |
| `files.transfer_send` | ✔ | ✔ | – | – | – |
| `files.transfer_fetch` | ✔ | ✔ | ✔ (diretórios de suporte) | – | – |
| `files.execute` (separada de transferir) | ✔ | – | – | – | – |
| `screen.view` | ✔ | – | ✔ | – | – |
| `endpoints.manage` (editar, grupos, desativar) | ✔ | ✔ | – | – | – |
| `endpoints.revoke` | ✔ | ✔ | – | – | – |
| `enrollment.create` | ✔ | ✔ | – | – | – |
| `enrollment.create_multi` | ✔ | – | – | – | – |
| `agents.versions_manage` | ✔ | – | – | – | – |
| `audit.view` | ✔ | – | – | ✔ | – |
| `users.manage` / `roles.manage` | ✔ | – | – | – | – |
| `settings.manage` | ✔ | – | – | – | – |

\* Consulta: sem software instalado completo, sem IPs/MACs, sem usuário logado (campos marcados `sensitive` no inventário).

Regras:
- Administrador global não pode ser removido do último usuário (guard no serviço de usuários).
- Quem cria uma tarefa de alto risco não pode aprová-la nem rejeitá-la (separação de funções, aplicada no `TaskService`; testado e verificado no E2E de 2026-10-15). O **Operador TI cria** tarefas de alto risco (`process.kill`, `agent.restart`, serviço fora da allowlist), mas **só o Admin aprova** (`tasks.approve`). Consequência: numa instalação com **um único Admin**, as tarefas de alto risco criadas por ele nunca podem ser aprovadas (L31). Criar exige também a permissão do recurso (`processes.kill` / `services.control`; `processes.view` / `services.view` para as listas); criador e aprovador são reconferidos no claim. Catálogo: [`api/task-types.md`](api/task-types.md).
- Auditor nunca recebe `tasks.create*`, `screen.view`, `files.transfer*` (testado).
- Negado por padrão: ausência de atribuição = sem acesso.
