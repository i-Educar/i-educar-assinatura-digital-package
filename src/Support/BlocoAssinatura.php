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

        $blocos = '';
        foreach ($assinaturas as $a) {
            $blocos .= self::umHtml($a, $cor);
        }

        return '<section class="assinaturas ls-assinaturas">'
            .'<h2 style="color:'.$e($cor).';font-size:.9rem;margin:0 0 6px;text-transform:uppercase;letter-spacing:.04em;">'
            .$e($titulo)
            .'</h2>'
            .'<div class="grade-assina ls-grade-assina">'.$blocos.'</div>'
            .'</section>';
    }

    /**
     * @param  array<string,mixed>  $a
     */
    public static function umHtml(array $a, string $cor = '#0a7a8a'): string
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
            $label = 'Conferir autenticidade';
            $detalhe = $codigo !== '' ? ' · '.$codigo : '';
            if ($url !== '') {
                $autenticidade = '<a class="ls-auth" href="'.$e($url).'" style="display:inline-block;margin-top:6px;font-size:.72rem;color:'.$e($cor).';text-decoration:none;border-bottom:1px dotted '.$e($cor).';">'
                    .$e($label.$detalhe)
                    .'</a>';
            } else {
                $autenticidade = '<span class="ls-auth" style="display:inline-block;margin-top:6px;font-size:.72rem;color:'.$e($cor).';">'
                    .$e($label.$detalhe)
                    .'</span>';
            }
        }

        return '<div class="assina ls-assina" style="text-align:center;padding-top:36px;">'
            .'<div class="linha-assina" style="border-top:1px solid #1a242b;margin:0 18px 8px;height:0;"></div>'
            .'<strong style="display:block;font-size:.88rem;color:#1a242b;">'.$e($nome !== '' ? $nome : '________________').'</strong>'
            .($cargo !== '' ? '<span style="display:block;color:#5b6b76;font-size:.78rem;line-height:1.35;">'.$e($cargo).'</span>' : '')
            .($quando !== '' ? '<small style="display:block;color:#5b6b76;font-size:.72rem;">'.$e($quando).'</small>' : '')
            .($status !== '' ? '<small style="display:block;color:#5b6b76;font-size:.7rem;">'.$e($status).'</small>' : '')
            .$autenticidade
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
