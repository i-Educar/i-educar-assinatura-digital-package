<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Support;

final class Identidade
{
    /** @return array{nome:string,descricao:string,cor:string,destaque:string,clara:string,icone:string} */
    public static function tokens(): array
    {
        $base = [
            'nome' => 'Assinatura digital',
            'descricao' => 'LibreSign · PDFs e validação pública',
            'cor' => '#0f4c5c',
            'destaque' => '#1a7a8c',
            'clara' => '#e6f4f6',
            'icone' => 'fa-pencil-square-o',
        ];

        if (class_exists(\iEducar\Packages\BuritiLagunaAmbiente\Support\CatalogoModulos::class)) {
            $visual = \iEducar\Packages\BuritiLagunaAmbiente\Support\CatalogoModulos::visual('assinatura');
            if (is_array($visual)) {
                return [
                    'nome' => 'Assinatura digital',
                    'descricao' => (string) ($visual['descricao'] ?? $base['descricao']),
                    'cor' => (string) ($visual['cor'] ?? $base['cor']),
                    'destaque' => (string) ($visual['destaque'] ?? $base['destaque']),
                    'clara' => (string) ($visual['clara'] ?? $base['clara']),
                    'icone' => (string) ($visual['icone'] ?? $base['icone']),
                ];
            }
        }

        return $base;
    }
}
