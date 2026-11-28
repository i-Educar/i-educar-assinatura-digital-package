@extends('assinatura::layout')

@section('titulo', 'Minhas assinaturas pendentes')
@section('icone-tela', 'fa-pencil-square-o')
@section('titulo-tela', 'Minhas pendências')
@section('lead')
    Documentos aguardando a sua assinatura digital no LibreSign. Confirme para registrar.
@endsection
@section('acoes-topo')
    <a class="botao claro" href="{{ url('/painel') }}"><i class="fa fa-th-large"></i> Painel</a>
    <a class="botao claro" href="{{ url('/assinatura-digital/assinados') }}"><i class="fa fa-check-square-o"></i> Já assinei</a>
    <a class="botao claro" href="{{ url('/assinatura-digital/pedidos') }}"><i class="fa fa-list"></i> Todos os pedidos</a>
@endsection

@section('conteudo')
@if(session('ok'))
    <p class="flash ok"><i class="fa fa-check-circle"></i> {{ session('ok') }}</p>
@endif
@if(session('erro'))
    <p class="flash erro"><i class="fa fa-exclamation-circle"></i> {{ session('erro') }}</p>
@endif

@php $qPendentes = count($itens ?? []); @endphp

<div class="numeros" style="margin-bottom:14px">
    <article style="--tom: var(--ambar, #b7791f)">
        <span class="kpi-ico"><i class="fa fa-hourglass-half"></i></span>
        <span>Aguardando sua assinatura</span>
        <strong>{{ $qPendentes }}</strong>
    </article>
</div>

<div class="bloco">
    <h2><i class="fa fa-clock-o"></i> Fila ({{ $qPendentes }})</h2>
    <p class="texto-suave" style="margin:0 0 12px">
        Use <strong>Ver</strong> para pré-visualizar o PDF e <strong>Assinar</strong> para confirmar com um clique (sem senha).
    </p>
    <table class="lista">
        <thead>
        <tr>
            <th>Pedido</th>
            <th>Documento</th>
            <th>Seu papel</th>
            <th>Status</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse($itens as $item)
            @php
                $pdfUrl = $item['url_pdf'] ?? url('/assinatura-digital/pedidos/'.$item['pedido_id'].'/pdf');
                $verUrl = $item['url_ver'] ?? url('/assinatura-digital/pedidos/'.$item['pedido_id'].'/ver');
                $assinarUrl = $item['url_assinar'] ?? route('assinatura.pendencias.confirmar', $item['pedido_id']);
                $cargo = $item['cargo'] ?? ($item['nome'] ?? '');
            @endphp
            <tr>
                <td>#{{ $item['pedido_id'] }}</td>
                <td>
                    <strong>{{ $item['titulo'] }}</strong>
                    @if(!empty($item['criado_em']))
                        <br><small class="texto-suave">{{ $item['criado_em'] }}</small>
                    @endif
                </td>
                <td>{{ $cargo !== '' ? $cargo : '—' }}</td>
                <td><span class="badge warn">{{ $item['status'] }}</span></td>
                <td>
                    <button type="button"
                            class="botao sm claro js-abrir-pdf-modal"
                            data-pdf-url="{{ $pdfUrl }}"
                            data-ver-url="{{ $verUrl }}"
                            data-titulo="{{ $item['titulo'] }}"
                            data-cargo="{{ $cargo }}"
                            data-assinar-url="{{ $assinarUrl }}"
                            data-redirect="/assinatura-digital/pendencias">
                        <i class="fa fa-eye" aria-hidden="true"></i> Ver
                    </button>
                    <form method="post" action="{{ $assinarUrl }}" style="display:inline;margin-top:6px">
                        @csrf
                        <input type="hidden" name="_redirect" value="/assinatura-digital/pendencias">
                        <button type="submit" class="botao sm" onclick="return confirm('Confirmar sua assinatura digital neste documento?')">
                            <i class="fa fa-pencil" aria-hidden="true"></i> Assinar
                        </button>
                    </form>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="texto-suave">Nenhum documento pendente da sua assinatura.</td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>

@include('assinatura::partials.modal-pdf')
@endsection
