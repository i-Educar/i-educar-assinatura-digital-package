<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Support;

final class MenuFluxo
{
    /**
     * @param  array<string, bool>  $permite
     * @return list<array{nome:string,itens:list<array{chave:string,rotulo:string,url:string,icone:string}>}>
     */
    public static function grupos(array $permite = []): array
    {
        $itens = [];
        if ($permite['painel'] ?? true) {
            $itens[] = ['chave' => 'painel', 'rotulo' => 'Painel', 'url' => '/assinatura-digital', 'icone' => 'fa-th-large'];
        }
        if ($permite['pendencias'] ?? true) {
            $itens[] = ['chave' => 'pendencias', 'rotulo' => 'Minhas pendências', 'url' => '/assinatura-digital/pendencias', 'icone' => 'fa-pencil-square-o'];
        }
        if ($permite['assinados'] ?? true) {
            $itens[] = ['chave' => 'assinados', 'rotulo' => 'Já assinei', 'url' => '/assinatura-digital/assinados', 'icone' => 'fa-check-square-o'];
        }
        if ($permite['pedidos'] ?? true) {
            $itens[] = ['chave' => 'pedidos', 'rotulo' => 'Pedidos', 'url' => '/assinatura-digital/pedidos', 'icone' => 'fa-list'];
        }
        if ($permite['testes'] ?? true) {
            $itens[] = ['chave' => 'testes', 'rotulo' => 'Testes LibreSign', 'url' => '/assinatura-digital/testes', 'icone' => 'fa-flask'];
        }
        if ($permite['config'] ?? true) {
            $itens[] = ['chave' => 'config', 'rotulo' => 'Configurações', 'url' => '/assinatura-digital/configuracoes', 'icone' => 'fa-sliders'];
        }

        return [
            [
                'nome' => 'Assinatura',
                'itens' => $itens,
            ],
        ];
    }
}
