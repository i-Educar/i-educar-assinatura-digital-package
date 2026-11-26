<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Support;

final class MenuFluxo
{
    /**
     * @param  array<string, bool>  $permite
     * @return list<array{titulo:string,itens:list<array{chave:string,rotulo:string,url:string,icone:string}>}>
     */
    public static function grupos(array $permite = []): array
    {
        $itens = [];
        if ($permite['painel'] ?? true) {
            $itens[] = ['chave' => 'painel', 'rotulo' => 'Painel', 'url' => '/assinatura-digital', 'icone' => 'fa-th-large'];
        }
        if ($permite['pedidos'] ?? true) {
            $itens[] = ['chave' => 'pedidos', 'rotulo' => 'Pedidos', 'url' => '/assinatura-digital/pedidos', 'icone' => 'fa-list'];
        }
        if ($permite['testes'] ?? true) {
            $itens[] = ['chave' => 'testes', 'rotulo' => 'Testes LibreSign', 'url' => '/assinatura-digital/testes', 'icone' => 'fa-flask'];
        }

        return [
            [
                'titulo' => 'Assinatura',
                'itens' => $itens,
            ],
        ];
    }
}
