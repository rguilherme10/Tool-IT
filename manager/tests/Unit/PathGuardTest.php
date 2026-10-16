<?php

namespace Tests\Unit;

use App\Support\Transfers\PathGuard;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O vetor tests/contracts/path-guard-cases.json é o contrato compartilhado com o Agent (C#): os dois lados
 * comparam apenas ok/blocked. Aqui, por ser o lado que gerou o vetor, também conferimos o código "why".
 */
class PathGuardTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function vector(): array
    {
        // Os data providers rodam antes de o app subir: base_path() não existe ainda.
        $file = dirname(__DIR__, 3).'/tests/contracts/path-guard-cases.json';
        self::assertFileExists($file);

        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    private static function section(string $name): array
    {
        $cases = [];
        foreach (self::vector()[$name] as $i => $case) {
            $label = $name.'#'.$i.' '.json_encode($case['path'] ?? $case['name'], JSON_INVALID_UTF8_SUBSTITUTE);
            $cases[$label] = [$case];
        }

        return $cases;
    }

    public static function directoryCases(): array
    {
        return self::section('directories');
    }

    public static function fileCases(): array
    {
        return self::section('files');
    }

    public static function insideCases(): array
    {
        return self::section('inside');
    }

    public static function blockedCases(): array
    {
        return self::section('blocked_extensions');
    }

    #[DataProvider('directoryCases')]
    public function test_shared_vector_directories(array $case): void
    {
        $reason = PathGuard::validateDirectory($case['path']);
        $this->assertSame($case['ok'], $reason === null, 'diretório: '.json_encode($case['path']).' motivo='.json_encode($reason));
        $this->assertSame($case['why'], $case['ok'] ? 'ok' : PathGuard::directoryReason($case['path']));
    }

    #[DataProvider('fileCases')]
    public function test_shared_vector_files(array $case): void
    {
        $reason = PathGuard::validateFileName($case['name']);
        $this->assertSame($case['ok'], $reason === null, 'nome: '.json_encode($case['name']).' motivo='.json_encode($reason));
        $this->assertSame($case['why'], $case['ok'] ? 'ok' : PathGuard::fileNameReason($case['name']));
    }

    #[DataProvider('insideCases')]
    public function test_shared_vector_inside(array $case): void
    {
        $this->assertSame($case['ok'], PathGuard::isInside($case['path'], $case['dirs']), json_encode($case));
    }

    #[DataProvider('blockedCases')]
    public function test_shared_vector_blocked_extensions(array $case): void
    {
        $this->assertSame($case['blocked'], PathGuard::hasBlockedExtension($case['name']), json_encode($case['name']));
    }

    public function test_inside_folds_case_for_ascii_only(): void
    {
        $dirs = ['C:\\Dados\\Inbox'];
        $this->assertTrue(PathGuard::isInside('c:\\DADOS\\inbox\\a.txt', $dirs));
        // ı (U+0131), ſ (U+017F) e o Kelvin (U+212A) NÃO equivalem a I, S, K no NTFS.
        $this->assertFalse(PathGuard::isInside("C:\\Dados\\\u{0131}nbox\\a.txt", $dirs));
        $this->assertFalse(PathGuard::isInside("C:\\Dado\u{017F}\\Inbox\\a.txt", $dirs));
        $this->assertFalse(PathGuard::isInside("C:\\Dados\\\u{212A}ilo\\a.txt", ['C:\\Dados\\kilo']));
        // Não ASCII é comparado exato: "É" x "é" é recusado (lado seguro).
        $this->assertFalse(PathGuard::isInside("C:\\Dados\\\u{00C9}den\\a", ["C:\\Dados\\\u{00E9}den"]));
        $this->assertTrue(PathGuard::isInside("C:\\DADOS\\\u{00E9}den\\a", ["C:\\Dados\\\u{00E9}den"]));
    }

    public function test_allowed_characters_are_only_letters_digits_space_and_dot_dash_underscore_parentheses(): void
    {
        foreach (str_split('[]&+,\'#@!={}%$;^~') as $char) {
            $this->assertNotNull(PathGuard::validateFileName("a{$char}b.txt"), $char);
            $this->assertNotNull(PathGuard::validateDirectory("C:\\a{$char}b"), $char);
        }
        $this->assertNull(PathGuard::validateFileName('Relatório (final)-2_v1.0 ok.txt'));
        $this->assertNull(PathGuard::validateFileName("relato\u{0301}rio.txt"));   // NFD compõe em NFC
    }

    public function test_vector_covers_every_section_with_enough_cases(): void
    {
        $vector = self::vector();
        $this->assertSame(['directories', 'files', 'inside', 'blocked_extensions'], array_keys($vector));
        foreach ($vector as $section => $cases) {
            $this->assertGreaterThanOrEqual(30, count($cases), $section);
        }
    }

    public function test_messages_exist_for_every_reason_code_in_the_vector(): void
    {
        foreach (['directories', 'files'] as $section) {
            foreach (self::vector()[$section] as $case) {
                if ($case['ok']) {
                    continue;
                }
                $this->assertNotSame($case['why'], PathGuard::message($case['why']), 'sem mensagem para '.$case['why']);
            }
        }
    }

    public function test_validate_returns_a_portuguese_message_not_the_code(): void
    {
        $this->assertSame(PathGuard::message('dotdot'), PathGuard::validateDirectory('C:\\a\\..\\b'));
        $this->assertNotNull(PathGuard::validateFileName('CON'));
    }

    public function test_invalid_utf8_is_rejected(): void
    {
        $this->assertNotNull(PathGuard::validateDirectory("C:\\a\\\xC3\x28"));
        $this->assertNotNull(PathGuard::validateFileName("a\xC3\x28.txt"));
        $this->assertFalse(PathGuard::isInside("C:\\Dados\\Inbox\\\xC3\x28", ['C:\\Dados\\Inbox']));
        $this->assertTrue(PathGuard::hasBlockedExtension("a.exe\xC3\x28"));   // na dúvida, bloqueia
        $this->assertTrue(PathGuard::forbiddenPolicyDirectory("C:\\a\\\xC3\x28"));
    }

    public function test_normalize_name_returns_nfc(): void
    {
        $nfd = "relato\u{0301}rio.pdf";
        $nfc = "relat\u{00F3}rio.pdf";
        $this->assertSame($nfc, PathGuard::normalizeName($nfd));
        $this->assertSame($nfc, PathGuard::normalizeName($nfc));
        $this->assertSame('a.txt', PathGuard::normalizeName('a.txt'));
    }

    public function test_normalize_name_refuses_invalid_encoding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PathGuard::normalizeName("a\xC3\x28");
    }

    public function test_forbidden_policy_directories(): void
    {
        foreach (['C:\\', 'D:\\', 'c:\\', 'C:\\Windows', 'C:\\Windows\\System32', 'C:\\WINDOWS\\x', 'C:\\Windows.old', 'C:\\Program Files',
            'C:\\Program Files (x86)\\x', 'C:\\program files\\Toolit', 'C:\\Users', 'c:\\users', 'D:\\Windows\\x',
            // "C:\Windows*" é prefixo por decisão do brief.
            'C:\\WindowsData'] as $dir) {
            $this->assertTrue(PathGuard::forbiddenPolicyDirectory($dir), $dir);
        }

        // Inválido também é proibido (falha segura).
        foreach (['', '..\\x', 'C:\\a\\..\\Windows', '\\\\srv\\share', 'C:/Windows'] as $dir) {
            $this->assertTrue(PathGuard::forbiddenPolicyDirectory($dir), $dir);
        }

        foreach (['C:\\Users\\Publico\\Inbox', 'C:\\Users\\Publico', 'C:\\ProgramData\\Toolit\\Inbox', 'C:\\Dados\\Windows', 'C:\\Dados\\Inbox'] as $dir) {
            $this->assertFalse(PathGuard::forbiddenPolicyDirectory($dir), $dir);
        }
    }

    public function test_join_builds_a_normalized_path(): void
    {
        $this->assertSame('C:\\Dados\\Inbox\\a.txt', PathGuard::join('C:\\Dados\\Inbox', 'a.txt'));
        $this->assertSame('C:\\a.txt', PathGuard::join('C:\\', 'a.txt'));
        $this->assertSame("C:\\Dados\\relat\u{00F3}rio.pdf", PathGuard::join('C:\\Dados', "relato\u{0301}rio.pdf"));
        $this->assertTrue(PathGuard::isInside(PathGuard::join('C:\\Dados\\Inbox', 'a.txt'), ['C:\\Dados\\Inbox']));
    }

    public function test_join_never_escapes(): void
    {
        $hostile = ['..', '.', '..\\x', '../x', 'a\\b', 'a/b', 'C:\\x', '\\\\srv\\x', 'a:b', 'CON', 'nul.txt', 'a.', 'a ', '', "a\u{FF0F}b",
            "a\u{2215}b", "a\u{202E}b", "a\u{200B}b", 'PROGRA~1', str_repeat('a', 121)];
        foreach ($hostile as $name) {
            try {
                $joined = PathGuard::join('C:\\Dados\\Inbox', $name);
                $this->fail('join aceitou '.json_encode($name).' => '.$joined);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['..\\x', 'C:\\a\\..\\b', '\\\\srv\\x', 'C:\\a\\', '', 'C:/a'] as $dir) {
            try {
                PathGuard::join($dir, 'a.txt');
                $this->fail('join aceitou o diretório '.json_encode($dir));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_join_refuses_a_result_longer_than_260(): void
    {
        $dir = 'C:\\'.str_repeat('a', 120).'\\'.str_repeat('b', 120).'\\'.str_repeat('c', 10);   // 255
        $this->assertSame(255, strlen($dir));
        $this->assertSame($dir.'\\abcd', PathGuard::join($dir, 'abcd'));
        $this->expectException(InvalidArgumentException::class);
        PathGuard::join($dir, 'abcde');
    }

    public function test_blocked_extensions_constant_matches_the_spec(): void
    {
        $this->assertSame(
            ['exe', 'dll', 'bat', 'cmd', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'msi', 'msp', 'scr', 'com', 'pif', 'lnk', 'hta', 'jar', 'cpl', 'reg', 'sys', 'drv'],
            PathGuard::BLOCKED_EXTENSIONS,
        );
    }
}
