<?php

/**
 * Smoke CLI (sem PHPUnit): BlocoAssinatura + orquestrador map + PDF demo.
 * Testes HTTP fake: Feature/AssinaturaUnicaEMultiplaTest.php via phpunit.
 */

declare(strict_types=1);

$ok = 0;
$falhas = 0;

$assert = static function (bool $cond, string $msg) use (&$ok, &$falhas): void {
    if ($cond) {
        echo "  OK  {$msg}\n";
        $ok++;
    } else {
        echo "  FAIL  {$msg}\n";
        $falhas++;
    }
};

require dirname(__DIR__).'/src/Support/BlocoAssinatura.php';

use iEducar\Packages\BuritiAssinaturaDigital\Support\BlocoAssinatura;

$html = BlocoAssinatura::gradeHtml([
    ['nome' => 'Ana', 'cargo' => 'Responsável técnico', 'codigo_validacao' => 'AAA111', 'url_validacao' => 'https://ls/validation/u1'],
    ['nome' => 'Bruno', 'cargo' => 'Gestor(a) da unidade', 'codigo_validacao' => 'BBB222', 'url_validacao' => 'https://ls/validation/u1'],
]);

$assert(str_contains($html, 'Conferir autenticidade'), 'bloco contém Conferir autenticidade');
$assert(substr_count($html, 'Conferir autenticidade') >= 2, 'duas assinaturas com autenticidade');
$assert(str_contains($html, 'AAA111') && str_contains($html, 'BBB222'), 'códigos individuais no bloco');
$assert(str_contains($html, 'https://ls/validation/u1'), 'URL LibreSign no bloco');

$enriquecido = BlocoAssinatura::comAutenticidade(
    [['nome' => 'X', 'cargo' => 'Y']],
    'ZZZ',
    'https://v/ZZZ',
);
$assert(($enriquecido[0]['codigo_validacao'] ?? '') === '', 'comAutenticidade não copia código do documento');

$comLs = BlocoAssinatura::comUrlLibreSign(
    [['nome' => 'X', 'cargo' => 'Y', 'codigo_validacao' => 'C1']],
    'http://localhost:8088/apps/libresign/validation/abc',
);
$assert(($comLs[0]['url_validacao'] ?? '') === 'http://localhost:8088/apps/libresign/validation/abc', 'comUrlLibreSign aplica URL');
$assert(($comLs[0]['codigo_validacao'] ?? '') === 'C1', 'comUrlLibreSign preserva código individual');

$b64 = dirname(__DIR__).'/resources/pdf/demo-assinatura.pdf.b64';
$assert(is_file($b64), 'PDF demo b64 presente');
$bin = base64_decode((string) file_get_contents($b64), true);
$assert(is_string($bin) && str_starts_with($bin, '%PDF'), 'PDF demo válido');

echo "\nResultado: {$ok} ok, {$falhas} falhas\n";
exit($falhas > 0 ? 1 : 0);
