@extends('assinatura::layout')

@section('titulo', 'Assinatura digital')
@section('icone-tela', 'fa-pencil-square-o')
@section('titulo-tela', 'Assinatura digital')
@section('lead')
    PDFs assinados no LibreSign. Aqui você consulta pedidos, baixa por proxy e valida autenticidade.
@endsection
@section('acoes-topo')
    <a class="botao claro icone" href="{{ url('/assinatura-digital/pedidos') }}" title="Pedidos" aria-label="Pedidos"><i aria-hidden="true" class="fa fa-list"></i></a>
    @if(!empty($isAssinaturaAdmin))
        <a class="botao icone" href="{{ url('/assinatura-digital/testes') }}" title="Testes" aria-label="Testes"><i aria-hidden="true" class="fa fa-flask"></i></a>
        <a class="botao claro icone" href="{{ url('/assinatura-digital/configuracoes') }}" title="Configurações" aria-label="Configurações"><i aria-hidden="true" class="fa fa-sliders"></i></a>
    @endif
    <a class="botao claro icone" href="{{ url('/assinatura-digital/verificar') }}" target="_blank" title="Validação pública" aria-label="Validação pública"><i aria-hidden="true" class="fa fa-qrcode"></i></a>
@endsection

@section('conteudo')
@php
    $total = count($pedidos);
    $totaisMeus = $meusAssinados['totais'] ?? ['todos' => 0, 'andamento' => 0, 'prontos' => 0];
    $assinados = (int) ($totaisMeus['todos'] ?? 0);
    $pendentes = (int) ($minhasPendencias ?? 0);
    $destaques = is_array($meusAssinados['itens'] ?? null) ? $meusAssinados['itens'] : [];
@endphp

<div class="numeros">
    <article style="--tom: var(--cor-tela)">
        <div class="kpi-ico"><i class="fa fa-cloud"></i></div>
        <span>LibreSign</span>
        <strong>{{ !empty($libresignOk) ? 'Online' : 'Offline' }}</strong>
    </article>
    <article style="--tom: {{ !empty($libresignOk) ? 'var(--ok)' : 'var(--erro)' }}">
        <div class="kpi-ico"><i class="fa fa-files-o"></i></div>
        <span>Últimos pedidos</span>
        <strong>{{ $total }}</strong>
    </article>
    <article style="--tom: var(--ok)">
        <div class="kpi-ico"><i class="fa fa-check-circle"></i></div>
        <span>Já assinei</span>
        <strong>{{ $assinados }}</strong>
    </article>
    <article style="--tom: var(--ambar)">
        <div class="kpi-ico"><i class="fa fa-clock-o"></i></div>
        <span>Minhas pendências</span>
        <strong>{{ $pendentes }}</strong>
    </article>
</div>

<div class="bloco">
    <h2><i class="fa fa-compass"></i> Atalhos</h2>
    <ul class="atalhos">
        <li>
            <a href="{{ url('/assinatura-digital/verificar') }}" target="_blank">
                <i class="fa fa-shield"></i>
                <span>Consulta pública<small>Código / QR · LGPD</small></span>
            </a>
        </li>
        <li>
            <a href="{{ url('/assinatura-digital/pendencias') }}">
                <i class="fa fa-pencil-square-o"></i>
                <span>Minhas pendências<small>Confirmar e assinar no LibreSign</small></span>
            </a>
        </li>
        <li>
            <a href="{{ url('/assinatura-digital/assinados') }}">
                <i class="fa fa-check-square-o"></i>
                <span>Já assinei<small>Prontos ou aguardando outras etapas</small></span>
            </a>
        </li>
        <li>
            <a href="{{ url('/assinatura-digital/pedidos') }}">
                <i class="fa fa-list"></i>
                <span>Pedidos<small>Status e PDF no LibreSign</small></span>
            </a>
        </li>
        @if(!empty($isAssinaturaAdmin))
            <li>
                <a href="{{ url('/assinatura-digital/configuracoes') }}">
                    <i class="fa fa-sliders"></i>
                    <span>Configurações<small>Instância e signatários</small></span>
                </a>
            </li>
            <li>
                <form method="post" action="{{ url('/assinatura-digital/configuracoes/reparar-pendentes') }}"
                      onsubmit="return confirm('Sincronizar pedidos pendentes e corrigir códigos HMAC?');">
                    @csrf
                    <button type="submit">
                        <i class="fa fa-wrench"></i>
                        <span>Reparar pendentes<small>Sync LibreSign + HMAC</small></span>
                    </button>
                </form>
            </li>
        @endif
    </ul>
</div>

<div class="grade-2">
    <div class="bloco">
        <h2><i class="fa fa-cloud"></i> LibreSign</h2>
        @if($libresignOk)
            <p><span class="badge ok"><i class="fa fa-check"></i> online</span> <code>{{ $baseUrl }}</code></p>
        @else
            <p><span class="badge erro"><i class="fa fa-ban"></i> indisponível</span></p>
            <p class="texto-suave">Configure a URL em
                @if(!empty($isAssinaturaAdmin))
                    <a href="{{ url('/assinatura-digital/configuracoes') }}">Configurações</a>
                @else
                    Configurações (admin)
                @endif
                ou suba <code>docker/libresign</code>.</p>
        @endif
    </div>
    <div class="bloco">
        <h2><i class="fa fa-hdd-o"></i> Armazenamento</h2>
        <p>Modo: <strong>{{ !empty($cfg['somente_libresign'] ?? true) ? 'somente LibreSign' : 'permite cópia local' }}</strong></p>
        <p class="texto-suave">Ver/Baixar fazem proxy sob demanda. Validação pública: {{ !empty($cfg['validacao_publica'] ?? true) ? 'ligada' : 'desligada' }}.</p>
    </div>
</div>

@if($destaques !== [])
<div class="bloco">
    <h2><i class="fa fa-check-square-o"></i> Já assinei</h2>
    <ul class="atalhos" style="margin:0">
        @foreach($destaques as $item)
            <li>
                <a href="{{ $item['url_ver'] ?? url('/assinatura-digital/assinados') }}">
                    <i class="fa fa-file-text-o"></i>
                    <span>
                        {{ $item['titulo'] ?? 'Documento' }}
                        <small>{{ $item['estado_rotulo'] ?? '' }}@if(!empty($item['progresso'])) · {{ $item['progresso'] }}@endif</small>
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
    <p style="margin:10px 0 0"><a href="{{ url('/assinatura-digital/assinados') }}">Ver todos</a></p>
</div>
@endif

<div class="bloco">
    <h2><i class="fa fa-history"></i> Últimos pedidos</h2>
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
                        <span class="badge {{ $st === 'assinado' ? 'ok' : ($st === 'parcial' ? 'warn' : '') }}">{{ $st ?: '—' }}</span>
                    </td>
                    <td>
                        @if(!empty($p['codigo_validacao']))
                            <a href="{{ $p['url_validacao'] ?? url('/assinatura-digital/verificar/'.$p['codigo_validacao']) }}">{{ $p['codigo_validacao'] }}</a>
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
