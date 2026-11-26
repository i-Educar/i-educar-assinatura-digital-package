@extends('assinatura::layout')

@section('titulo', 'Pedidos de assinatura')

@section('conteudo')
<div class="cabeca-tela">
    <div class="identidade-tela">
        <div class="marca-tela"><i class="fa fa-list"></i></div>
        <div>
            <p class="titulo-modulo">Assinatura</p>
            <h1>Pedidos</h1>
            <p class="lead">Metadados no i-Educar · PDF no LibreSign</p>
        </div>
    </div>
</div>

<div class="bloco">
    <table class="lista">
        <thead>
        <tr><th>ID</th><th>Título</th><th>Cenário</th><th>Status</th><th>UUID LibreSign</th><th></th></tr>
        </thead>
        <tbody>
        @forelse($pedidos as $p)
            <tr>
                <td>{{ $p['id'] }}</td>
                <td>{{ $p['titulo'] }}</td>
                <td>{{ $p['cenario'] }}</td>
                <td>{{ $p['status'] }}</td>
                <td><code>{{ \Illuminate\Support\Str::limit($p['libresign_uuid'] ?? '', 13) }}</code></td>
                <td><a class="botao sm claro" href="{{ url('/assinatura-digital/pedidos/'.$p['id']) }}">Detalhe</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="texto-suave">Nenhum pedido.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
