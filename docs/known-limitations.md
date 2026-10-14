# Limitações conhecidas

Atualizar a cada fase.

| # | Limitação | Impacto | Plano |
|---|---|---|---|
| L1 | Host de desenvolvimento sem MySQL, Redis ou Laragon | Migrations, triggers de auditoria e filas **não foram validados** em MySQL/Redis; testes rodam em SQLite/array | Executar perfil `mysql` da suíte no Laragon (task 013) |
| L2 | SDK .NET 10 (LTS) não instalado (só SDK 9.0.308) | Agent alvo `net8.0` provisório; .NET 8 sai de suporte em 10/11/2026 | Instalar SDK 10 e migrar `TargetFramework` (task 005) |
| L3 | Composer global com `platform.php=8.1` | `create-project`/`require` podem resolver versões antigas | Usar `COMPOSER_HOME` isolado ou `config.platform.php` no projeto |
| L4 | Laragon é stack de desenvolvimento | Menos robusto que serviços dedicados | ADR-013 e task 014 |
| L5 | Captura de tela não cobre tela de bloqueio/UAC/DRM | Operador vê "indisponível" | Documentado (task 010) |
| L6 | Sem teste em ARM64 e Windows 10 antigo | Compatibilidade não comprovada | task 013 |
| L7 | Reverb no Windows não validado sob carga | Tempo real pode exigir ajustes | task 010/013 |
| L8 | **Serviço Windows nunca instalado de verdade**: o ambiente de desenvolvimento não é Administrador. O script `installer/Install-ToolitAgent.ps1` foi validado só em `-DryRun` e nas guardas; `sc create`, início automático atrasado, ações de recuperação e reinício do PC **não foram exercitados** | Pode haver ajuste no script/serviço ao primeiro uso real | Rodar como Administrador numa VM/PC de teste e registrar em `test-evidence.md` (antes do piloto de 5 máquinas) |
| L9 | Coleta medida em **uma** máquina (Windows 11, 12 CPUs, 36 GB) | Custo em PCs modestos, Windows 10 e ARM64 desconhecido | task 013 |
| L10 | Agent usa `winsqlite3.dll` do Windows (Windows 10 1607+) | Windows anterior não suportado | decisão consciente (ADR-014) |
| L11 | A chave privada é protegida por DPAPI **escopo máquina**: qualquer processo local com acesso ao arquivo pode desproteger; a barreira real é a ACL do diretório (aplicada só quando elevado/SYSTEM) | Um administrador local pode copiar a identidade | Documentado no modelo de ameaças (T3); mitigação: revogação individual + alerta de uso por duas origens (task 007) |
| L12 | Agregação horária de métricas e alertas não existem; histórico bruto de 7 dias | Sem tendência de longo prazo | task 007 |
| L13 | Temperatura/sensores não coletados | Campo sempre vazio | decisão consciente (sem fonte confiável) |
| L14 | Periféricos: `WmiMonitorID` só retorna monitores com EDID legível; sob a conta SYSTEM `Win32_Printer` **pode** não listar impressoras instaladas por usuário (hipótese, não verificada — os testes rodaram como usuário comum) | Inventário de impressoras pode ficar incompleto no serviço | Verificar quando o serviço rodar como SYSTEM (L8) |
| L15 | Vulnerabilidades .NET: o scan precisa de `--source https://api.nuget.org/v3/index.json` neste host (fonte corporativa configurada está inacessível) | Scan falha silenciosamente com a configuração padrão | usar a fonte pública no CI |
