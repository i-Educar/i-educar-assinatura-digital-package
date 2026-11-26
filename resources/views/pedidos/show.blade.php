@extends('assinatura::layout')

@section('titulo', $pedido['titulo'] ?? 'Pedido')

@section('conteudo')
<div class="cabeca-tela">
    <div class="identidade-tela">
        <div class="marca-tela"><i class="fa fa-file-text-o"></i></div>
        <div>
            <p class="titulo-modulo">Pedido #{{ $pedido['id'] }}</p>
            <h1>{{ $pedido['titulo'] }}</h1>
            <p class="lead">Cenário {{ $pedido['cenario'] }} · status {{ $pedido['status'] }}</p>
        </div>
    </div>
    <div class="acoes-tela">
        @if(($pedido['status'] ?? '') === 'assinado' || ($pedido['status_libresign'] ?? 0) == 3)
            <a class="botao" href="{{ url('/assinatura-digital/pedidos/'.$pedido['id'].'/ver') }}" target="_blank"><i class="fa fa-eye"></i> Ver PDF</a>
            <a class="botao claro" href="{{ url('/assinatura-digital/pedidos/'.$pedido['id'].'/baixar') }}"><i class="fa fa-download"></i> Baixar</a>
        @endif
        <form method="post" action="{{ url('/assinatura-digital/pedidos/'.$pedido['id'].'/sincronizar') }}">
            @csrf
            <button class="botao claro" type="submit"><i class="fa fa-refresh"></i> Sincronizar</button>
        </form>
    </div>
</div>

<div class="grade-2">
    <div class="bloco">
        <h2>LibreSign</h2>
        <p>UUID: <code>{{ $pedido['libresign_uuid'] ?? '—' }}</code></p>
        <p>File id: <code>{{ $pedido['libresign_file_id'] ?? '—' }}</code></p>
        <p>Status LS: <code>{{ $pedido['status_libresign'] ?? '—' }}</code></p>
        <p class="texto-suave">Arquivo físico somente no Nextcloud/LibreSign.</p>
    </div>
    <div class="bloco">
        <h2>Validação pública</h2>
        @if(!empty($pedido['codigo_validacao']))
            <p>Código: <strong>{{ $pedido['codigo_validacao'] }}</strong></p>
            <p><a href="{{ $pedido['url_validacao'] }}">Abrir validação</a></p>
            <p class="texto-suave">Também em <code>/exportacao/validar</code>.</p>
        @else
            <p class="texto-suave">Disponível quando o fluxo estiver totalmente assinado (status 3).</p>
        @endif
    </div>
</div>

<div class="bloco">
    <h2>Signatários</h2>
    <ul>
        @foreach(($pedido['signatarios'] ?? []) as $s)
            <li>{{ $s['displayName'] ?? ($s['account'] ?? $s['email'] ?? '—') }}
                @if(!empty($s['account'])) <code>{{ $s['account'] }}</code> @endif
            </li>
        @endforeach
    </ul>
</div>
@endsection
