<?php

return [
    'ativo' => (bool) env('ASSINATURA_DIGITAL_ATIVO', true),

    'libresign' => [
        'base_url' => rtrim((string) env('LIBRESIGN_BASE_URL', 'http://localhost:8088'), '/'),
        'user' => (string) env('LIBRESIGN_USER', 'ieducar'),
        'password' => (string) env('LIBRESIGN_PASSWORD', 'Ieducar-Dev!'),
        'verify_ssl' => filter_var(env('LIBRESIGN_VERIFY_SSL', false), FILTER_VALIDATE_BOOL),
        'timeout' => (int) env('LIBRESIGN_TIMEOUT', 60),
        // Contas Nextcloud usadas nos testes automatizados de assinatura
        'signer1' => (string) env('LIBRESIGN_SIGNER1', 'signer1'),
        'signer1_password' => (string) env('LIBRESIGN_SIGNER1_PASSWORD', 'Signer1-Dev!'),
        'signer2' => (string) env('LIBRESIGN_SIGNER2', 'signer2'),
        'signer2_password' => (string) env('LIBRESIGN_SIGNER2_PASSWORD', 'Signer2-Dev!'),
    ],

    'armazenamento' => [
        // true = nunca grava o PDF no storage do i-Educar (proxy sob demanda no LibreSign)
        'somente_libresign' => filter_var(env('ASSINATURA_SOMENTE_LIBRESIGN', true), FILTER_VALIDATE_BOOL),
    ],

    'validacao' => [
        'habilitado' => filter_var(env('ASSINATURA_VALIDACAO_PUBLICA', true), FILTER_VALIDATE_BOOL),
        'tipo_documento' => 'assinatura_digital_libresign',
    ],
];
