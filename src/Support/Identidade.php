<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Support;

final class Identidade
{
    /** @return array{nome:string,cor:string,destaque:string,clara:string,icone:string} */
    public static function tokens(): array
    {
        if (class_exists(\iEducar\Packages\BuritiLagunaAmbiente\Support\CatalogoModulos::class)) {
            $visual = \iEducar\Packages\BuritiLagunaAmbiente\Support\CatalogoModulos::visual('assinatura');
            if (is_array($visual)) {
                return [
                    'nome' => 'Assinatura digital',
                    'cor' => (string) ($visual['cor'] ?? '#0f4c5c'),
                    'destaque' => '#1a7a8c',
                    'clara' => '#e6f4f6',
                    'icone' => (string) ($visual['icone'] ?? 'fa-pencil-square-o'),
                ];
            }
        }

        return [
            'nome' => 'Assinatura digital',
            'cor' => '#0f4c5c',
            'destaque' => '#1a7a8c',
            'clara' => '#e6f4f6',
            'icone' => 'fa-pencil-square-o',
        ];
    }
}
