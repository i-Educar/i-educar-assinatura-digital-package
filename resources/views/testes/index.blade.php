@extends('assinatura::layout')

@section('titulo', 'Testes LibreSign')

@section('conteudo')
<div class="cabeca-tela">
    <div class="identidade-tela">
        <div class="marca-tela"><i class="fa fa-flask"></i></div>
        <div>
            <p class="titulo-modulo">Desenvolvimento</p>
            <h1>Testes de assinatura</h1>
            <p class="lead">Dois cenários contra o LibreSign local (docker/libresign).</p>
        </div>
    </div>
</div>

<div class="bloco">
    <h2>Ambiente</h2>
    @if($libresignOk)
        <p><span class="badge ok">LibreSign online</span> <code>{{ $baseUrl }}</code></p>
    @else
        <p><span class="badge erro">LibreSign offline</span> — rode <code>cd docker/libresign && docker compose up -d && ./scripts/bootstrap.sh</code></p>
    @endif
    <p class="texto-suave">Signatários: <code>{{ $signer1 }}</code>@if($signer2) e <code>{{ $signer2 }}</code>@endif</p>
</div>

<div class="grade-2">
    <div class="bloco">
        <h2>1. Assinatura única</h2>
        <p>Um documento, um signatário (<code>{{ $signer1 }}</code>). Após assinar: ver/baixar via proxy e código na validação pública.</p>
        <form method="post" action="{{ url('/assinatura-digital/testes/unica') }}">
            @csrf
            <button class="botao" type="submit"><i class="fa fa-play"></i> Executar teste único</button>
        </form>
        <p class="texto-suave" style="margin-top:10px">CLI: <code>php artisan assinatura:teste unica</code></p>
    </div>
    <div class="bloco">
        <h2>2. Múltiplas assinaturas</h2>
        <p>Um documento, dois signatários (<code>{{ $signer1 }}</code> + <code>{{ $signer2 }}</code>). Status parcial → assinado.</p>
        <form method="post" action="{{ url('/assinatura-digital/testes/multipla') }}">
            @csrf
            <button class="botao" type="submit"><i class="fa fa-play"></i> Executar teste múltiplo</button>
        </form>
        <p class="texto-suave" style="margin-top:10px">CLI: <code>php artisan assinatura:teste multipla</code></p>
    </div>
</div>
@endsection
