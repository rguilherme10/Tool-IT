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
