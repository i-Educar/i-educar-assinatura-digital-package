@extends('assinatura::layout')

@section('titulo', 'Configurações — Assinatura digital')
@section('icone-tela', 'fa-sliders')
@section('titulo-tela', 'Configurações')
@section('lead')
    Instância LibreSign, signatários e regras — edição no catálogo Laguna (somente administrador).
@endsection
@section('acoes-topo')
    <a class="botao" href="{{ $urlModulos }}"><i class="fa fa-pencil"></i> Editar no catálogo</a>
    <form method="post" action="{{ url('/assinatura-digital/configuracoes/ping') }}" style="display:inline">
        @csrf
        <button class="botao claro" type="submit"><i class="fa fa-heartbeat"></i> Testar conexão</button>
    </form>
    <form method="post" action="{{ url('/assinatura-digital/configuracoes/reparar-pendentes') }}" style="display:inline"
          onsubmit="return confirm('Reparar assinaturas pendentes e códigos HMAC?');">
        @csrf
        <button class="botao claro" type="submit"><i class="fa fa-wrench"></i> Reparar pendentes</button>
    </form>
    <a class="botao claro" href="{{ url('/assinatura-digital') }}"><i class="fa fa-th-large"></i> Painel</a>
@endsection

@section('conteudo')
@php $cfg = $cfg ?? []; @endphp

<div class="grade-2">
    <div class="bloco">
        <h2><i class="fa fa-cloud"></i> Instância LibreSign</h2>
        <p>
            @if(!empty($libresignOk))
                <span class="badge ok"><i class="fa fa-check"></i> online</span>
            @else
                <span class="badge erro"><i class="fa fa-ban"></i> indisponível</span>
            @endif
            <code>{{ $cfg['libresign_base_url'] ?: '—' }}</code>
        </p>
        <p>Usuário: <strong>{{ $cfg['libresign_user'] ?: '—' }}</strong>
            · senha {{ !empty($cfg['libresign_password_definida']) ? 'definida' : 'não definida' }}
        </p>
        <p class="texto-suave">
            SSL: {{ !empty($cfg['libresign_verify_ssl']) ? 'verificar' : 'não verificar' }}
            · timeout {{ (int) ($cfg['libresign_timeout'] ?? 60) }}s
        </p>
    </div>
    <div class="bloco">
        <h2><i class="fa fa-users"></i> Signatários padrão</h2>
        <p>signer1: <code>{{ $cfg['signer1'] ?: '—' }}</code>
            · senha {{ !empty($cfg['signer1_password_definida']) ? 'definida' : 'não' }}</p>
        <p>signer2: <code>{{ $cfg['signer2'] ?: '—' }}</code>
            · senha {{ !empty($cfg['signer2_password_definida']) ? 'definida' : 'não' }}</p>
        @if(!empty($cfg['signer3']))
            <p>signer3: <code>{{ $cfg['signer3'] }}</code></p>
        @endif
        @if(!empty($cfg['signer4']))
            <p>signer4: <code>{{ $cfg['signer4'] }}</code></p>
        @endif
    </div>
</div>

<div class="bloco">
    <h2><i class="fa fa-random"></i> Mapeamentos</h2>
    <p class="texto-suave" style="margin-top:0">O usuário que assina no i-Educar é resolvido para a conta LibreSign nesta ordem: mapa de login → cargo → signers padrão.</p>
    <p><strong>Login → conta</strong></p>
    <p><code style="display:block;white-space:pre-wrap;padding:10px 12px;">{{ $cfg['mapa_usuarios'] ?: '(vazio — usa login do portal ou fallback de cargo)' }}</code></p>
    <p><strong>Cargo → conta</strong></p>
    <p><code style="display:block;white-space:pre-wrap;padding:10px 12px;">{{ $cfg['mapa_cargos'] ?: '—' }}</code></p>
</div>

<div class="bloco">
    <h2><i class="fa fa-check-square-o"></i> Regras</h2>
    <p>
        PDF só no LibreSign: <strong>{{ !empty($cfg['somente_libresign']) ? 'sim' : 'não' }}</strong>
        · Validação pública: <strong>{{ !empty($cfg['validacao_publica']) ? 'sim' : 'não' }}</strong>
        · Assinar imediatamente: <strong>{{ !empty($cfg['assinar_imediatamente']) ? 'sim' : 'não' }}</strong>
        · Login como conta: <strong>{{ !empty($cfg['usar_login_como_conta']) ? 'sim' : 'não' }}</strong>
        · Exportação → LibreSign: <strong>{{ !empty($cfg['exportacao_libresign']) ? 'sim' : 'não' }}</strong>
    </p>
    <p class="texto-suave">Alterações permanentes: use o catálogo Laguna (botão acima). Esta tela é o resumo operacional.</p>
</div>
@endsection
