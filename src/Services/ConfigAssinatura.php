<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Services;

use Illuminate\Support\Facades\Config;

/**
 * Aplica/lê configurações do módulo (LibreSign + regras) a partir das diretivas Laguna.
 */
final class ConfigAssinatura
{
    /**
     * @param  array<string, mixed>  $valores  chaves das diretivas (libresign_base_url, …)
     */
    public static function aplicar(bool $ativo, array $valores): void
    {
        Config::set('assinatura.ativo', $ativo);

        $base = rtrim((string) ($valores['libresign_base_url'] ?? config('assinatura.libresign.base_url', '')), '/');
        if ($base !== '') {
            Config::set('assinatura.libresign.base_url', $base);
        }

        foreach ([
            'libresign_user' => 'assinatura.libresign.user',
            'libresign_password' => 'assinatura.libresign.password',
            'signer1' => 'assinatura.libresign.signer1',
            'signer1_password' => 'assinatura.libresign.signer1_password',
            'signer2' => 'assinatura.libresign.signer2',
            'signer2_password' => 'assinatura.libresign.signer2_password',
            'signer3' => 'assinatura.libresign.signer3',
            'signer3_password' => 'assinatura.libresign.signer3_password',
            'signer4' => 'assinatura.libresign.signer4',
            'signer4_password' => 'assinatura.libresign.signer4_password',
        ] as $chave => $cfg) {
            if (! array_key_exists($chave, $valores)) {
                continue;
            }
            $v = trim((string) $valores[$chave]);
            // Vazio = manter padrão/.env (não apagar signer3/4 nem senhas).
            if ($v === '') {
                continue;
            }
            Config::set($cfg, $v);
        }

        if (array_key_exists('libresign_verify_ssl', $valores)) {
            Config::set('assinatura.libresign.verify_ssl', (bool) $valores['libresign_verify_ssl']);
        }
        if (array_key_exists('libresign_timeout', $valores)) {
            Config::set('assinatura.libresign.timeout', max(5, min(300, (int) $valores['libresign_timeout'])));
        }
        if (array_key_exists('somente_libresign', $valores)) {
            Config::set('assinatura.armazenamento.somente_libresign', (bool) $valores['somente_libresign']);
        }
        if (array_key_exists('validacao_publica', $valores)) {
            Config::set('assinatura.validacao.habilitado', (bool) $valores['validacao_publica']);
        }
        if (array_key_exists('exportacao_libresign', $valores)) {
            $lsDocs = (bool) $valores['exportacao_libresign'];
            Config::set('exportacao.assinatura_digital.habilitado', $lsDocs);
            // Mesmo interruptor: documentos do Relatórios Avançados também entram no LibreSign.
            Config::set('advanced-reports.assinatura_digital.habilitado', $lsDocs);
        }

        $mapaTexto = (string) ($valores['mapa_cargos'] ?? config('assinatura.mapa_cargos_texto', ''));
        $mapa = self::parseMapaCargos($mapaTexto);
        // Garante etapas do almox com contas distintas (validação LibreSign multi-assinatura).
        foreach ([
            'Separação' => 'signer1',
            'Aceite' => 'signer2',
            'Entrega' => 'signer3',
            'Conferência' => 'signer4',
        ] as $cargo => $acc) {
            if (! isset($mapa[$cargo]) || trim((string) $mapa[$cargo]) === '') {
                $mapa[$cargo] = $acc;
            }
        }
        Config::set('assinatura.mapa_cargos_texto', self::mapaParaTexto($mapa));
        Config::set('assinatura.mapa_cargos', $mapa);

        if (array_key_exists('mapa_usuarios', $valores) || array_key_exists('mapa_usuarios_texto', $valores)) {
            $mapaUsers = (string) ($valores['mapa_usuarios'] ?? $valores['mapa_usuarios_texto'] ?? '');
            Config::set('assinatura.mapa_usuarios_texto', $mapaUsers);
            Config::set('assinatura.mapa_usuarios', self::parseMapaUsuarios($mapaUsers));
        }
        if (array_key_exists('usar_login_como_conta', $valores)) {
            Config::set('assinatura.usar_login_como_conta', (bool) $valores['usar_login_como_conta']);
        }
        if (array_key_exists('assinar_imediatamente', $valores)) {
            Config::set('assinatura.assinar_imediatamente', (bool) $valores['assinar_imediatamente']);
        }
    }

    /** Carrega diretivas salvas (settings) para o runtime no boot. */
    public static function hidratarDoAmbiente(): void
    {
        if (! class_exists(\iEducar\Packages\BuritiLagunaAmbiente\Support\ModuloConfiguracao::class)) {
            // Sem Laguna: ainda normaliza mapas a partir do texto/env do config file.
            $texto = (string) config('assinatura.mapa_cargos_texto', '');
            if ($texto !== '') {
                Config::set('assinatura.mapa_cargos', self::parseMapaCargos($texto));
            }
            $textoUsers = (string) config('assinatura.mapa_usuarios_texto', '');
            if ($textoUsers !== '') {
                Config::set('assinatura.mapa_usuarios', self::parseMapaUsuarios($textoUsers));
            }

            return;
        }

        $ativo = \iEducar\Packages\BuritiLagunaAmbiente\Support\ModuloConfiguracao::ativo('assinatura');
        $salvas = \iEducar\Packages\BuritiLagunaAmbiente\Support\ModuloConfiguracao::diretivasSalvas('assinatura');
        self::aplicar($ativo, $salvas);
    }

    /**
     * Snapshot para UI do módulo (sem expor senhas em claro).
     *
     * @return array<string, mixed>
     */
    public static function estadoAtual(): array
    {
        return [
            'ativo' => (bool) config('assinatura.ativo', true),
            'libresign_base_url' => (string) config('assinatura.libresign.base_url', ''),
            'libresign_user' => (string) config('assinatura.libresign.user', ''),
            'libresign_password_definida' => (string) config('assinatura.libresign.password', '') !== '',
            'libresign_verify_ssl' => (bool) config('assinatura.libresign.verify_ssl', false),
            'libresign_timeout' => (int) config('assinatura.libresign.timeout', 60),
            'signer1' => (string) config('assinatura.libresign.signer1', ''),
            'signer1_password_definida' => (string) config('assinatura.libresign.signer1_password', '') !== '',
            'signer2' => (string) config('assinatura.libresign.signer2', ''),
            'signer2_password_definida' => (string) config('assinatura.libresign.signer2_password', '') !== '',
            'signer3' => (string) config('assinatura.libresign.signer3', ''),
            'signer4' => (string) config('assinatura.libresign.signer4', ''),
            'mapa_cargos' => (string) config('assinatura.mapa_cargos_texto', self::mapaParaTexto((array) config('assinatura.mapa_cargos', []))),
            'mapa_cargos_parseado' => (array) config('assinatura.mapa_cargos', []),
            'mapa_usuarios' => (string) config('assinatura.mapa_usuarios_texto', self::mapaParaTexto((array) config('assinatura.mapa_usuarios', []))),
            'mapa_usuarios_parseado' => (array) config('assinatura.mapa_usuarios', []),
            'usar_login_como_conta' => (bool) config('assinatura.usar_login_como_conta', true),
            'assinar_imediatamente' => (bool) config('assinatura.assinar_imediatamente', true),
            'somente_libresign' => (bool) config('assinatura.armazenamento.somente_libresign', true),
            'validacao_publica' => (bool) config('assinatura.validacao.habilitado', true),
            'exportacao_libresign' => (bool) config('exportacao.assinatura_digital.habilitado', true),
            'advanced_reports_libresign' => (bool) config('advanced-reports.assinatura_digital.habilitado', true),
        ];
    }

    /** @return array<string, string> */
    public static function parseMapaCargos(string $texto): array
    {
        $out = [];
        foreach (explode(',', $texto) as $par) {
            $par = trim($par);
            if ($par === '' || ! str_contains($par, ':')) {
                continue;
            }
            [$cargo, $account] = array_map('trim', explode(':', $par, 2));
            if ($cargo !== '' && $account !== '') {
                $out[$cargo] = $account;
            }
        }

        return $out;
    }

    /** @return array<string, string> login_lower => account */
    public static function parseMapaUsuarios(string $texto): array
    {
        $out = [];
        foreach (explode(',', $texto) as $par) {
            $par = trim($par);
            if ($par === '' || ! str_contains($par, ':')) {
                continue;
            }
            [$login, $account] = array_map('trim', explode(':', $par, 2));
            if ($login !== '' && $account !== '') {
                $out[mb_strtolower($login)] = $account;
            }
        }

        return $out;
    }

    /** @param  array<string, string>  $mapa */
    public static function mapaParaTexto(array $mapa): string
    {
        $parts = [];
        foreach ($mapa as $cargo => $account) {
            $parts[] = $cargo.':'.$account;
        }

        return implode(',', $parts);
    }
}
