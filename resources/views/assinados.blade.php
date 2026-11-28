@extends('assinatura::layout')

@section('titulo', 'Documentos que já assinei')
@section('icone-tela', 'fa-check-square-o')
@section('titulo-tela', 'Já assinei')
@section('lead')
    Documentos em que você já assinou. Use os filtros para ver o que ainda está em andamento ou o que já ficou pronto. A lista é paginada para não ocupar a tela inteira.
@endsection
@section('acoes-topo')
    <a class="botao claro" href="{{ url('/painel') }}"><i class="fa fa-th-large"></i> Painel</a>
    <a class="botao claro" href="{{ url('/assinatura-digital/pendencias') }}"><i class="fa fa-pencil-square-o"></i> Pendências</a>
@endsection

@section('conteudo')
@php
    $totais = $totais ?? ['todos' => 0, 'andamento' => 0, 'prontos' => 0];
    $filtro = $filtro ?? 'andamento';
    $pagina = max(1, (int) ($pagina ?? 1));
    $ultimaPagina = max(1, (int) ($ultima_pagina ?? 1));
    $totalFiltrado = (int) ($total_filtrado ?? count($itens ?? []));
    $porPagina = max(5, (int) ($por_pagina ?? 8));
    $baseUrl = url('/assinatura-digital/assinados');
    $urlFiltro = static function (string $f) use ($baseUrl, $porPagina): string {
        return $baseUrl.'?'.http_build_query(['filtro' => $f, 'por_pagina' => $porPagina, 'pagina' => 1]);
    };
    $urlPagina = static function (int $p) use ($baseUrl, $filtro, $porPagina): string {
        return $baseUrl.'?'.http_build_query(['filtro' => $filtro, 'por_pagina' => $porPagina, 'pagina' => $p]);
    };
@endphp

<div class="numeros" style="margin-bottom:14px">
    <a href="{{ $urlFiltro('prontos') }}"
       class="assinados-kpi{{ $filtro === 'prontos' ? ' is-ativo' : '' }}"
       style="--tom: var(--ok, #17663f); text-decoration:none; color:inherit">
        <article>
            <span class="kpi-ico"><i class="fa fa-check-circle"></i></span>
            <span>Prontos</span>
            <strong>{{ (int) ($totais['prontos'] ?? 0) }}</strong>
        </article>
    </a>
    <a href="{{ $urlFiltro('andamento') }}"
       class="assinados-kpi{{ $filtro === 'andamento' ? ' is-ativo' : '' }}"
       style="--tom: var(--ambar, #b7791f); text-decoration:none; color:inherit">
        <article>
            <span class="kpi-ico"><i class="fa fa-clock-o"></i></span>
            <span>Já assinei — em andamento</span>
            <strong>{{ (int) ($totais['andamento'] ?? 0) }}</strong>
        </article>
    </a>
    <a href="{{ $urlFiltro('todos') }}"
       class="assinados-kpi{{ $filtro === 'todos' ? ' is-ativo' : '' }}"
       style="--tom: var(--cor-tela, #1a5f7a); text-decoration:none; color:inherit">
        <article>
            <span class="kpi-ico"><i class="fa fa-list"></i></span>
            <span>Todos</span>
            <strong>{{ (int) ($totais['todos'] ?? 0) }}</strong>
        </article>
    </a>
</div>

<div class="bloco">
    <h2>
        <i class="fa fa-check-square-o"></i>
        @if($filtro === 'prontos')
            Prontos
        @elseif($filtro === 'andamento')
            Em andamento
        @else
            Caixa de assinados
        @endif
        <span class="texto-suave" style="font-weight:500;font-size:.85rem;margin-left:6px">
            ({{ $totalFiltrado }} · pág. {{ $pagina }}/{{ $ultimaPagina }})
        </span>
    </h2>
    <p class="texto-suave" style="margin:0 0 12px">
        Exibindo até {{ $porPagina }} por página. Clique nos cards acima para filtrar.
    </p>
    <table class="lista">
        <thead>
        <tr>
            <th>Pedido</th>
            <th>Documento</th>
            <th>Seu papel</th>
            <th>Progresso</th>
            <th>Situação</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse($itens as $item)
            @php
                $subtituloModal = $item['resumo_tempo'] ?? '';
                if ($subtituloModal === '' && !empty($item['cargo'])) {
                    $subtituloModal = 'Seu papel: '.$item['cargo'];
                }
                $linhasCurtas = array_slice($item['linhas_tempo'] ?? [], 0, 2);
            @endphp
            <tr>
                <td>#{{ $item['pedido_id'] }}</td>
                <td>
                    <strong>{{ $item['titulo'] }}</strong>
                    @if($linhasCurtas !== [])
                        <ul style="margin:6px 0 0;padding-left:1.1rem;color:var(--suave);font-size:.82rem;line-height:1.4">
                            @foreach($linhasCurtas as $linha)
                                <li>{{ $linha }}</li>
                            @endforeach
                        </ul>
                    @elseif(!empty($item['quando']))
                        <br><small class="texto-suave">{{ $item['quando'] }}</small>
                    @endif
                    @if(($item['estado'] ?? '') === 'parcial' && !empty($item['pendentes_outros']))
                        <p class="texto-suave" style="margin:6px 0 0;font-size:.82rem">
                            <i class="fa fa-hourglass-half" aria-hidden="true"></i>
                            Ainda faltam: {{ implode(', ', $item['pendentes_outros']) }}
                        </p>
                    @endif
                </td>
                <td>{{ $item['cargo'] ?? '—' }}</td>
                <td>{{ $item['progresso'] }}</td>
                <td>
                    @if(($item['estado'] ?? '') === 'completo')
                        <span class="badge ok">{{ $item['estado_rotulo'] }}</span>
                    @else
                        <span class="badge warn">{{ $item['estado_rotulo'] }}</span>
                    @endif
                </td>
                <td>
                    <button type="button"
                            class="botao sm claro js-abrir-pdf-modal"
                            data-pdf-url="{{ $item['url_pdf'] ?? url('/assinatura-digital/pedidos/'.$item['pedido_id'].'/pdf') }}"
                            data-ver-url="{{ $item['url_ver'] }}"
                            data-titulo="{{ $item['titulo'] }}"
                            data-cargo="{{ $subtituloModal }}"
                            title="Ver PDF">
                        <i class="fa fa-eye" aria-hidden="true"></i> Ver
                    </button>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="texto-suave">
                    @if($filtro === 'andamento')
                        Nenhum documento em andamento. Você pode ver os prontos ou todos nos cards acima.
                    @elseif($filtro === 'prontos')
                        Nenhum documento pronto ainda.
                    @else
                        Você ainda não assinou nenhum documento nesta fila.
                    @endif
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>

    @if($ultimaPagina > 1)
        <nav class="assinados-paginacao" aria-label="Paginação" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:14px">
            @if($pagina > 1)
                <a class="botao sm claro" href="{{ $urlPagina($pagina - 1) }}"><i class="fa fa-chevron-left"></i> Anterior</a>
            @endif
            <span class="texto-suave">Página {{ $pagina }} de {{ $ultimaPagina }}</span>
            @if($pagina < $ultimaPagina)
                <a class="botao sm claro" href="{{ $urlPagina($pagina + 1) }}">Próxima <i class="fa fa-chevron-right"></i></a>
            @endif
        </nav>
    @endif
</div>

@include('assinatura::partials.modal-pdf')
@endsection

@push('estilos')
<style>
    .numeros { align-items: stretch; }
    .assinados-kpi { display: block; border-radius: 14px; }
    .assinados-kpi article {
        height: 100%;
        transition: box-shadow .15s ease, border-color .15s ease;
        cursor: pointer;
    }
    .assinados-kpi.is-ativo article {
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--tom) 55%, transparent);
        border-color: color-mix(in srgb, var(--tom) 55%, var(--linha, #d0d7de));
    }
    .assinados-kpi:hover article {
        border-color: color-mix(in srgb, var(--tom) 45%, var(--linha, #d0d7de));
    }
</style>
@endpush
