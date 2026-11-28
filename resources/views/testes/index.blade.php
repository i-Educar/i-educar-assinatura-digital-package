@extends('assinatura::layout')

@section('titulo', 'Testes LibreSign')
@section('icone-tela', 'fa-flask')
@section('titulo-tela', 'Testes de assinatura')
@section('lead')
    Dois cenários contra o LibreSign local (docker/libresign).
@endsection
@section('acoes-topo')
    <a class="botao claro" href="{{ url('/assinatura-digital') }}"><i class="fa fa-th-large"></i> Painel</a>
    <a class="botao claro" href="{{ url('/assinatura-digital/configuracoes') }}"><i class="fa fa-sliders"></i> Configurações</a>
@endsection

@section('conteudo')
<div class="bloco">
    <h2><i class="fa fa-server"></i> Ambiente</h2>
    @if($libresignOk)
        <p><span class="badge ok"><i class="fa fa-check"></i> LibreSign online</span> <code>{{ $baseUrl }}</code></p>
    @else
        <p><span class="badge erro"><i class="fa fa-ban"></i> LibreSign offline</span> — rode <code>cd docker/libresign && docker compose up -d && ./scripts/bootstrap.sh</code></p>
    @endif
    <p class="texto-suave">Signatários: <code>{{ $signer1 }}</code>@if($signer2) e <code>{{ $signer2 }}</code>@endif</p>
</div>

<div class="grade-2">
    <div class="bloco">
        <h2><i class="fa fa-user"></i> 1. Assinatura única</h2>
        <p>Um documento, um signatário (<code>{{ $signer1 }}</code>). Após assinar: ver/baixar via proxy e código na validação pública.</p>
        <form method="post" action="{{ url('/assinatura-digital/testes/unica') }}">
            @csrf
            <button class="botao" type="submit"><i class="fa fa-play"></i> Executar teste único</button>
        </form>
        <p class="texto-suave" style="margin-top:10px">CLI: <code>php artisan assinatura:teste unica</code></p>
    </div>
    <div class="bloco">
        <h2><i class="fa fa-users"></i> 2. Múltiplas assinaturas</h2>
        <p>Um documento, dois signatários (<code>{{ $signer1 }}</code> + <code>{{ $signer2 }}</code>). Status parcial → assinado.</p>
        <form method="post" action="{{ url('/assinatura-digital/testes/multipla') }}">
            @csrf
            <button class="botao" type="submit"><i class="fa fa-play"></i> Executar teste múltiplo</button>
        </form>
        <p class="texto-suave" style="margin-top:10px">CLI: <code>php artisan assinatura:teste multipla</code></p>
    </div>
</div>
@endsection
