<?php

$mapaCargosTexto = (string) env(
    'ASSINATURA_CARGO_MAP',
    'Secretário(a) Escolar:signer1,Diretor(a):signer2,Emissor:signer1,Responsável técnico:signer1,Gestor(a) da unidade:signer2,Separação:signer1,Aceite:signer2,Entrega:signer3,Conferência:signer4'
);

$mapaCargosEnv = [];
foreach (explode(',', $mapaCargosTexto) as $par) {
    $par = trim($par);
    if ($par === '' || ! str_contains($par, ':')) {
        continue;
    }
    [$cargo, $account] = array_map('trim', explode(':', $par, 2));
    if ($cargo !== '' && $account !== '') {
        $mapaCargosEnv[$cargo] = $account;
    }
}

return [
    'ativo' => (bool) env('ASSINATURA_DIGITAL_ATIVO', true),

    'libresign' => [
        'base_url' => rtrim((string) env('LIBRESIGN_BASE_URL', 'http://localhost:8088'), '/'),
        'public_url' => (string) env('LIBRESIGN_PUBLIC_URL', ''),
        'user' => (string) env('LIBRESIGN_USER', 'ieducar'),
        'password' => (string) env('LIBRESIGN_PASSWORD', 'Ieducar-Dev!'),
        'verify_ssl' => filter_var(env('LIBRESIGN_VERIFY_SSL', false), FILTER_VALIDATE_BOOL),
        'timeout' => (int) env('LIBRESIGN_TIMEOUT', 60),
        // Contas Nextcloud usadas nos testes e como fallback por posição (1..N)
        'signer1' => (string) env('LIBRESIGN_SIGNER1', 'signer1'),
        'signer1_password' => (string) env('LIBRESIGN_SIGNER1_PASSWORD', 'Signer1-Dev!'),
        'signer2' => (string) env('LIBRESIGN_SIGNER2', 'signer2'),
        'signer2_password' => (string) env('LIBRESIGN_SIGNER2_PASSWORD', 'Signer2-Dev!'),
        'signer3' => (string) env('LIBRESIGN_SIGNER3', 'signer3'),
        'signer3_password' => (string) env('LIBRESIGN_SIGNER3_PASSWORD', 'Signer3-Dev!'),
        'signer4' => (string) env('LIBRESIGN_SIGNER4', 'signer4'),
        'signer4_password' => (string) env('LIBRESIGN_SIGNER4_PASSWORD', 'Signer4-Dev!'),
        /*
        | Rodapé no PDF assinado (LibreSign/mPDF).
        | QR grande sobrepõe o documento e pode gerar página extra — desligado por padrão.
        | Destaque só em "Signed by LibreSign".
        */
        'footer_enabled' => filter_var(env('LIBRESIGN_FOOTER_ENABLED', true), FILTER_VALIDATE_BOOL),
        'footer_qrcode' => filter_var(env('LIBRESIGN_FOOTER_QRCODE', false), FILTER_VALIDATE_BOOL),
        'footer_signed_by' => (string) env('LIBRESIGN_FOOTER_SIGNED_BY', 'Signed by LibreSign'),
        'footer_validate_in' => (string) env('LIBRESIGN_FOOTER_VALIDATE_IN', ''),
        /*
        | Empurrar rodapé via API (policies + provisioning_api).
        | null/auto = só em hosts locais (Docker). Em cloud.libresign.coop o Nextcloud
        | aplica bruteforce/429 se várias configs forem gravadas em sequência.
        | false = nunca via API (use occ/bootstrap ou painel da instância).
        | true = sempre tenta (com cache e abort imediato em 429).
        */
        'footer_via_api' => env('LIBRESIGN_FOOTER_VIA_API'),
        'admin_user' => (string) env('LIBRESIGN_ADMIN_USER', 'admin'),
        'admin_password' => (string) env('LIBRESIGN_ADMIN_PASSWORD', 'admin'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mapa cargo institucional → conta LibreSign
    |--------------------------------------------------------------------------
    | Texto editável na UI (Configurações → Módulos) e array parseado em runtime.
    */
    'mapa_cargos_texto' => $mapaCargosTexto,
    'mapa_cargos' => $mapaCargosEnv,

    /*
    |--------------------------------------------------------------------------
    | Mapa login i-Educar → conta LibreSign
    |--------------------------------------------------------------------------
    | Ex.: admin:signer1,secretaria:signer2
    */
    'mapa_usuarios_texto' => (string) env('ASSINATURA_USUARIO_MAP', ''),
    'mapa_usuarios' => (static function (): array {
        $texto = (string) env('ASSINATURA_USUARIO_MAP', '');
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
    })(),
    /*
    | login_ou_conta:senha — senhas LibreSign por usuário (não commitar em produção).
    | Ex.: admin:Signer1-Dev!,secretaria:Signer2-Dev!
    */
    'mapa_usuarios_senhas_texto' => (string) env('ASSINATURA_USUARIO_SENHA_MAP', ''),
    'mapa_usuarios_senhas' => (static function (): array {
        $texto = (string) env('ASSINATURA_USUARIO_SENHA_MAP', '');
        $out = [];
        foreach (explode(',', $texto) as $par) {
            $par = trim($par);
            if ($par === '' || ! str_contains($par, ':')) {
                continue;
            }
            [$login, $senha] = array_map('trim', explode(':', $par, 2));
            if ($login !== '' && $senha !== '') {
                $out[mb_strtolower($login)] = $senha;
            }
        }

        return $out;
    })(),
    'usar_login_como_conta' => filter_var(env('ASSINATURA_USAR_LOGIN_COMO_CONTA', true), FILTER_VALIDATE_BOOL),
    // Após a ação no i-Educar, tenta clickToSign no LibreSign (como o CLI de testes).
    'assinar_imediatamente' => filter_var(env('ASSINATURA_ASSINAR_IMEDIATAMENTE', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Fila por usuário (painel + notificações)
    |--------------------------------------------------------------------------
    | Quando há signatários com user_id diferentes do solicitante, o pedido
    | fica pendente para cada um confirmar/assinar no LibreSign.
    */
    'fila_usuario' => filter_var(env('ASSINATURA_FILA_USUARIO', true), FILTER_VALIDATE_BOOL),

    'armazenamento' => [
        // true = nunca grava o PDF no storage do i-Educar (proxy sob demanda no LibreSign)
        'somente_libresign' => filter_var(env('ASSINATURA_SOMENTE_LIBRESIGN', true), FILTER_VALIDATE_BOOL),
    ],

    'validacao' => [
        'habilitado' => filter_var(env('ASSINATURA_VALIDACAO_PUBLICA', true), FILTER_VALIDATE_BOOL),
        'tipo_documento' => 'assinatura_digital_libresign',
    ],
];
