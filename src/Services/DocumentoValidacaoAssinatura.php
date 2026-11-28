<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Services;

/**
 * Registra o documento assinado no validador público (Exportação unificada / Advanced Reports).
 * O PDF continua só no LibreSign; o payload da validação aponta para o proxy i-Educar.
 */
final class DocumentoValidacaoAssinatura
{
    /**
     * @param  array<string,mixed>  $pedido
     * @return array{codigo:string,url:string,qr_data_uri:?string,via:string}|null
     */
    public function registrar(array $pedido): ?array
    {
        if (! (bool) config('assinatura.validacao.habilitado', true)) {
            return null;
        }

        if (! class_exists(\iEducar\Packages\BuritiExportacaoUnificada\Services\ValidadorDocumentoBridge::class)) {
            return null;
        }

        $tipo = (string) config('assinatura.validacao.tipo_documento', 'assinatura_digital_libresign');
        $signatarios = is_array($pedido['signatarios'] ?? null) ? $pedido['signatarios'] : [];
        $signatarios = array_map(static function ($s): array {
            if (! is_array($s)) {
                return [];
            }
            unset($s['password']);

            return $s;
        }, $signatarios);

        $payload = [
            'titulo' => (string) ($pedido['titulo'] ?? ''),
            'cenario' => (string) ($pedido['cenario'] ?? ''),
            'libresign_uuid' => (string) ($pedido['libresign_uuid'] ?? ''),
            'libresign_file_id' => (string) ($pedido['libresign_file_id'] ?? ''),
            'pedido_id' => (int) ($pedido['id'] ?? 0),
            'signatarios' => array_values(array_filter($signatarios)),
            'armazenamento' => 'libresign',
            'ver_url' => url('/assinatura-digital/pedidos/'.(int) ($pedido['id'] ?? 0).'/ver'),
            'baixar_url' => url('/assinatura-digital/pedidos/'.(int) ($pedido['id'] ?? 0).'/baixar'),
            'observacao' => 'PDF permanece no LibreSign; i-Educar não grava cópia no storage local.',
        ];

        $bridge = app(\iEducar\Packages\BuritiExportacaoUnificada\Services\ValidadorDocumentoBridge::class);
        $reg = $bridge->registrar($tipo, $payload);

        if (($reg['codigo'] ?? '') === '') {
            return null;
        }

        $codigo = (string) $reg['codigo'];
        // URL canônica do módulo (tela pública LGPD), não a rota interna do validador.
        $url = app(ValidacaoPublicaService::class)->urlPublica($codigo);

        return [
            'codigo' => $codigo,
            'url' => $url,
            'qr_data_uri' => $reg['qr_data_uri'] ?? null,
            'via' => (string) ($reg['via'] ?? ''),
        ];
    }
}
