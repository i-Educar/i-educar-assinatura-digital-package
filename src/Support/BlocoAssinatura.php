<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Support;

/**
 * HTML minimalista sob cada assinatura: nome, cargo e “Conferir autenticidade”.
 * Código/URL sob a assinatura são sempre do signatário (nunca o código do documento).
 */
final class BlocoAssinatura
{
    /**
     * @param  list<array{
     *   nome?:string,
     *   cargo?:string,
     *   quando?:?string,
     *   account?:string,
     *   email?:string,
     *   displayName?:string,
     *   codigo_validacao?:?string,
     *   url_validacao?:?string,
     *   status?:?string
     * }>  $assinaturas
     * @param  array{cor?:string,titulo?:string}  $opcoes
     */
    public static function gradeHtml(array $assinaturas, array $opcoes = []): string
    {
        if ($assinaturas === []) {
            return '';
        }

        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cor = (string) ($opcoes['cor'] ?? '#0a7a8a');
        $titulo = (string) ($opcoes['titulo'] ?? 'Assinaturas');
        $n = count($assinaturas);
        // Dompdf: tabela em 1 linha (até 4) cabe melhor em A4 do que grid 2×2 com muito padding.
        $cols = (int) ($opcoes['colunas'] ?? 0);
        if ($cols < 1) {
            $cols = min(4, max(1, $n));
        }
        $cols = max(1, min(4, $cols));
        $pct = rtrim(rtrim(number_format(100 / $cols, 2, '.', ''), '0'), '.').'%';

        $linhas = '';
        foreach (array_chunk($assinaturas, $cols) as $chunk) {
            $celulas = '';
            foreach ($chunk as $a) {
                $celulas .= '<td class="assina ls-assina" style="width:'.$pct.';vertical-align:top;padding:10px 6px 0;text-align:center;word-break:break-word;">'
                    .self::umHtml($a, $cor, true)
                    .'</td>';
            }
            for ($i = count($chunk); $i < $cols; $i++) {
                $celulas .= '<td style="width:'.$pct.';border:0;padding:0;"></td>';
            }
            $linhas .= '<tr>'.$celulas.'</tr>';
        }

        return '<section class="assinaturas ls-assinaturas" style="margin:14px 0 6px;">'
            .'<h2 style="color:'.$e($cor).';font-size:.82rem;margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em;">'
            .$e($titulo)
            .'</h2>'
            .'<table class="grade-assina ls-grade-assina" style="width:100%;border-collapse:collapse;table-layout:fixed;">'
            .$linhas
            .'</table>'
            .'</section>';
    }

    /**
     * @param  array<string,mixed>  $a
     */
    public static function umHtml(array $a, string $cor = '#0a7a8a', bool $somenteConteudo = false): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $nome = trim((string) ($a['nome'] ?? $a['displayName'] ?? ''));
        $cargo = trim((string) ($a['cargo'] ?? ''));
        $quando = trim((string) ($a['quando'] ?? ''));
        $status = trim((string) ($a['status'] ?? ''));
        // Nunca herda código/URL do documento — só o do signatário.
        $codigo = trim((string) ($a['codigo_validacao'] ?? ''));
        $url = trim((string) ($a['url_validacao'] ?? ''));

        $autenticidade = '';
        if ($codigo !== '' || $url !== '') {
            $label = 'Conferir';
            $detalhe = $codigo !== '' ? ' · '.$codigo : '';
            if ($url !== '') {
                $autenticidade = '<a class="ls-auth" href="'.$e($url).'" style="display:inline-block;margin-top:4px;font-size:.65rem;color:'.$e($cor).';text-decoration:none;border-bottom:1px dotted '.$e($cor).';word-break:break-all;">'
                    .$e($label.$detalhe)
                    .'</a>';
            } else {
                $autenticidade = '<span class="ls-auth" style="display:inline-block;margin-top:4px;font-size:.65rem;color:'.$e($cor).';word-break:break-all;">'
                    .$e($label.$detalhe)
                    .'</span>';
            }
        }

        $inner = '<div class="linha-assina" style="border-top:1px solid #1a242b;margin:0 10px 6px;height:0;"></div>'
            .'<strong style="display:block;font-size:.78rem;line-height:1.25;color:#1a242b;word-break:break-word;">'.$e($nome !== '' ? $nome : '________________').'</strong>'
            .($cargo !== '' ? '<span style="display:block;color:#5b6b76;font-size:.7rem;line-height:1.25;word-break:break-word;">'.$e($cargo).'</span>' : '')
            .($quando !== '' ? '<small style="display:block;color:#5b6b76;font-size:.65rem;line-height:1.2;">'.$e($quando).'</small>' : '')
            .($status !== '' ? '<small style="display:block;color:#5b6b76;font-size:.62rem;line-height:1.2;">'.$e($status).'</small>' : '')
            .$autenticidade;

        if ($somenteConteudo) {
            return $inner;
        }

        return '<div class="assina ls-assina" style="text-align:center;padding-top:22px;word-break:break-word;">'
            .$inner
            .'</div>';
    }

    /**
     * NÃO copia o código do documento para as assinaturas.
     * Mantido por compatibilidade: só preserva códigos/URLs já individuais.
     *
     * @param  list<array<string,mixed>>  $assinaturas
     * @return list<array<string,mixed>>
     */
    public static function comAutenticidade(array $assinaturas, ?string $codigo = null, ?string $url = null): array
    {
        // $codigo/$url do documento são ignorados de propósito.
        unset($codigo, $url);

        return array_values(array_map(static function (array $row): array {
            return $row;
        }, $assinaturas));
    }

    /**
     * Aplica URL do validador LibreSign em cada signatário que já tem identidade.
     *
     * @param  list<array<string,mixed>>  $assinaturas
     * @return list<array<string,mixed>>
     */
    public static function comUrlLibreSign(array $assinaturas, string $urlLibreSign): array
    {
        $urlLibreSign = trim($urlLibreSign);
        if ($urlLibreSign === '') {
            return $assinaturas;
        }

        $out = [];
        foreach ($assinaturas as $a) {
            $row = $a;
            $nome = trim((string) ($row['nome'] ?? $row['displayName'] ?? ''));
            if ($nome !== '') {
                $row['url_validacao'] = $urlLibreSign;
            }
            $out[] = $row;
        }

        return $out;
    }
}
