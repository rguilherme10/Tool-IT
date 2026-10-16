<?php

namespace App\Support\Transfers;

use InvalidArgumentException;
use Normalizer;

/**
 * Validação SINTÁTICA de caminhos Windows para transferência de arquivos (ameaça T9: path traversal).
 *
 * Estática e sem tocar o disco. Tem um espelho em C# no Agent (que acrescenta reparse points, GetFullPath e a
 * conferência do handle) e os dois passam pelo MESMO vetor tests/contracts/path-guard-cases.json — qualquer
 * mudança de regra aqui exige mudar o vetor e o espelho.
 *
 * Decisões (todas fixadas no vetor):
 *  - Só barra invertida; "/" é recusada. Separador final é recusado (exceto na raiz "X:\").
 *  - Após NFC, um componente só pode ter letras (\p{L}), dígitos (\p{N}), espaço e ". - _ ( )"; todo o resto é
 *    "char_not_allowed" (inclui marcas sem forma composta, colchetes, & + , ' # @ ! = $ % ^ { } e ;. O "~" só vale
 *    como 8.3 -> "short_name").
 *  - Nomes em NFD são aceitos; quem grava deve usar normalizeName() (NFC). isInside() e join() normalizam.
 *  - Comprimentos em unidades UTF-16 (como o Windows conta): total <= 260, componente <= 120.
 *  - Homógrafos: qualquer caractere não ASCII cujo NFKC contenha "/ \ : ." ou que esteja numa lista explícita
 *    (barras que o NFKC não mapeia, como U+2215). Letras de outros alfabetos parecidas com latinas (cirílico)
 *    NÃO são tratadas: não permitem escapar de diretório.
 *  - Comparação sem diferenciar maiúsculas dobra SÓ A-Z/a-z; todo caractere não ASCII é comparado exato (após NFC).
 *    É conservador (recusa "É" x "é" do mesmo diretório real) e evita que ı, ſ ou o Kelvin virem I, S, K.
 *    Nomes reservados e extensões usam NFKC + comparação ASCII.
 *  - Veja docs/api/path-guard.md: é o contrato das duas implementações.
 */
final class PathGuard
{
    /** Extensões que nunca podem ser transferidas, mesmo se constarem da política. */
    public const BLOCKED_EXTENSIONS = [
        'exe', 'dll', 'bat', 'cmd', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'msi', 'msp', 'scr', 'com', 'pif',
        'lnk', 'hta', 'jar', 'cpl', 'reg', 'sys', 'drv',
    ];

    private const MAX_PATH = 260;

    private const MAX_COMPONENT = 120;

    /** Imitações de "/" e "\" que o NFKC não converte. */
    private const EXTRA_HOMOGLYPHS = [0x2044, 0x2215, 0x2216, 0x2236, 0x29F5, 0x29F8, 0x29F9, 0x2571, 0x2572, 0xA789];

    private const MESSAGES = [
        'empty' => 'O caminho está vazio.',
        'invalid_encoding' => 'O texto tem codificação inválida.',
        'control_or_format' => 'Contém caracteres de controle ou invisíveis.',
        'homoglyph' => 'Contém caracteres que imitam separadores, dois-pontos ou ponto.',
        'device_path' => 'Caminhos de dispositivo (\\\\?\\ ou \\\\.\\) não são permitidos.',
        'unc' => 'Caminhos de rede (UNC) não são permitidos.',
        'forward_slash' => 'Use a contrabarra (\\) como separador, não a barra (/).',
        'relative' => 'O caminho deve ser absoluto, como C:\\Pasta.',
        'too_long' => 'O caminho ou um de seus nomes é longo demais.',
        'trailing_separator' => 'O caminho não pode terminar em contrabarra.',
        'empty_component' => 'O caminho tem uma pasta vazia (contrabarras seguidas).',
        'dotdot' => 'Os nomes "." e ".." não são permitidos.',
        'trailing_dot_space' => 'Nomes não podem terminar em ponto ou espaço.',
        'ads' => 'Dois-pontos (fluxos alternativos) não são permitidos.',
        'short_name' => 'Nomes curtos 8.3 (com ~número) não são permitidos.',
        'reserved' => 'Nome reservado do Windows (CON, NUL, COM1...).',
        'char_not_allowed' => 'Só letras, números, espaço e . - _ ( ) são permitidos.',
        'separator' => 'O nome não pode conter separadores de pasta.',
    ];

    /** Mensagem curta em português para um código de motivo. */
    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? $code;
    }

    /**
     * Motivo (mensagem) pelo qual o diretório é inválido, ou null.
     * ATENÇÃO: é só sintaxe. Para diretórios de política chame também forbiddenPolicyDirectory().
     */
    public static function validateDirectory(string $path): ?string
    {
        $code = self::directoryReason($path);

        return $code === null ? null : self::message($code);
    }

    /**
     * Motivo (mensagem) pelo qual o nome de arquivo é inválido, ou null.
     * ATENÇÃO: NÃO olha a extensão (".exe", "a.pdf.exe" são nomes válidos aqui). Quem aceita um arquivo deve
     * chamar também hasBlockedExtension().
     */
    public static function validateFileName(string $name): ?string
    {
        $code = self::fileNameReason($name);

        return $code === null ? null : self::message($code);
    }

    /** Código estável do motivo (o "why" do vetor) para um diretório/caminho absoluto, ou null se válido. */
    public static function directoryReason(string $path): ?string
    {
        if ($path === '') {
            return 'empty';
        }
        if (! mb_check_encoding($path, 'UTF-8')) {
            return 'invalid_encoding';
        }
        if (($code = self::characterIssue($path)) !== null) {
            return $code;
        }
        if (str_starts_with($path, '\\\\?\\') || str_starts_with($path, '\\\\.\\')) {
            return 'device_path';
        }
        if (str_starts_with($path, '\\\\')) {
            return 'unc';
        }
        if (str_contains($path, '/')) {
            return 'forward_slash';
        }
        if (preg_match('/^[A-Za-z]:\\\\/', $path) !== 1) {
            return 'relative';
        }
        if (self::utf16Length($path) > self::MAX_PATH) {
            return 'too_long';
        }

        $rest = substr($path, 3);
        if ($rest === '') {
            return null;   // raiz da unidade, "X:\"
        }

        $parts = explode('\\', $rest);
        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if ($part === '') {
                return ($i === $last && $last > 0) ? 'trailing_separator' : 'empty_component';
            }
            if (($code = self::componentReason($part)) !== null) {
                return $code;
            }
        }

        return null;
    }

    /** Código estável do motivo para um nome de arquivo (um único componente), ou null se válido. */
    public static function fileNameReason(string $name): ?string
    {
        if ($name === '') {
            return 'empty';
        }
        if (! mb_check_encoding($name, 'UTF-8')) {
            return 'invalid_encoding';
        }
        if (($code = self::characterIssue($name)) !== null) {
            return $code;
        }
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            return 'separator';
        }

        return self::componentReason($name);
    }

    /** NFC (forma em que os nomes são gravados e comparados). */
    public static function normalizeName(string $name): string
    {
        $normalized = mb_check_encoding($name, 'UTF-8') ? Normalizer::normalize($name, Normalizer::FORM_C) : false;
        if ($normalized === false) {
            throw new InvalidArgumentException(self::message('invalid_encoding'));
        }

        return $normalized;
    }

    /**
     * $path (caminho absoluto, também válido pelas regras de diretório) está dentro de algum de $dirs, ou é um deles?
     * Sem diferenciar maiúsculas, com fronteira de separador ("Inbox" não contém "InboxX"). Caminho inválido ou
     * diretório permitido inválido nunca casam.
     *
     * @param  list<string>  $dirs
     */
    public static function isInside(string $path, array $dirs): bool
    {
        if (self::directoryReason($path) !== null) {
            return false;
        }
        $candidate = self::fold($path);

        foreach ($dirs as $dir) {
            if (! is_string($dir) || self::directoryReason($dir) !== null) {
                continue;
            }
            $root = self::fold($dir);
            $prefix = str_ends_with($root, '\\') ? $root : $root.'\\';
            if ($candidate === $root || str_starts_with($candidate, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Junta diretório e nome de arquivo. Lança InvalidArgumentException se qualquer parte for inválida ou o
     * resultado passar de 260: o resultado sempre está dentro do diretório informado.
     * ATENÇÃO: NÃO checa extensão (chame hasBlockedExtension() no nome) nem a política (isInside() contra a lista
     * da política; forbiddenPolicyDirectory() para diretórios de política).
     */
    public static function join(string $dir, string $name): string
    {
        if (($code = self::directoryReason($dir)) !== null) {
            throw new InvalidArgumentException('Diretório inválido: '.self::message($code));
        }
        if (($code = self::fileNameReason($name)) !== null) {
            throw new InvalidArgumentException('Nome de arquivo inválido: '.self::message($code));
        }

        $dir = self::normalizeName($dir);
        $joined = (str_ends_with($dir, '\\') ? $dir : $dir.'\\').self::normalizeName($name);

        if (($code = self::directoryReason($joined)) !== null) {
            throw new InvalidArgumentException('Caminho inválido: '.self::message($code));
        }

        return $joined;
    }

    /**
     * Alguma das extensões do nome (todas, não só a última) é executável/script? Remove caracteres invisíveis e
     * ponto/espaço finais, aplica NFKC (".exe" escondido por largura total/bidi/zero-width) e ignora maiúsculas.
     * O que antes do primeiro ponto é o nome-base, nunca extensão; ".exe" (nome só com extensão) é bloqueado.
     * Em dúvida (texto inválido), bloqueia.
     */
    public static function hasBlockedExtension(string $name): bool
    {
        if (! mb_check_encoding($name, 'UTF-8')) {
            return true;
        }
        $normalized = Normalizer::normalize($name, Normalizer::FORM_KC);
        if ($normalized === false) {
            return true;
        }
        $visible = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $normalized);
        if ($visible === null) {
            return true;
        }

        $segments = explode('.', $visible);
        array_shift($segments);
        foreach ($segments as $segment) {
            if (in_array(self::asciiLower(trim($segment, ' ')), self::BLOCKED_EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Diretório que uma política jamais pode conter: raiz de unidade, "X:\Windows*", "X:\Program Files*" e
     * "X:\Users" exato. Diretório inválido também é proibido (falha segura).
     */
    public static function forbiddenPolicyDirectory(string $dir): bool
    {
        if (self::directoryReason($dir) !== null) {
            return true;
        }

        $parts = explode('\\', substr(self::fold($dir), 3));
        if ($parts === ['']) {
            return true;
        }
        if (str_starts_with($parts[0], 'WINDOWS') || str_starts_with($parts[0], 'PROGRAM FILES')) {
            return true;
        }

        return $parts[0] === 'USERS' && count($parts) === 1;
    }

    private static function componentReason(string $component): ?string
    {
        if ($component === '.' || $component === '..') {
            return 'dotdot';
        }
        $end = substr($component, -1);
        if ($end === '.' || $end === ' ') {
            return 'trailing_dot_space';
        }
        if (self::utf16Length($component) > self::MAX_COMPONENT) {
            return 'too_long';
        }
        if (str_contains($component, ':')) {
            return 'ads';
        }
        if (preg_match('/~[0-9]/', $component) === 1) {
            return 'short_name';
        }
        if (self::isReservedName($component)) {
            return 'reserved';
        }
        $composed = Normalizer::normalize($component, Normalizer::FORM_C);
        if ($composed === false || preg_match('/^[\p{L}\p{N} .\-_()]+$/u', $composed) !== 1) {
            return 'char_not_allowed';
        }
        // Caractere que o NFC troca sozinho por outro (Kelvin K, Ohm, Angstrom, ideogramas de compatibilidade): no
        // disco é um nome diferente do que o NFC produz, então não pode ser aceito (senão "K" Kelvin casaria com "k").
        foreach (preg_split('//u', $component, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (strlen($char) > 1 && Normalizer::normalize($char, Normalizer::FORM_C) !== $char) {
                return 'char_not_allowed';
            }
        }

        return null;
    }

    /** CON PRN AUX NUL COM1-9 LPT1-9, com ou sem extensão, ignorando espaços antes do ponto e largura total. */
    private static function isReservedName(string $component): bool
    {
        $normalized = Normalizer::normalize($component, Normalizer::FORM_KC);
        if ($normalized === false) {
            return true;
        }
        $base = rtrim(explode('.', $normalized, 2)[0], ' ');

        return preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $base) === 1;
    }

    /** Controle/formato/separadores de linha e homógrafos de "/ \ : .". */
    private static function characterIssue(string $text): ?string
    {
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $text) === 1) {
            return 'control_or_format';
        }

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chars === false ? [] : $chars as $char) {
            if (strlen($char) === 1) {
                continue;
            }
            if (in_array(mb_ord($char, 'UTF-8'), self::EXTRA_HOMOGLYPHS, true)) {
                return 'homoglyph';
            }
            $compat = Normalizer::normalize($char, Normalizer::FORM_KC);
            if ($compat !== false && preg_match('~[/\\\\:.]~', $compat) === 1) {
                return 'homoglyph';
            }
        }

        return null;
    }

    /** NFC + dobra de caixa SÓ ASCII (a-z -> A-Z): o resto dos caracteres é comparado exato. */
    private static function fold(string $text): string
    {
        return strtr(self::normalizeName($text), 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    /** Comprimento em unidades UTF-16 (o que o Windows limita). Exige UTF-8 válido. */
    private static function utf16Length(string $text): int
    {
        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    private static function asciiLower(string $text): string
    {
        return strtr($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }
}
