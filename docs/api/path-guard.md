# PathGuard — contrato de validação de caminhos (Manager PHP e Agent C#)

Regras **sintáticas** (sem acessar disco) para caminhos Windows de transferência de arquivos (ameaça T9). As duas implementações (`manager/app/Support/Transfers/PathGuard.php` e o espelho C# do Agent) devem produzir o mesmo `ok`/`blocked` para todo caso de `tests/contracts/path-guard-cases.json` (seções `directories`, `files`, `inside`, `blocked_extensions`). O campo `why` do vetor é só um código para humanos; o teste do PHP também o confere, o do C# não precisa. Mudou uma regra: mude este documento, o vetor e as duas implementações juntos.

O Agent acrescenta (fora deste contrato): `Path.GetFullPath`, recusa de reparse points e conferência do caminho real do handle.

## Normalização e unidades
- Entrada precisa ser texto válido (UTF-8/UTF-16 sem substitutos soltos); inválido = `invalid_encoding` (extensão: conta como bloqueada).
- **NFC** é a forma de gravação e de comparação de nomes (`normalizeName`). Nomes em NFD são aceitos na entrada. `join` e `isInside` normalizam para NFC.
- **NFKC** só é usado para detectar: homógrafos, nomes reservados e extensões bloqueadas.
- Comprimentos em **unidades UTF-16** (`string.Length` em C#), medidos na entrada como recebida: **total <= 260**, **cada componente <= 120**. Caracteres fora do BMP contam 2.

## Estrutura de diretório (`validateDirectory`)
Ordem de verificação (define o `why`; o `ok` não depende da ordem):
1. vazio -> `empty`.
2. texto inválido -> `invalid_encoding`.
3. controle/formato e homógrafos (abaixo) -> `control_or_format` / `homoglyph`.
4. começa com `\\?\` ou `\\.\` -> `device_path`. (`\??\` não é prefixo especial: cai em `relative`.)
5. começa com `\\` -> `unc`. **UNC é sempre recusado** (inclusive se estivesse listado na política).
6. contém `/` -> `forward_slash`. Só `\` é separador.
7. não começa com `X:\` (X = A-Z/a-z ASCII) -> `relative`. Recusados: `C:`, `C:a\b`, `\a\b`, `a\b`, `..\x`.
8. total > 260 -> `too_long`.
9. depois de `X:\`, separar por `\`; cada componente:
   - vazio -> `empty_component`; exceção: um vazio **apenas no fim** (caminho termina em `\`) -> `trailing_separator`. Ou seja, **separador final é recusado** (sem normalização). `X:\` sozinho (raiz) é sintaticamente válido (a política o proíbe via `forbiddenPolicyDirectory`).
   - regras de componente (abaixo).

## Regras de componente (diretórios e nomes de arquivo)
Em ordem:
1. `.` ou `..` -> `dotdot`.
2. termina em `.` ou espaço ASCII -> `trailing_dot_space` (inclui `...`, `a.`, `a `, só espaços).
3. comprimento > 120 -> `too_long`.
4. contém `:` -> `ads` (fluxos alternativos; `X:` só existe no início do caminho como estrutura).
5. contém `~` seguido de dígito ASCII `[0-9]` em qualquer posição -> `short_name` (8.3).
6. nome reservado -> `reserved`: aplicar NFKC ao componente, tomar o texto antes do **primeiro** `.`, remover espaços finais dele, e comparar **sem caixa, só ASCII**, com `CON PRN AUX NUL COM1..COM9 LPT1..LPT9`. Assim `CON.txt`, `nul.tar.gz`, `CON .txt`, `ＣＯＮ` (largura total) e `COM¹ COM² COM³ LPT¹ LPT² LPT³` (sobrescritos viram 1-3 no NFKC) são reservados; `COM0`, `COM10`, `CONSOLE`, `nulo.txt` não.
7. **caracteres permitidos**: após NFC, apenas letras (`\p{L}`), números (`\p{N}`), espaço ASCII e `. - _ ( )`. Qualquer outro -> `char_not_allowed` (incluindo marcas combinantes sem forma composta, `[ ] & + , ' # @ ! = { } % $ ; ^ ~ < > " | ? *`, emoji, NBSP e espaços Unicode). Letras de outros alfabetos parecidas com latinas (cirílico) são aceitas.
8. **caractere cujo NFC o troca sozinho** (um ponto de código `c` isolado com `NFC(c) != c`: Kelvin U+212A, Ohm U+2126, Angstrom U+212B, ideogramas de compatibilidade) -> `char_not_allowed`.

Espaço no **início** de um componente é aceito.

## Nome de arquivo (`validateFileName`)
Vazio -> `empty`; texto inválido; controle/formato e homógrafos; contém `/` ou `\` -> `separator`; depois, as regras de componente. **Não olha a extensão** (`.exe`, `a.pdf.exe`, `A.ExE` são nomes válidos): quem aceita um arquivo também chama `hasBlockedExtension`.

## Controle, formato e homógrafos (qualquer posição, antes das demais regras)
- `control_or_format`: categorias Unicode **Cc, Cf, Zl, Zp** (inclui NUL, tab, CR/LF, DEL, U+0085, U+00AD, U+200B..U+200F, U+202A..U+202E, U+2066..U+2069, U+FEFF, U+2028, U+2029).
- `homoglyph`: qualquer caractere **não ASCII** que (a) tenha NFKC contendo `/`, `\`, `:` ou `.` (ex.: U+FF0F, U+FF3C, U+FF1A, U+FE55, U+2024, U+2025, U+2026, U+FF0E, U+FE52, U+FE68) **ou** (b) esteja na lista explícita (barras/dois-pontos que o NFKC não mapeia): **U+2044, U+2215, U+2216, U+2236, U+29F5, U+29F8, U+29F9, U+2571, U+2572, U+A789**.

## Comparação sem diferenciar maiúsculas (`isInside`)
- Normalizar os dois lados para NFC e dobrar **somente A-Z <-> a-z (ASCII)**. Todo caractere não ASCII é comparado **exato**. **Não usar** `OrdinalIgnoreCase`/`ToUpperInvariant`/`MB_CASE_UPPER`: `ı` (U+0131), `ſ` (U+017F) e o Kelvin não podem virar `I`, `S`, `K`. É conservador de propósito: `É` x `é` do mesmo diretório real é recusado (lado seguro).
- `isInside(path, dirs)`: `path` precisa passar `validateDirectory` (um caminho de arquivo é tratado como um caminho); diretórios da lista que não passam são ignorados; verdadeiro se `path == dir` ou `path` começa com `dir + "\"` (se `dir` já termina em `\`, i.e. raiz `X:\`, não duplicar). `C:\Dados\InboxX` não está em `C:\Dados\Inbox`.
- `join(dir, nome)`: `dir` e `nome` válidos (senão exceção), NFC, `dir + "\" + nome` (sem duplicar em raiz), e o resultado precisa passar `validateDirectory` (<= 260).

## Extensão bloqueada (`hasBlockedExtension`)
1. Texto inválido -> **bloqueado**.
2. NFKC; remover caracteres Cc e Cf.
3. Separar por `.`; **descartar o primeiro segmento** (nome-base); em cada segmento restante remover espaços das pontas e minúsculas ASCII.
4. Bloqueado se **qualquer** segmento estiver em: `exe dll bat cmd ps1 psm1 vbs vbe js jse wsf msi msp scr com pif lnk hta jar cpl reg sys drv`.

Exemplos: `a.exe`, `A.ExE`, `a.exe.`, `a.exe `, `a. exe`, `a.pdf.exe`, `a.exe.txt`, `.exe`, `..exe`, `a.e<U+200B>xe`, `a.ｅｘｅ` -> bloqueados; `a.pdf`, `exe`, `exe.txt`, `a.exe2`, `a.sexe`, `a.` -> livres.

## Diretórios proibidos em política (`forbiddenPolicyDirectory`)
Proibido se: não passa `validateDirectory` (falha segura); é raiz de unidade; o primeiro componente (após NFC e dobra ASCII) **começa com** `WINDOWS` ou `PROGRAM FILES` (qualquer letra de unidade; cobre `Windows.old`, `Program Files (x86)`); ou é exatamente `X:\Users`. Permitidos: `C:\Users\Publico\Inbox`, `C:\Dados\Windows`.

## Códigos `why`
`ok`, `empty`, `invalid_encoding`, `control_or_format`, `homoglyph`, `device_path`, `unc`, `forward_slash`, `relative`, `too_long`, `trailing_separator`, `empty_component`, `dotdot`, `trailing_dot_space`, `ads`, `short_name`, `reserved`, `char_not_allowed`, `separator` (só nomes de arquivo). A seção `inside` não tem `why`; `blocked_extensions` usa `blocked`.
