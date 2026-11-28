<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Verificar autenticidade — Assinatura digital</title>
    <link rel="stylesheet" href="{{ url('/intranet/styles/font-awesome.css') }}">
    <style>
        :root {
            --cor: {{ $identidade['cor'] ?? '#0f4c5c' }};
            --destaque: {{ $identidade['destaque'] ?? '#1a7a8c' }};
            --clara: {{ $identidade['clara'] ?? '#e6f4f6' }};
            --tinta: #1a242b; --suave: #5b6b76; --linha: #cfd8df;
            --ok: #17663f; --ok-fundo: #e5f6ec; --erro: #9b2c2c; --erro-fundo: #fdeceb;
            --warn: #b86e00; --warn-fundo: #fff4e0; --superficie: #fff;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; font-family: "Segoe UI", sans-serif; font-size: 14px; color: var(--tinta);
            background:
                radial-gradient(1100px 420px at 8% -12%, color-mix(in srgb, var(--clara) 80%, transparent), transparent 55%),
                linear-gradient(180deg, #f3f6f8, #e8edf2);
            min-height: 100vh;
        }
        .shell { max-width: 1120px; margin: 0 auto; padding: 14px 18px 28px; }
        .topo {
            display: grid; grid-template-columns: minmax(220px, 1.1fr) minmax(280px, 1.2fr);
            gap: 14px 20px; align-items: end;
            padding: 14px 16px; border-radius: 16px;
            background: linear-gradient(120deg, color-mix(in srgb, var(--cor) 92%, #000), var(--destaque));
            color: #fff; margin-bottom: 14px;
            box-shadow: 0 12px 28px color-mix(in srgb, var(--cor) 28%, transparent);
        }
        .marca { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .marca .ico {
            width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center;
            background: rgba(255,255,255,.16); font-size: 1.1rem; flex: none;
        }
        .marca strong { display: block; font-size: .68rem; letter-spacing: .1em; text-transform: uppercase; opacity: .9; }
        .marca span { display: block; font-size: 1.05rem; font-weight: 800; line-height: 1.2; }
        .topo h1 { margin: 8px 0 0; font-size: 1.2rem; font-weight: 800; }
        .topo .lead { margin: 4px 0 0; opacity: .9; font-size: .84rem; max-width: 36rem; }
        .busca {
            background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.22);
            border-radius: 12px; padding: 10px 12px;
        }
        .busca label {
            display: block; font-size: .68rem; font-weight: 700; letter-spacing: .06em;
            text-transform: uppercase; opacity: .85; margin: 0 0 6px;
        }
        .linha-form { display: flex; gap: 8px; }
        input[type=text] {
            flex: 1; min-width: 0; border: 0; border-radius: 9px; padding: 9px 11px;
            font: inherit; letter-spacing: .04em; text-transform: uppercase; color: var(--tinta);
        }
        button, .botao {
            display: inline-flex; align-items: center; gap: 7px; border: 0; border-radius: 9px;
            background: #fff; color: var(--cor); padding: 9px 12px; font: inherit; font-weight: 700;
            cursor: pointer; text-decoration: none; white-space: nowrap;
        }
        .botao.ghost {
            background: transparent; color: #fff; border: 1px solid rgba(255,255,255,.35);
        }
        .botao.ghost:hover { background: rgba(255,255,255,.12); }
        .botao.sm { padding: 7px 10px; font-size: .82rem; }

        .painel {
            display: grid; grid-template-columns: minmax(260px, 0.95fr) minmax(320px, 1.35fr);
            gap: 12px; align-items: start;
        }
        .cartao {
            background: var(--superficie); border: 1px solid var(--linha); border-radius: 14px;
            padding: 14px 14px 16px; border-top: 3px solid var(--cor);
            box-shadow: 0 8px 22px rgba(21,34,43,.05);
        }
        .cartao h2 {
            margin: 0 0 10px; font-size: .78rem; font-weight: 800; letter-spacing: .08em;
            text-transform: uppercase; color: var(--suave); display: flex; align-items: center; gap: 8px;
        }
        .cartao h2 .badge {
            margin-left: auto; text-transform: none; letter-spacing: 0; font-size: .72rem;
        }

        .veredicto {
            display: grid; grid-template-columns: 48px 1fr; gap: 10px; align-items: start;
            border-radius: 12px; padding: 12px; margin: 0 0 12px; border: 1px solid transparent;
        }
        .veredicto .ico-grande {
            width: 48px; height: 48px; border-radius: 14px; display: grid; place-items: center;
            font-size: 1.35rem; color: #fff;
        }
        .veredicto h3 { margin: 0 0 3px; font-size: 1.05rem; font-weight: 800; line-height: 1.25; }
        .veredicto p { margin: 0; font-size: .86rem; line-height: 1.4; opacity: .95; }
        .veredicto.ok { background: var(--ok-fundo); border-color: color-mix(in srgb, var(--ok) 30%, #fff); color: var(--ok); }
        .veredicto.ok .ico-grande { background: var(--ok); }
        .veredicto.warn { background: var(--warn-fundo); border-color: color-mix(in srgb, var(--warn) 30%, #fff); color: #8a5a00; }
        .veredicto.warn .ico-grande { background: var(--warn); }
        .veredicto.erro { background: var(--erro-fundo); border-color: color-mix(in srgb, var(--erro) 30%, #fff); color: var(--erro); }
        .veredicto.erro .ico-grande { background: var(--erro); }

        .meta-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
        }
        .campo {
            background: #f7fafb; border: 1px solid var(--linha); border-radius: 10px; padding: 8px 10px;
        }
        .campo span { display: block; font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; color: var(--suave); }
        .campo strong { display: block; margin-top: 2px; font-size: .88rem; line-height: 1.3; }

        .badge {
            display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px;
            font-size: .72rem; font-weight: 800;
        }
        .badge.ok { background: var(--ok-fundo); color: var(--ok); }
        .badge.warn { background: var(--warn-fundo); color: var(--warn); }
        .badge.erro { background: var(--erro-fundo); color: var(--erro); }

        /* Fluxograma horizontal */
        .fluxo {
            display: flex; align-items: stretch; gap: 0; overflow-x: auto;
            padding: 6px 2px 4px; scrollbar-width: thin;
        }
        .fluxo-etapa {
            flex: 1 1 0; min-width: 132px; max-width: 200px;
            display: flex; flex-direction: column; align-items: center; position: relative;
            padding: 0 6px;
        }
        .fluxo-etapa:not(:last-child)::after {
            content: '';
            position: absolute; top: 27px; left: calc(50% + 28px); right: calc(-50% + 28px);
            height: 3px; background: var(--linha); z-index: 0;
        }
        .fluxo-etapa.is-done:not(:last-child)::after {
            background: color-mix(in srgb, var(--ok) 55%, var(--linha));
        }
        .fluxo-etapa.is-atual:not(:last-child)::after {
            background: linear-gradient(90deg, color-mix(in srgb, var(--warn) 70%, var(--linha)), var(--linha));
        }
        .fluxo-no {
            position: relative; z-index: 1;
            width: 54px; height: 54px; border-radius: 16px; display: grid; place-items: center;
            font-size: 1.2rem; border: 2px solid var(--linha); background: #fff; color: var(--suave);
            box-shadow: 0 4px 12px rgba(21,34,43,.05);
        }
        .fluxo-etapa.is-done .fluxo-no {
            background: var(--ok); border-color: var(--ok); color: #fff;
            box-shadow: 0 6px 14px color-mix(in srgb, var(--ok) 30%, transparent);
        }
        .fluxo-etapa.is-pendente .fluxo-no {
            background: #fff; border-color: color-mix(in srgb, var(--warn) 45%, var(--linha));
            color: var(--warn); border-style: dashed;
        }
        .fluxo-etapa.is-atual .fluxo-no {
            background: var(--warn); border-color: var(--warn); color: #fff;
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--warn) 22%, transparent);
        }
        .fluxo-etapa.is-destaque .fluxo-no {
            outline: 2px solid var(--cor);
            outline-offset: 2px;
        }
        .fluxo-card {
            width: 100%; margin-top: 10px; padding: 8px 8px 9px; border-radius: 10px;
            border: 1px solid var(--linha); background: #fafcfd; text-align: center;
        }
        .fluxo-etapa.is-done .fluxo-card {
            background: color-mix(in srgb, var(--ok-fundo) 70%, #fff);
            border-color: color-mix(in srgb, var(--ok) 28%, var(--linha));
        }
        .fluxo-etapa.is-pendente .fluxo-card,
        .fluxo-etapa.is-atual .fluxo-card {
            background: color-mix(in srgb, var(--warn-fundo) 65%, #fff);
            border-color: color-mix(in srgb, var(--warn) 28%, var(--linha));
        }
        .fluxo-card .papel { display: block; font-weight: 800; font-size: .82rem; line-height: 1.25; color: var(--tinta); }
        .fluxo-card .quem { display: block; margin-top: 3px; font-size: .74rem; color: var(--suave); line-height: 1.3; min-height: 1.3em; }
        .fluxo-card .quando { display: block; margin-top: 2px; font-size: .7rem; color: var(--suave); }
        .fluxo-card .chip {
            display: inline-flex; align-items: center; gap: 4px; margin-top: 6px;
            padding: 2px 7px; border-radius: 999px; font-size: .68rem; font-weight: 800;
        }
        .fluxo-card .chip.ok { background: var(--ok-fundo); color: var(--ok); }
        .fluxo-card .chip.warn { background: var(--warn-fundo); color: var(--warn); }

        .progresso {
            display: flex; align-items: center; gap: 10px; margin: 0 0 12px;
            padding: 8px 10px; border-radius: 10px; background: #f4f7f9; border: 1px solid var(--linha);
        }
        .progresso-barra {
            flex: 1; height: 8px; border-radius: 99px; background: #e4ebf0; overflow: hidden;
        }
        .progresso-barra i {
            display: block; height: 100%; border-radius: 99px;
            background: linear-gradient(90deg, var(--ok), color-mix(in srgb, var(--ok) 70%, var(--destaque)));
        }
        .progresso strong { font-size: .82rem; white-space: nowrap; }

        .acoes-doc { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .lgpd {
            margin-top: 10px; padding: 8px 10px; border-radius: 10px; background: #f4f7f9;
            border: 1px dashed var(--linha); color: var(--suave); font-size: .76rem; line-height: 1.4;
        }
        .legenda {
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-top: 4px;
        }
        .legenda .item {
            border: 1px solid var(--linha); border-radius: 10px; padding: 8px 10px; background: #fafcfd;
            color: var(--suave); font-size: .78rem; line-height: 1.35;
        }
        .legenda .item strong { display: block; color: var(--tinta); margin-bottom: 2px; font-size: .8rem; }
        .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; }
        .dot.ok { background: var(--ok); } .dot.warn { background: var(--warn); } .dot.erro { background: var(--erro); }
        .rodape { margin-top: 14px; text-align: center; color: var(--suave); font-size: .76rem; }
        .rodape a { color: var(--cor); }

        .vazio-hint {
            display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 12px;
        }

        @media (max-width: 860px) {
            .topo, .painel, .vazio-hint { grid-template-columns: 1fr; }
            .legenda { grid-template-columns: 1fr; }
            .fluxo-etapa { min-width: 120px; flex: 0 0 140px; }
        }
        @media (max-width: 520px) {
            .meta-grid { grid-template-columns: 1fr; }
            .linha-form { flex-direction: column; }
        }
    </style>
</head>
<body>
@php
    $v = is_array($resultado['veredicto'] ?? null) ? $resultado['veredicto'] : null;
    if ($v === null && $resultado !== null) {
        if (!empty($resultado['encontrado']) && !empty($resultado['autentico'])) {
            $v = ['nivel' => 'ok', 'titulo' => 'Documento válido', 'resumo' => (string) ($resultado['mensagem'] ?? ''), 'icone' => 'fa-check-circle', 'cor' => 'ok'];
        } elseif (!empty($resultado['encontrado'])) {
            $v = ['nivel' => 'warn', 'titulo' => 'Encontrado com ressalvas', 'resumo' => (string) ($resultado['mensagem'] ?? ''), 'icone' => 'fa-exclamation-triangle', 'cor' => 'warn'];
        } else {
            $v = ['nivel' => 'erro', 'titulo' => 'Não encontrado', 'resumo' => (string) ($resultado['mensagem'] ?? ''), 'icone' => 'fa-times-circle', 'cor' => 'erro'];
        }
    }
    $d = is_array($resultado['documento'] ?? null) ? $resultado['documento'] : null;
    $assinaturas = is_array($resultado['assinaturas'] ?? null) ? $resultado['assinaturas'] : [];
    $totalEtapas = count($assinaturas);
    $feitas = count(array_filter($assinaturas, static fn ($a) => !empty($a['concluida'])));
    $pct = $totalEtapas > 0 ? (int) round(($feitas / $totalEtapas) * 100) : 0;
    $primeiraPendente = null;
    foreach ($assinaturas as $idx => $a) {
        if (empty($a['concluida'])) {
            $primeiraPendente = $idx;
            break;
        }
    }
@endphp

<div class="shell">
    <header class="topo">
        <div>
            <div class="marca">
                <span class="ico"><i class="fa {{ $identidade['icone'] ?? 'fa-shield' }}"></i></span>
                <span>
                    <strong>{{ $identidade['secretaria'] ?? 'Secretaria Municipal de Educação' }}</strong>
                    <span>Assinatura digital</span>
                </span>
            </div>
            <h1>Este documento é verdadeiro?</h1>
            <p class="lead">Confira o código do rodapé ou da assinatura. O fluxograma mostra o andamento das etapas.</p>
        </div>
        <form class="busca" method="get" action="{{ url('/assinatura-digital/verificar') }}">
            <label for="codigo">Código de autenticidade</label>
            <div class="linha-form">
                <input id="codigo" type="text" name="codigo" value="{{ $codigo }}" maxlength="64" autocomplete="off" placeholder="Ex.: A0BFDE0E3223BECA" required>
                <button type="submit"><i class="fa fa-search"></i> Verificar</button>
            </div>
        </form>
    </header>

    @if($resultado === null)
        <section class="vazio-hint">
            <div class="cartao">
                <h2><i class="fa fa-info-circle" style="color:var(--cor)"></i> Como ler o resultado</h2>
                <div class="legenda">
                    <div class="item"><strong><span class="dot ok"></span> Verde — completo</strong> Todas as etapas feitas; pode usar o documento.</div>
                    <div class="item"><strong><span class="dot warn"></span> Amarelo — em andamento</strong> Código válido; ainda faltam etapas.</div>
                    <div class="item"><strong><span class="dot erro"></span> Vermelho — inválido</strong> Código errado ou conteúdo não confere.</div>
                </div>
            </div>
            <div class="cartao">
                <h2><i class="fa fa-sitemap" style="color:var(--cor)"></i> Fluxo típico</h2>
                <div class="fluxo" aria-hidden="true">
                    @foreach(['Emissão', 'Assinaturas', 'Conferência', 'Pronto'] as $i => $rotulo)
                        <div class="fluxo-etapa {{ $i === 0 ? 'is-done' : ($i === 1 ? 'is-atual' : 'is-pendente') }}">
                            <div class="fluxo-no"><i class="fa {{ ['fa-file-text-o','fa-pencil','fa-check','fa-flag-checkered'][$i] }}"></i></div>
                            <div class="fluxo-card"><span class="papel">{{ $rotulo }}</span></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @elseif($v !== null)
        <div class="painel">
            <aside class="cartao">
                <h2><i class="fa fa-shield" style="color:var(--cor)"></i> Resultado</h2>
                <div class="veredicto {{ $v['cor'] ?? 'erro' }}">
                    <div class="ico-grande" aria-hidden="true"><i class="fa {{ $v['icone'] ?? 'fa-times-circle' }}"></i></div>
                    <div>
                        <h3>{{ $v['titulo'] ?? 'Resultado' }}</h3>
                        <p>{{ $v['resumo'] ?? ($resultado['mensagem'] ?? '') }}</p>
                    </div>
                </div>

                @if($d !== null)
                    <div class="meta-grid">
                        <div class="campo">
                            <span>Escopo</span>
                            <strong>{{ ($d['escopo_consulta'] ?? '') === 'assinatura' ? 'Uma assinatura' : 'Documento completo' }}</strong>
                        </div>
                        <div class="campo">
                            <span>Tipo</span>
                            <strong>{{ $d['tipo_publico'] ?? 'Documento' }}</strong>
                        </div>
                        <div class="campo">
                            <span>Emitido em</span>
                            <strong>{{ $d['emitido_em'] ?? '—' }}</strong>
                        </div>
                        <div class="campo">
                            <span>Situação</span>
                            <strong>{{ $d['status_amigavel'] ?? $d['status'] ?? '—' }}</strong>
                        </div>
                        @if(!empty($d['referencia']))
                            <div class="campo" style="grid-column:1 / -1">
                                <span>Referência</span>
                                <strong>{{ $d['referencia'] }}</strong>
                            </div>
                        @endif
                    </div>

                    <div class="acoes-doc">
                        @if(!empty($d['url_libresign']))
                            <a class="botao sm" href="{{ $d['url_libresign'] }}" target="_blank" rel="noopener" style="background:var(--cor);color:#fff">
                                <i class="fa fa-external-link"></i> LibreSign
                            </a>
                        @endif
                        <a class="botao sm" href="{{ url('/assinatura-digital/verificar') }}" style="background:#fff;color:var(--cor);border:1px solid color-mix(in srgb, var(--cor) 35%, var(--linha))">
                            <i class="fa fa-search"></i> Nova consulta
                        </a>
                    </div>
                @endif

                <div class="lgpd">
                    <strong style="color:var(--tinta)">Privacidade (LGPD)</strong>
                    {{ $resultado['aviso_lgpd'] ?? 'Esta consulta confirma só a autenticidade. Dados pessoais sensíveis não são exibidos.' }}
                </div>
            </aside>

            <section class="cartao">
                <h2>
                    <i class="fa fa-sitemap" style="color:var(--cor)"></i> Fluxo das etapas
                    @if($totalEtapas > 0)
                        <span class="badge {{ !empty($d['completo']) ? 'ok' : 'warn' }}">
                            {{ $d['progresso_assinaturas'] ?? ($feitas.' / '.$totalEtapas) }}
                        </span>
                    @endif
                </h2>

                @if($totalEtapas > 0)
                    <div class="progresso" aria-label="Progresso das assinaturas">
                        <div class="progresso-barra"><i style="width:{{ $pct }}%"></i></div>
                        <strong>{{ $feitas }}/{{ $totalEtapas }} · {{ $pct }}%</strong>
                    </div>

                    <div class="fluxo" role="list">
                        @foreach($assinaturas as $i => $a)
                            @php
                                $feita = !empty($a['concluida']);
                                $atual = ! $feita && $primeiraPendente === $i;
                                $classe = $feita ? 'is-done' : ($atual ? 'is-atual' : 'is-pendente');
                                if (!empty($a['destaque'])) {
                                    $classe .= ' is-destaque';
                                }
                            @endphp
                            <div class="fluxo-etapa {{ $classe }}" role="listitem">
                                <div class="fluxo-no" aria-hidden="true">
                                    <i class="fa {{ $feita ? 'fa-check' : ($atual ? 'fa-pencil' : 'fa-circle-o') }}"></i>
                                </div>
                                <div class="fluxo-card">
                                    <span class="papel">{{ $a['papel'] ?? ('Etapa '.($i + 1)) }}</span>
                                    <span class="quem">
                                        @if($feita)
                                            {{ $a['responsavel_mascarado'] ?? 'Servidor autorizado' }}
                                        @else
                                            Aguardando
                                        @endif
                                    </span>
                                    @if(!empty($a['quando']))
                                        <span class="quando">{{ $a['quando'] }}</span>
                                    @endif
                                    <span class="chip {{ $feita ? 'ok' : 'warn' }}">
                                        <i class="fa {{ $feita ? 'fa-check' : 'fa-clock-o' }}"></i>
                                        {{ $a['status'] ?? ($feita ? 'Já assinou' : 'Pendente') }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if(!empty($d['etapas_faltantes']) && empty($d['completo']))
                        <p style="margin:12px 0 0;color:var(--suave);font-size:.82rem">
                            <i class="fa fa-hourglass-half" style="color:var(--warn)"></i>
                            Faltam: <strong style="color:var(--tinta)">{{ implode(', ', $d['etapas_faltantes']) }}</strong>
                        </p>
                    @endif
                @else
                    <p style="margin:0;color:var(--suave)">
                        @if(!empty($resultado['encontrado']))
                            Não há etapas de assinatura listadas para este código. O veredicto ao lado resume a autenticidade.
                        @else
                            Nenhuma etapa disponível.
                        @endif
                    </p>
                    <div class="legenda" style="margin-top:12px">
                        <div class="item"><strong><span class="dot ok"></span> Verde</strong> Completo</div>
                        <div class="item"><strong><span class="dot warn"></span> Amarelo</strong> Em andamento</div>
                        <div class="item"><strong><span class="dot erro"></span> Vermelho</strong> Inválido</div>
                    </div>
                @endif
            </section>
        </div>
    @endif

    <p class="rodape">
        LibreSign · i-Educar
        @auth
            · <a href="{{ url('/assinatura-digital') }}">Painel interno</a>
        @endauth
    </p>
</div>
</body>
</html>
