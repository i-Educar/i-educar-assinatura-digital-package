@extends('assinatura::layout')

@section('titulo', 'Pedidos de assinatura')
@section('icone-tela', 'fa-list')
@section('titulo-tela', 'Pedidos')
@section('lead')
    Metadados no i-Educar · PDF no LibreSign
@endsection
@section('acoes-topo')
    <a class="botao claro" href="{{ url('/assinatura-digital') }}"><i class="fa fa-th-large"></i> Painel</a>
@endsection

@section('conteudo')
<div class="bloco">
    <h2><i class="fa fa-list"></i> Lista</h2>
    <table class="lista">
        <thead>
        <tr><th>ID</th><th>Título</th><th>Cenário</th><th>Status</th><th>UUID LibreSign</th><th></th></tr>
        </thead>
        <tbody>
        @forelse($pedidos as $p)
            @php $st = $p['status'] ?? ''; @endphp
            <tr>
                <td>{{ $p['id'] }}</td>
                <td>{{ $p['titulo'] }}</td>
                <td>{{ $p['cenario'] }}</td>
                <td>
                    <span class="badge {{ $st === 'assinado' ? 'ok' : ($st === 'parcial' ? 'warn' : '') }}">{{ $st ?: '—' }}</span>
                </td>
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
