@extends('assinatura::layout')

@section('titulo', 'Assinatura digital')

@section('conteudo')
<div class="cabeca-tela">
    <div class="identidade-tela">
        <div class="marca-tela"><i class="fa fa-pencil-square-o"></i></div>
        <div>
            <p class="titulo-modulo">LibreSign</p>
            <h1>Assinatura digital</h1>
            <p class="lead">PDF assinado fica no LibreSign. Aqui você vê, baixa (proxy) e valida autenticidade.</p>
        </div>
    </div>
    <div class="acoes-tela">
        <a class="botao" href="{{ url('/assinatura-digital/testes') }}"><i class="fa fa-flask"></i> Rodar testes</a>
        <a class="botao claro" href="{{ url('/exportacao/validar') }}"><i class="fa fa-qrcode"></i> Validar documento</a>
    </div>
</div>

<div class="grade-2">
    <div class="bloco">
        <h2><i class="fa fa-cloud"></i> LibreSign</h2>
        @if($libresignOk)
            <p><span class="badge ok">online</span> <code>{{ $baseUrl }}</code></p>
        @else
            <p><span class="badge erro">indisponível</span></p>
            <p class="texto-suave">Suba <code>docker/libresign</code> e configure <code>LIBRESIGN_*</code> no .env.</p>
        @endif
    </div>
    <div class="bloco">
        <h2><i class="fa fa-hdd-o"></i> Armazenamento</h2>
        <p>Modo: <strong>somente LibreSign</strong> — sem cópia no storage do i-Educar.</p>
        <p class="texto-suave">Ver/Baixar fazem proxy sob demanda.</p>
    </div>
</div>

<div class="bloco">
    <h2>Últimos pedidos</h2>
    @if(count($pedidos) === 0)
        <p class="texto-suave">Nenhum pedido ainda. Use os testes de assinatura única ou múltipla.</p>
    @else
        <table class="lista">
            <thead>
            <tr><th>Título</th><th>Cenário</th><th>Status</th><th>Validação</th><th></th></tr>
            </thead>
            <tbody>
            @foreach($pedidos as $p)
                <tr>
                    <td>{{ $p['titulo'] }}</td>
                    <td>{{ $p['cenario'] }}</td>
                    <td>
                        @php $st = $p['status'] ?? ''; @endphp
                        <span class="badge {{ $st === 'assinado' ? 'ok' : ($st === 'parcial' ? 'warn' : '') }}">{{ $st }}</span>
                    </td>
                    <td>
                        @if(!empty($p['codigo_validacao']))
                            <a href="{{ $p['url_validacao'] ?? url('/exportacao/validar/'.$p['codigo_validacao']) }}">{{ $p['codigo_validacao'] }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td class="acoes-linha">
                        <a class="botao sm claro" href="{{ url('/assinatura-digital/pedidos/'.$p['id']) }}">Abrir</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
