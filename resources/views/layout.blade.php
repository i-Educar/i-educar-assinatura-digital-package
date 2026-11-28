<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Assinatura digital')</title>
    <link rel="stylesheet" href="{{ url('/intranet/styles/font-awesome.css') }}">
    <style>
        :root {
            --cor: {{ $identidade['cor'] ?? '#0f4c5c' }};
            --destaque: {{ $identidade['destaque'] ?? '#1a7a8c' }};
            --clara: {{ $identidade['clara'] ?? '#e6f4f6' }};
            --nav: #15222b; --nav-texto: #e8eef3; --fundo: #e8edf0;
            --tinta: #1a242b; --suave: #5b6b76; --linha: #cfd8df;
            --ok: #17663f; --ok-fundo: #e5f6ec; --erro: #9b2c2c; --erro-fundo: #fdeceb;
            --ambar: #b86e00; --cor-tela: var(--cor);
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", sans-serif; font-size: 14px; color: var(--tinta);
            background: radial-gradient(900px 360px at 8% -8%, color-mix(in srgb, var(--clara) 80%, transparent), transparent 55%), linear-gradient(180deg, #f3f6f8, var(--fundo)); }
        .app { display: flex; min-height: 100vh; }
        .lateral { width: 248px; flex: none; background: linear-gradient(180deg, color-mix(in srgb, var(--cor) 35%, #15222b), var(--nav)); color: var(--nav-texto); }
        .marca { display: flex; align-items: center; justify-content: center; color: #fff; text-decoration: none; padding: 14px; border-bottom: 1px solid rgba(255,255,255,.08); }
        /* Escopo em .marca — não pode vazar para .botao.icone do cabeçalho */
        .marca .icone { width: 40px; height: 40px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center;
            background: linear-gradient(145deg, var(--destaque), var(--cor)); color: #fff; box-shadow: 0 8px 18px color-mix(in srgb, var(--cor) 35%, transparent); }
        .botao.icone { width: 40px; min-width: 40px; height: 40px; min-height: 40px; padding: 0; gap: 0; justify-content: center; flex: none; box-shadow: none; }
        .botao.icone i { font-size: 1.05rem; line-height: 1; margin: 0; }
        .modulo { padding: 8px 10px; }
        .modulo-nome { margin: 0; padding: 8px; color: #8aa0ad; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; font-weight: 700; }
        .modulo a { display: flex; align-items: center; gap: 10px; color: var(--nav-texto); text-decoration: none; border-radius: 10px; padding: 8px 10px; }
        .modulo a i { width: 18px; text-align: center; }
        .modulo a:hover { background: color-mix(in srgb, var(--cor) 42%, rgba(255,255,255,.08)); color: #fff; }
        .modulo a.ativo { background: color-mix(in srgb, var(--cor) 68%, #060b10); color: #fff; font-weight: 700; box-shadow: inset 3px 0 0 var(--destaque, var(--cor)); }
        .miolo { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        main { padding: 18px 20px 28px; flex: 1; }
        .cabeca-tela { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin: -18px -20px 16px; padding: 14px 16px 14px 20px;
            background: linear-gradient(120deg, color-mix(in srgb, var(--clara) 70%, var(--mix-branco, #fff)), var(--superficie, #fff) 55%); border-bottom: 1px solid var(--linha); border-left: 4px solid var(--cor-tela); }
        .identidade-tela { display: flex; align-items: center; gap: 12px; }
        .marca-tela { width: 46px; height: 46px; border-radius: 14px; display: grid; place-items: center; background: linear-gradient(145deg, var(--destaque), var(--cor-tela)); color: #fff; font-size: 18px; box-shadow: 0 10px 20px color-mix(in srgb, var(--cor-tela) 28%, transparent); }
        .cabeca-tela h1 { margin: 0; font-size: 1.25rem; }
        .cabeca-tela .lead { margin: 2px 0 0; color: var(--suave); }
        .acoes-tela { display: flex; flex-wrap: wrap; gap: 8px; margin-left: auto; }
        .botao { display: inline-flex; align-items: center; gap: 8px; background: var(--cor-tela); color: #fff; border: 0; border-radius: 10px; padding: 8px 12px; font: inherit; text-decoration: none; cursor: pointer; }
        .botao.claro { background: var(--superficie, #fff); color: var(--cor-tela); border: 1px solid color-mix(in srgb, var(--cor-tela) 35%, var(--linha)); }
        .botao.sm { padding: 6px 9px; font-size: .85rem; }
        .flash { border-radius: 12px; padding: 12px 14px; margin: 0 0 16px; }
        .flash.ok { background: var(--ok-fundo); color: var(--ok); }
        .flash.erro { background: var(--erro-fundo); color: var(--erro); }
        .bloco { background: var(--superficie, #fff); border: 1px solid var(--linha); border-radius: 14px; padding: 14px 16px; margin: 0 0 12px; border-top: 3px solid var(--cor-tela); box-shadow: 0 10px 24px rgba(21,34,43,.04); }
        .bloco h2 { margin: 0 0 10px; display: flex; align-items: center; gap: 8px; font-size: 1.05rem; }
        .texto-suave { color: var(--suave); font-size: .85rem; }
        .acoes-linha { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .numeros { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; margin: 0 0 16px; }
        .numeros article { --tom: var(--cor-tela); background: var(--superficie, #fff); border: 1px solid color-mix(in srgb, var(--tom) 30%, var(--linha)); border-top: 3px solid var(--tom); border-radius: 14px; padding: 14px; box-shadow: 0 8px 18px rgba(21,34,43,.05); }
        .numeros .kpi-ico { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; margin: 0 0 8px; background: color-mix(in srgb, var(--tom) 12%, var(--superficie, #fff)); color: var(--tom); border: 1px solid color-mix(in srgb, var(--tom) 22%, var(--superficie, #fff)); }
        .numeros span { display: block; color: var(--suave); font-size: .75rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .numeros strong { font-size: 1.4rem; letter-spacing: -.03em; }
        .atalhos { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
        .atalhos a, .atalhos button {
            display: grid; grid-template-columns: 42px 1fr; gap: 10px; align-items: center; width: 100%;
            min-height: 64px; padding: 10px 12px; border: 1px solid var(--linha); border-radius: 14px;
            text-decoration: none; color: var(--tinta); text-align: left; font: inherit; cursor: pointer;
            background: linear-gradient(180deg, color-mix(in srgb, var(--clara) 55%, var(--mix-branco, #fff)), var(--superficie, #fff)); font-weight: 700;
        }
        .atalhos a i, .atalhos button i { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; color: #fff; background: var(--destaque); }
        .atalhos a small, .atalhos button small { display: block; font-weight: 500; color: var(--suave); margin-top: 2px; }
        .atalhos a:hover, .atalhos button:hover { transform: translateY(-2px); border-color: var(--destaque); }
        table, table.lista { width: 100%; border-collapse: collapse; }
        table th, table td, table.lista th, table.lista td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--linha); vertical-align: top; }
        table th, table.lista th { background: var(--clara); color: var(--cor); font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; }
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: .75rem; font-weight: 700; }
        .badge.ok { background: var(--ok-fundo); color: var(--ok); }
        .badge.warn { background: #fff6e8; color: var(--ambar); }
        .badge.erro, .badge.fail { background: var(--erro-fundo); color: var(--erro); }
        .grade-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
        code { background: #f1f5f8; padding: 2px 6px; border-radius: 6px; font-size: .85rem; }
        @media (max-width: 860px) { .lateral { display: none; } }
    </style>
    @includeWhen(View::exists('buriti-ui::estilos'), 'buriti-ui::estilos')
    @stack('estilos')
</head>
<body>
@php $grupos = $menuGrupos ?? []; @endphp
<div class="app">
    <aside class="lateral" id="buriti-lateral">
        <a class="marca" href="{{ url('/assinatura-digital') }}" title="{{ $identidade['nome'] ?? 'Assinatura digital' }}">
            <span class="icone"><i class="fa {{ $identidade['icone'] ?? 'fa-pencil-square-o' }}"></i></span>
        </a>
        @foreach($grupos as $grupo)
            <nav class="modulo">
                <div class="modulo-nome">{{ $grupo['nome'] ?? $grupo['titulo'] ?? 'Assinatura' }}</div>
                @foreach($grupo['itens'] as $item)
                    @php
                        $urlItem = ltrim((string) ($item['url'] ?? ''), '/');
                        $ativo = $urlItem === 'assinatura-digital'
                            ? request()->is('assinatura-digital')
                            : request()->is($urlItem) || request()->is($urlItem.'/*');
                    @endphp
                    <a href="{{ url($item['url']) }}" class="{{ $ativo ? 'ativo' : '' }}">
                        <i class="fa {{ $item['icone'] }}"></i> {{ $item['rotulo'] }}
                    </a>
                @endforeach
            </nav>
        @endforeach
    </aside>
    <div class="miolo">
        @php
            $homeModulo = url('/assinatura-digital');
            $nomeModulo = $identidade['nome'] ?? 'Assinatura digital';
            $descricaoModulo = $identidade['descricao'] ?? 'LibreSign · PDFs e validação pública';
            $menuGrupos = $menuGrupos ?? ($grupos ?? []);
        @endphp
        @includeWhen(View::exists('buriti-ui::topo'), 'buriti-ui::topo')
        <main>
            @if(session('ok')) <p class="flash ok">{{ session('ok') }}</p> @endif
            @if(session('erro')) <p class="flash erro">{{ session('erro') }}</p> @endif
            @if(isset($errors) && $errors->any()) <p class="flash erro">{{ $errors->first() }}</p> @endif

            <header class="cabeca-tela">
                <div class="identidade-tela">
                    <span class="marca-tela"><i class="fa @yield('icone-tela', $identidade['icone'] ?? 'fa-pencil-square-o')" aria-hidden="true"></i></span>
                    <div>
                        <h1>@hasSection('titulo-tela')@yield('titulo-tela')@else@yield('titulo', 'Assinatura digital')@endif</h1>
                        @hasSection('lead')
                            <p class="lead">@yield('lead')</p>
                        @endif
                    </div>
                </div>
                <div class="acoes-tela">
                    @yield('acoes-topo')
                    @includeWhen(View::exists('buriti-ui::informes-modulo'), 'buriti-ui::informes-modulo', ['moduloId' => 'assinatura'])
                </div>
            </header>

            @hasSection('foco')
                @yield('foco')
            @endif
            @hasSection('trilho')
                <nav class="buriti-trilho" aria-label="Ações da tela">@yield('trilho')</nav>
            @endif

            @yield('conteudo')
        </main>
        @includeWhen(View::exists('buriti-ui::rodape'), 'buriti-ui::rodape', ['moduloRodape' => 'Assinatura digital'])
    </div>
</div>
@stack('scripts')
</body>
</html>
