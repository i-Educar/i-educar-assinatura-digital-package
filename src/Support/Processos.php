<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Support;

final class Processos
{
    public const int RAIZ = 881700;

    public const int PAINEL = 881701;

    public const int PEDIDOS = 881702;

    public const int TESTES = 881703;

    public const int CONFIG = 881704;

    /** @return array<int, string> */
    public static function titulos(): array
    {
        return [
            self::RAIZ => 'Assinatura digital',
            self::PAINEL => 'Painel',
            self::PEDIDOS => 'Pedidos',
            self::TESTES => 'Testes LibreSign',
            self::CONFIG => 'Configurações',
        ];
    }
}
