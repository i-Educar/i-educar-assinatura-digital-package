<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Services;

use App\Models\NotificationType;
use App\Services\NotificationService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Fila de documentos pendentes da assinatura do usuário logado (1..N signatários).
 */
final class PendenciasAssinaturaService
{
    public function __construct(
        private readonly PedidoAssinaturaService $pedidos,
        private readonly UsuarioLibreSign $usuarios,
    ) {}

    public function ativo(): bool
    {
        return (bool) config('assinatura.ativo', true)
            && (bool) config('assinatura.fila_usuario', true)
            && Schema::hasTable('assinatura_digital.pedido');
    }

    /**
     * Painel / fila: módulo Laguna ativo + LibreSign configurado (e responsivo, com cache curto).
     */
    public function painelDisponivel(): bool
    {
        if (! $this->ativo()) {
            return false;
        }

        if (class_exists(\iEducar\Packages\BuritiLagunaAmbiente\Support\ModuloConfiguracao::class)
            && ! \iEducar\Packages\BuritiLagunaAmbiente\Support\ModuloConfiguracao::ativo('assinatura')) {
            return false;
        }

        try {
            $ls = app(LibreSign\LibreSignClient::class);
            if (! $ls->configurado()) {
                return false;
            }

            return (bool) \Illuminate\Support\Facades\Cache::remember(
                'assinatura.libresign.ping_ok',
                now()->addMinutes(2),
                fn (): bool => $ls->ping()
            );
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{
     *   pedido_id:int,
     *   titulo:string,
     *   status:string,
     *   cargo:?string,
     *   nome:?string,
     *   criado_em:?string,
     *   url:string,
     *   pode_assinar:bool
     * }>
     */
    public function listarParaUsuario(?Authenticatable $user, int $limit = 50): array
    {
        if (! $this->ativo() || $user === null) {
            return [];
        }

        $ids = $this->identidadeUsuario($user);
        if ($ids['user_id'] === null && $ids['login'] === null) {
            return [];
        }

        $rows = DB::table('assinatura_digital.pedido')
            ->whereIn('status', ['aguardando', 'parcial', 'rascunho', ''])
            ->orderByDesc('id')
            ->limit(max(50, $limit * 4))
            ->get();

        $out = [];
        $vistosOrigem = [];
        foreach ($rows as $row) {
            $pedido = $this->pedidos->hydrate((array) $row);
            $slot = $this->slotPendenteDoUsuario($pedido, $ids);
            if ($slot === null) {
                continue;
            }
            $pedidoId = (int) $pedido['id'];
            $meta = is_array($pedido['meta'] ?? null)
                ? $pedido['meta']
                : (json_decode((string) ($pedido['meta'] ?? ''), true) ?: []);
            $metaArr = is_array($meta) ? $meta : [];
            $tituloBase = $this->tituloDocumentoOrigem((string) ($pedido['titulo'] ?? 'Documento'), $metaArr);
            $chaveOrigem = $this->chaveDocumentoOrigem($metaArr, $tituloBase, $pedidoId);
            if (isset($vistosOrigem[$chaveOrigem])) {
                continue;
            }
            $vistosOrigem[$chaveOrigem] = true;
            $moduloChave = $this->chaveModuloAssinatura($metaArr, $tituloBase);
            $criadoRaw = isset($pedido['created_at']) ? (string) $pedido['created_at'] : null;
            $criadoIso = null;
            $diasEmFila = null;
            if ($criadoRaw !== null && $criadoRaw !== '') {
                try {
                    $criado = \Illuminate\Support\Carbon::parse($criadoRaw);
                    $criadoIso = $criado->toIso8601String();
                    $diasEmFila = (int) $criado->diffInDays(now());
                } catch (\Throwable) {
                    $criadoIso = null;
                    $diasEmFila = null;
                }
            }
            $out[] = [
                'pedido_id' => $pedidoId,
                'titulo' => $tituloBase,
                'status' => (string) ($pedido['status'] ?? 'aguardando'),
                'cargo' => ($slot['cargo'] ?? '') !== '' ? (string) $slot['cargo'] : null,
                'nome' => ($slot['nome'] ?? '') !== '' ? (string) $slot['nome'] : null,
                'criado_em' => $criadoRaw,
                'criado_em_iso' => $criadoIso,
                'dias_em_fila' => $diasEmFila,
                'critico_fila' => $diasEmFila !== null && $diasEmFila > 7,
                'modulo' => $moduloChave,
                'modulo_rotulo' => $this->rotuloModuloAssinatura($moduloChave),
                'url' => url('/assinatura-digital/pendencias'),
                'url_ver' => url('/assinatura-digital/pedidos/'.$pedidoId.'/ver'),
                'url_pdf' => url('/assinatura-digital/pedidos/'.$pedidoId.'/pdf'),
                'url_assinar' => url('/assinatura-digital/pendencias/'.$pedidoId.'/confirmar'),
                'pode_assinar' => true,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function contarParaUsuario(?Authenticatable $user): int
    {
        return count($this->listarParaUsuario($user, 100));
    }

    /**
     * Documentos em que o usuário já assinou (completos ou ainda na fila de outros).
     *
     * @param  array{filtro?:string,pagina?:int,por_pagina?:int,forcar_filtro?:bool}  $opcoes
     * @return array{
     *   itens:list<array<string,mixed>>,
     *   totais:array{todos:int,andamento:int,prontos:int},
     *   filtro:string,
     *   pagina:int,
     *   por_pagina:int,
     *   total_filtrado:int,
     *   ultima_pagina:int
     * }
     */
    public function listarAssinadosParaUsuario(?Authenticatable $user, int $limit = 50, array $opcoes = []): array
    {
        $vazio = [
            'itens' => [],
            'totais' => ['todos' => 0, 'andamento' => 0, 'prontos' => 0],
            'filtro' => 'andamento',
            'pagina' => 1,
            'por_pagina' => 8,
            'total_filtrado' => 0,
            'ultima_pagina' => 1,
        ];

        if (! $this->ativo() || $user === null) {
            return $vazio;
        }

        $ids = $this->identidadeUsuario($user);
        if ($ids['user_id'] === null && $ids['login'] === null) {
            return $vazio;
        }

        $filtro = mb_strtolower(trim((string) ($opcoes['filtro'] ?? 'andamento')));
        if (! in_array($filtro, ['andamento', 'prontos', 'todos', 'destaque'], true)) {
            $filtro = 'andamento';
        }
        $porPagina = max(3, min(30, (int) ($opcoes['por_pagina'] ?? 8)));
        $pagina = max(1, (int) ($opcoes['pagina'] ?? 1));
        $forcarFiltro = ! empty($opcoes['forcar_filtro']);

        $rows = DB::table('assinatura_digital.pedido')
            ->whereIn('status', ['aguardando', 'parcial', 'assinado'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(max(200, $limit * 8))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $pedido = $this->pedidos->hydrate((array) $row);
            $slots = $this->slotsDoPedido($pedido);
            $meuSlot = null;
            $assinados = 0;
            $total = 0;
            $pendentesOutros = [];
            $assinaturasColetadas = [];
            $ultimaAssinaturaEm = null;

            $statusPedido = (string) ($pedido['status'] ?? '');
            $souSolicitante = $ids['user_id'] !== null
                && (int) ($pedido['solicitante_id'] ?? 0) === (int) $ids['user_id'];
            $meta = is_array($pedido['meta'] ?? null)
                ? $pedido['meta']
                : (json_decode((string) ($pedido['meta'] ?? ''), true) ?: []);
            $meta = is_array($meta) ? $meta : [];
            $forcarImediato = ! empty($meta['forcar_assinatura_imediata']);

            foreach ($slots as $slot) {
                if (! is_array($slot)) {
                    continue;
                }
                $total++;
                $pertence = $this->slotPertenceAoUsuario($slot, $ids);
                // Pedido concluído / etapa forçada no módulo: conta como assinado mesmo com
                // visual genérico ("Assinatura parcial") sem flag signed.
                $ja = $this->slotJaAssinado($slot)
                    || ($pertence && $statusPedido === 'assinado')
                    || ($pertence && $this->slotIndicaAssinaturaLocal($slot))
                    || ($pertence && $forcarImediato);
                $cargo = trim((string) ($slot['cargo'] ?? ''));
                $nome = trim((string) ($slot['nome'] ?? $slot['displayName'] ?? ''));
                $quandoSlot = trim((string) ($slot['quando'] ?? ''));
                if ($quandoSlot === '' && $ja) {
                    $quandoSlot = trim((string) ($pedido['assinado_em'] ?? $pedido['updated_at'] ?? ''));
                }
                $quandoHumano = $this->humanizarDataHora($quandoSlot !== '' ? $quandoSlot : null);

                if ($ja) {
                    $assinados++;
                    $assinaturasColetadas[] = [
                        'papel' => $cargo !== '' ? $cargo : 'Signatário',
                        'nome' => $nome !== '' ? $nome : null,
                        'quando' => $quandoSlot !== '' ? $quandoSlot : null,
                        'quando_humano' => $quandoHumano['absoluto'] ?? null,
                        'quando_relativo' => $quandoHumano['relativo'] ?? null,
                        'minha' => $pertence,
                    ];
                    if ($quandoSlot !== '') {
                        try {
                            $ts = \Illuminate\Support\Carbon::parse($quandoSlot);
                            if ($ultimaAssinaturaEm === null || $ts->gt($ultimaAssinaturaEm)) {
                                $ultimaAssinaturaEm = $ts;
                            }
                        } catch (\Throwable) {
                            //
                        }
                    }
                }

                if ($pertence) {
                    if ($ja) {
                        $meuSlot = $slot;
                        if (trim((string) ($meuSlot['quando'] ?? '')) === '' && $quandoSlot !== '') {
                            $meuSlot['quando'] = $quandoSlot;
                        }
                    }
                } elseif (! $ja) {
                    $pendentesOutros[] = $cargo !== '' ? $cargo : ($nome !== '' ? $nome : 'Signatário');
                }
            }

            // Nota almox / assinatura imediata: solicitante sem user_id no slot visual.
            if ($meuSlot === null && $souSolicitante && $total > 0
                && ($forcarImediato || in_array($statusPedido, ['assinado', 'parcial'], true))) {
                foreach ($slots as $slot) {
                    if (! is_array($slot)) {
                        continue;
                    }
                    if ($this->slotJaAssinado($slot) || $forcarImediato || $statusPedido === 'assinado'
                        || $this->slotIndicaAssinaturaLocal($slot)) {
                        $meuSlot = $slot;
                        if (trim((string) ($meuSlot['quando'] ?? '')) === '') {
                            $meuSlot['quando'] = (string) ($pedido['assinado_em'] ?? $pedido['updated_at'] ?? now()->toDateTimeString());
                        }
                        $assinados = max($assinados, 1);
                        break;
                    }
                }
                if ($meuSlot === null && ($statusPedido === 'assinado' || $forcarImediato)) {
                    $meuSlot = is_array($slots[0] ?? null) ? $slots[0] : [
                        'cargo' => 'Signatário',
                        'quando' => $pedido['assinado_em'] ?? $pedido['updated_at'] ?? null,
                    ];
                    $assinados = max($assinados, 1);
                }
            }

            if ($meuSlot === null || $total === 0) {
                continue;
            }

            // Status do pedido LibreSign (pode ser 1 etapa).
            $completoLs = $assinados >= $total
                || $statusPedido === 'assinado'
                || ($forcarImediato && $assinados >= $total);

            // Fluxo multi-etapa do documento de origem (ex.: nota almox Separação→Conferência).
            $fluxo = $this->progressoFluxoDocumentoOrigem($meta);
            $completo = $completoLs;
            if ($fluxo !== null) {
                $assinados = (int) $fluxo['concluidas'];
                $total = (int) $fluxo['total'];
                $completo = (bool) $fluxo['completo'];
                // Pendências = etapas ainda não assinadas (sem nome de ator).
                $pendentesOutros = array_values(array_unique(array_merge(
                    $pendentesOutros,
                    $fluxo['pendentes'],
                )));
            }

            $pedidoId = (int) $pedido['id'];

            $geradoRaw = (string) ($pedido['created_at'] ?? '');
            $gerado = $this->humanizarDataHora($geradoRaw !== '' ? $geradoRaw : null);
            $assineiRaw = trim((string) ($meuSlot['quando'] ?? ''));
            $assinei = $this->humanizarDataHora($assineiRaw !== '' ? $assineiRaw : null);

            $finalizadoRaw = '';
            if ($completo) {
                $finalizadoRaw = trim((string) ($pedido['assinado_em'] ?? ''));
                if ($finalizadoRaw === '' && $ultimaAssinaturaEm !== null) {
                    $finalizadoRaw = $ultimaAssinaturaEm->toDateTimeString();
                }
            }
            $finalizado = $this->humanizarDataHora($finalizadoRaw !== '' ? $finalizadoRaw : null);

            $linhasTempo = [];
            if (! empty($gerado['absoluto'])) {
                $linhasTempo[] = 'Gerado '.$gerado['absoluto']
                    .(! empty($gerado['relativo']) ? ' ('.$gerado['relativo'].')' : '');
            }
            if (! empty($assinei['absoluto'])) {
                $linhasTempo[] = 'Você assinou '.$assinei['absoluto']
                    .(! empty($assinei['relativo']) ? ' ('.$assinei['relativo'].')' : '');
            }
            if ($completo && ! empty($finalizado['absoluto'])) {
                $linhasTempo[] = 'Processo finalizado '.$finalizado['absoluto']
                    .(! empty($finalizado['relativo']) ? ' ('.$finalizado['relativo'].')' : '');
            } elseif (! $completo && $pendentesOutros !== []) {
                $linhasTempo[] = 'Já assinou — ainda faltam etapas: '.implode(', ', $pendentesOutros);
            } elseif (! $completo) {
                $linhasTempo[] = 'Já assinou — documento ainda em andamento';
            }

            $outrasColetadas = [];
            foreach ($assinaturasColetadas as $a) {
                if (! empty($a['minha'])) {
                    continue;
                }
                $rotulo = trim((string) (($a['papel'] ?? '').(! empty($a['nome']) ? ' ('.$a['nome'].')' : '')));
                $quandoTxt = (string) ($a['quando_humano'] ?? '');
                $outrasColetadas[] = $rotulo.($quandoTxt !== '' ? ' — '.$quandoTxt : '');
            }

            $metaArr = is_array($meta) ? $meta : [];
            $moduloChave = $this->chaveModuloAssinatura($metaArr, (string) ($pedido['titulo'] ?? ''));
            $assineiIso = null;
            if ($assineiRaw !== '') {
                try {
                    $assineiIso = \Illuminate\Support\Carbon::parse($assineiRaw)->toIso8601String();
                } catch (\Throwable) {
                    $assineiIso = null;
                }
            }

            $tituloBase = $this->tituloDocumentoOrigem(
                (string) ($pedido['titulo'] ?? 'Documento'),
                $metaArr,
            );

            $out[] = [
                'pedido_id' => $pedidoId,
                'titulo' => $tituloBase,
                'estado' => $completo ? 'completo' : 'parcial',
                'estado_rotulo' => $completo
                    ? 'Pronto — todas as etapas'
                    : 'Já assinei — faltam outras etapas',
                'progresso' => $assinados.'/'.$total,
                'assinaturas_feitas' => $assinados,
                'assinaturas_total' => $total,
                'assinatura_unica' => $total <= 1,
                'cargo' => trim((string) ($meuSlot['cargo'] ?? '')) ?: null,
                'quando' => $assinei['absoluto'] ?? ($assineiRaw !== '' ? $assineiRaw : null),
                'gerado_em' => $gerado['absoluto'] ?? null,
                'gerado_em_relativo' => $gerado['relativo'] ?? null,
                'assinei_em' => $assinei['absoluto'] ?? null,
                'assinei_em_relativo' => $assinei['relativo'] ?? null,
                'assinei_em_iso' => $assineiIso,
                'finalizado_em' => $finalizado['absoluto'] ?? null,
                'finalizado_em_relativo' => $finalizado['relativo'] ?? null,
                'linhas_tempo' => $linhasTempo,
                'resumo_tempo' => implode(' · ', $linhasTempo),
                'assinaturas_coletadas' => $assinaturasColetadas,
                'outras_assinaturas_humano' => $outrasColetadas,
                'url_ver' => url('/assinatura-digital/pedidos/'.$pedidoId.'/ver'),
                'url_pdf' => url('/assinatura-digital/pedidos/'.$pedidoId.'/pdf'),
                'pendentes_outros' => $pendentesOutros,
                'fluxo_multi_etapa' => $fluxo !== null || $total > 1,
                'modulo' => $moduloChave,
                'modulo_rotulo' => $this->rotuloModuloAssinatura($moduloChave),
                '_chave_origem' => $this->chaveDocumentoOrigem($metaArr, $tituloBase, $pedidoId),
            ];
            if (count($out) >= 300) {
                break;
            }
        }

        // Almox: etapas assinadas localmente (ex.: Conferência) mesmo se o LibreSign
        // falhou (429) ou ficou em "agendado" sem pedido_assinatura_id.
        $out = array_merge($out, $this->itensAlmoxAssinadosLocalmente($ids, max(50, $limit)));

        // Uma linha por documento de origem (ex.: nota almox #158 com 4 pedidos LS = 1 item).
        $out = $this->consolidarPorDocumentoOrigem($out);

        usort($out, static function (array $a, array $b): int {
            $pa = ($a['estado'] ?? '') === 'parcial' ? 0 : 1;
            $pb = ($b['estado'] ?? '') === 'parcial' ? 0 : 1;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return ((int) ($b['pedido_id'] ?? 0)) <=> ((int) ($a['pedido_id'] ?? 0));
        });

        $totais = [
            'todos' => count($out),
            'andamento' => count(array_filter($out, static fn (array $i): bool => ($i['estado'] ?? '') === 'parcial')),
            'prontos' => count(array_filter($out, static fn (array $i): bool => ($i['estado'] ?? '') === 'completo')),
        ];

        // Destaque (dashboard): poucos itens — prioriza em andamento e completa com os mais recentes.
        if ($filtro === 'destaque') {
            $andamento = array_values(array_filter($out, static fn (array $i): bool => ($i['estado'] ?? '') === 'parcial'));
            $prontos = array_values(array_filter($out, static fn (array $i): bool => ($i['estado'] ?? '') === 'completo'));
            $itens = array_slice(array_merge($andamento, $prontos), 0, $porPagina);

            return [
                'itens' => $itens,
                'totais' => $totais,
                'filtro' => 'destaque',
                'pagina' => 1,
                'por_pagina' => $porPagina,
                'total_filtrado' => $totais['todos'],
                'ultima_pagina' => 1,
            ];
        }

        // Se pediu andamento e não há nenhum (sem forçar), mostra os últimos de todos.
        if ($filtro === 'andamento' && $totais['andamento'] === 0 && ! $forcarFiltro) {
            $filtro = 'todos';
        }

        $filtrados = match ($filtro) {
            'prontos' => array_values(array_filter($out, static fn (array $i): bool => ($i['estado'] ?? '') === 'completo')),
            'andamento' => array_values(array_filter($out, static fn (array $i): bool => ($i['estado'] ?? '') === 'parcial')),
            default => array_values($out),
        };

        $totalFiltrado = count($filtrados);
        $ultimaPagina = max(1, (int) ceil($totalFiltrado / max(1, $porPagina)));
        if ($pagina > $ultimaPagina) {
            $pagina = $ultimaPagina;
        }
        $offset = ($pagina - 1) * $porPagina;
        $itens = array_slice($filtrados, $offset, $porPagina);

        return [
            'itens' => $itens,
            'totais' => $totais,
            'filtro' => $filtro,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_filtrado' => $totalFiltrado,
            'ultima_pagina' => $ultimaPagina,
        ];
    }

    /**
     * Notas de almox em que o usuário assinou uma etapa no i-Educar, mesmo sem
     * pedido LibreSign (falha de API / 429 / ainda agendado).
     *
     * @param  array{user_id:?int,login:?string}  $ids
     * @return list<array<string,mixed>>
     */
    private function itensAlmoxAssinadosLocalmente(array $ids, int $limit): array
    {
        if (! class_exists(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PocRegistro::class)
            || ! class_exists(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::class)
            || ! \iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PocRegistro::disponivel()
        ) {
            return [];
        }

        $uid = (int) ($ids['user_id'] ?? 0);
        $login = mb_strtolower(trim((string) ($ids['login'] ?? '')));
        if ($uid <= 0 && $login === '') {
            return [];
        }

        $etapas = ['aceite', 'entrega', 'conferencia'];
        $out = [];

        try {
            $rows = DB::table(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PocRegistro::TABELA)
                ->where('tipo', \iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::TIPO)
                ->orderByDesc('id')
                ->limit(max(40, min(120, $limit * 2)))
                ->get(['id', 'payload', 'created_at', 'updated_at']);
        } catch (Throwable) {
            return [];
        }

        foreach ($rows as $row) {
            $payload = json_decode((string) ($row->payload ?? ''), true);
            if (! is_array($payload)) {
                continue;
            }
            $assin = is_array($payload['assinaturas'] ?? null) ? $payload['assinaturas'] : [];
            $meuPapel = null;
            $assineiRaw = null;
            $assinaturasColetadas = [];
            $pendentes = [];
            $concluidas = 0;

            foreach ($etapas as $etapa) {
                $a = is_array($assin[$etapa] ?? null) ? $assin[$etapa] : null;
                $rotulo = \iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::rotuloEtapa($etapa);
                $feita = $a !== null && trim((string) ($a['responsavel'] ?? '')) !== '';
                if (! $feita) {
                    $pendentes[] = $rotulo;
                    continue;
                }
                $concluidas++;
                $aUid = (int) ($a['responsavel_id'] ?? 0);
                $aLogin = mb_strtolower(trim((string) ($a['login'] ?? '')));
                $pertence = ($uid > 0 && $aUid === $uid)
                    || ($login !== '' && $aLogin !== '' && $aLogin === $login);
                $quando = trim((string) ($a['quando_raw'] ?? $a['quando_iso'] ?? $a['quando'] ?? ''));
                $quandoHumano = $this->humanizarDataHora($quando !== '' ? $quando : null);
                $assinaturasColetadas[] = [
                    'papel' => $rotulo,
                    'nome' => trim((string) ($a['responsavel'] ?? '')) ?: null,
                    'quando' => $quando !== '' ? $quando : null,
                    'quando_humano' => $quandoHumano['absoluto'] ?? null,
                    'quando_relativo' => $quandoHumano['relativo'] ?? null,
                    'minha' => $pertence,
                ];
                if ($pertence && $meuPapel === null) {
                    $meuPapel = $rotulo;
                    $assineiRaw = $quando;
                }
            }

            if ($meuPapel === null) {
                continue;
            }

            $pedidoId = (int) $row->id;
            $completo = $pendentes === [];
            $total = count($etapas);
            $titulo = 'Nota almoxarifado #'.$pedidoId;
            $assinei = $this->humanizarDataHora($assineiRaw !== null && $assineiRaw !== '' ? $assineiRaw : null);
            $assineiIso = null;
            if ($assineiRaw !== null && $assineiRaw !== '') {
                try {
                    $assineiIso = \Illuminate\Support\Carbon::parse($assineiRaw)->toIso8601String();
                } catch (\Throwable) {
                    $assineiIso = null;
                }
            }

            // Preferir link do pedido LS mais recente da nota, se existir.
            $pedidoLsId = 0;
            foreach (array_reverse($etapas) as $etapa) {
                $a = is_array($assin[$etapa] ?? null) ? $assin[$etapa] : null;
                $lsId = (int) ($a['pedido_assinatura_id'] ?? 0);
                if ($lsId > 0) {
                    $pedidoLsId = $lsId;
                    break;
                }
            }

            $linhasTempo = [];
            if (! empty($assinei['absoluto'])) {
                $linhasTempo[] = 'Você assinou '.$assinei['absoluto']
                    .(! empty($assinei['relativo']) ? ' ('.$assinei['relativo'].')' : '')
                    .' — '.$meuPapel;
            }
            if (! $completo && $pendentes !== []) {
                $linhasTempo[] = 'Já assinou — ainda faltam etapas: '.implode(', ', $pendentes);
            } elseif ($completo) {
                $linhasTempo[] = 'Processo finalizado (todas as etapas da nota)';
            }

            $out[] = [
                'pedido_id' => $pedidoLsId > 0 ? $pedidoLsId : $pedidoId,
                'titulo' => $titulo,
                'estado' => $completo ? 'completo' : 'parcial',
                'estado_rotulo' => $completo
                    ? 'Pronto — todas as etapas'
                    : 'Já assinei — faltam outras etapas',
                'progresso' => $concluidas.'/'.$total,
                'assinaturas_feitas' => $concluidas,
                'assinaturas_total' => $total,
                'assinatura_unica' => false,
                'cargo' => $meuPapel,
                'quando' => $assinei['absoluto'] ?? $assineiRaw,
                'gerado_em' => null,
                'gerado_em_relativo' => null,
                'assinei_em' => $assinei['absoluto'] ?? null,
                'assinei_em_relativo' => $assinei['relativo'] ?? null,
                'assinei_em_iso' => $assineiIso,
                'finalizado_em' => $completo ? ($assinei['absoluto'] ?? null) : null,
                'finalizado_em_relativo' => $completo ? ($assinei['relativo'] ?? null) : null,
                'linhas_tempo' => $linhasTempo,
                'resumo_tempo' => implode(' · ', $linhasTempo),
                'assinaturas_coletadas' => $assinaturasColetadas,
                'outras_assinaturas_humano' => [],
                'url_ver' => $pedidoLsId > 0
                    ? url('/assinatura-digital/pedidos/'.$pedidoLsId.'/ver')
                    : url('/almoxarifado-escolar/pedidos/'.$pedidoId.'/nota'),
                'url_pdf' => $pedidoLsId > 0
                    ? url('/assinatura-digital/pedidos/'.$pedidoLsId.'/pdf')
                    : url('/almoxarifado-escolar/pedidos/'.$pedidoId.'/nota/pdf'),
                'pendentes_outros' => $pendentes,
                'fluxo_multi_etapa' => true,
                'modulo' => 'almoxarifado',
                'modulo_rotulo' => $this->rotuloModuloAssinatura('almoxarifado'),
                '_chave_origem' => 'almoxarifado:'.$pedidoId,
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Progresso do documento de origem quando há várias etapas (ex.: almox).
     * Pendentes = só rótulos de etapa (sem atores).
     *
     * @param  array<string,mixed>  $meta
     * @return array{total:int,concluidas:int,pendentes:list<string>,completo:bool}|null
     */
    private function progressoFluxoDocumentoOrigem(array $meta): ?array
    {
        $origem = mb_strtolower(trim((string) ($meta['origem'] ?? '')));
        $modulo = mb_strtolower(trim((string) ($meta['modulo_origem'] ?? '')));
        $temFluxo = str_contains($origem, 'almox')
            || str_contains($modulo, 'almox')
            || ! empty($meta['etapas_esperadas'])
            || (! empty($meta['pedido_id']) && ! empty($meta['etapa']));
        if (! $temFluxo) {
            return null;
        }

        $esperadas = [];
        $rawEsp = $meta['etapas_esperadas'] ?? null;
        if (is_array($rawEsp) && $rawEsp !== []) {
            foreach ($rawEsp as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $esperadas[] = trim($item);
                } elseif (is_array($item)) {
                    $papel = trim((string) ($item['papel'] ?? $item['rotulo'] ?? $item['etapa'] ?? ''));
                    if ($papel !== '') {
                        $esperadas[] = $papel;
                    }
                }
            }
        }

        $concluidas = [];
        $pedidoOrigemId = (int) ($meta['pedido_id'] ?? 0);
        if ($pedidoOrigemId > 0
            && class_exists(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::class)) {
            try {
                $pedido = \iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::obter($pedidoOrigemId);
                $payload = is_array($pedido['payload'] ?? null) ? $pedido['payload'] : [];
                $assin = is_array($payload['assinaturas'] ?? null) ? $payload['assinaturas'] : [];
                $ordem = ['separacao', 'aceite', 'entrega', 'conferencia'];
                if ($esperadas === []) {
                    foreach ($ordem as $etapa) {
                        $esperadas[] = \iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::rotuloEtapa($etapa);
                    }
                }
                foreach ($ordem as $i => $etapa) {
                    $rotulo = $esperadas[$i]
                        ?? \iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::rotuloEtapa($etapa);
                    $a = is_array($assin[$etapa] ?? null) ? $assin[$etapa] : null;
                    if ($a !== null && trim((string) ($a['responsavel'] ?? '')) !== '') {
                        $concluidas[] = $rotulo;
                    }
                }
            } catch (Throwable) {
                //
            }
        }

        if ($esperadas === []) {
            return null;
        }

        $pendentes = [];
        foreach ($esperadas as $rotulo) {
            $ok = false;
            foreach ($concluidas as $c) {
                if (mb_strtolower($c) === mb_strtolower($rotulo)) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                $pendentes[] = $rotulo;
            }
        }

        $total = count($esperadas);
        $nOk = $total - count($pendentes);

        return [
            'total' => $total,
            'concluidas' => max(0, $nOk),
            'pendentes' => $pendentes,
            'completo' => $pendentes === [],
        ];
    }

    private function humanizarDataHora(mixed $valor): array
    {
        if ($valor === null || $valor === '') {
            return ['absoluto' => null, 'relativo' => null];
        }

        try {
            $c = \Illuminate\Support\Carbon::parse((string) $valor)->locale('pt_BR');
        } catch (\Throwable) {
            $txt = trim((string) $valor);

            return ['absoluto' => $txt !== '' ? $txt : null, 'relativo' => null];
        }

        return [
            'absoluto' => $c->translatedFormat('d/m/Y \à\s H:i'),
            'relativo' => $c->diffForHumans(),
        ];
    }

    /**
     * Confirma e assina no LibreSign apenas o slot do usuário.
     *
     * @return array{ok:bool,mensagem:string,pedido:?array<string,mixed>}
     */
    public function confirmar(int $pedidoId, Authenticatable $user, ?string $senhaLibreSign = null): array
    {
        if (! $this->ativo()) {
            return ['ok' => false, 'mensagem' => 'Fila de assinaturas desativada.', 'pedido' => null];
        }

        $pedido = $this->pedidos->obter($pedidoId);
        if ($pedido === []) {
            return ['ok' => false, 'mensagem' => 'Pedido não encontrado.', 'pedido' => null];
        }

        $ids = $this->identidadeUsuario($user);
        $slot = $this->slotPendenteDoUsuario($pedido, $ids);
        if ($slot === null) {
            return ['ok' => false, 'mensagem' => 'Não há assinatura pendente sua neste documento.', 'pedido' => $pedido];
        }

        $uuid = (string) ($pedido['libresign_uuid'] ?? '');
        if ($uuid === '') {
            return ['ok' => false, 'mensagem' => 'Pedido sem UUID LibreSign.', 'pedido' => $pedido];
        }

        // Visual costuma não guardar senha; o JSON signatarios pode ter account/senha do mapeamento.
        $signatarios = is_array($pedido['signatarios'] ?? null) ? $pedido['signatarios'] : [];
        foreach ($signatarios as $s) {
            if (! is_array($s) || ! $this->slotPertenceAoUsuario($s, $ids)) {
                continue;
            }
            $slot = array_merge($slot, array_filter([
                'account' => $slot['account'] ?? ($s['account'] ?? null),
                'password' => $slot['password'] ?? ($s['password'] ?? null),
                'login' => $slot['login'] ?? ($s['login'] ?? null),
                'email' => $slot['email'] ?? ($s['email'] ?? null),
                'cargo' => $slot['cargo'] ?? ($s['cargo'] ?? null),
            ], static fn ($v) => $v !== null && $v !== ''));
            break;
        }

        $slot['login'] = $slot['login'] ?? $ids['login'];
        $slot['user_id'] = $slot['user_id'] ?? $ids['user_id'];

        $cred = $this->usuarios->credenciaisParaAssinar($slot, $senhaLibreSign);
        $account = trim((string) ($cred['account'] ?? ''));
        $password = trim((string) ($cred['password'] ?? ''));
        // Conta do LibreSign (após mapa cargo→signer*): preferir essa no match do sign_uuid.
        $accountPedido = trim((string) ($slot['account'] ?? ''));
        $preferida = $account !== '' ? $account : $accountPedido;

        try {
            $resultadoUm = $this->pedidos->assinarUm(
                $uuid,
                $preferida,
                $password,
                $preferida,
            );
            $ok = (bool) ($resultadoUm['ok'] ?? false);
            $msg = (string) ($resultadoUm['mensagem'] ?? '');
            $accountUsada = trim((string) ($resultadoUm['account'] ?? $account));
            $this->pedidos->sincronizarAposAssinatura($pedidoId, $uuid);
            $pedido = $this->pedidos->obter($pedidoId);

            DB::table('assinatura_digital.evento')->insert([
                'pedido_id' => $pedidoId,
                'tipo' => $ok ? 'assinatura_usuario' : 'assinatura_usuario_falhou',
                'mensagem' => $ok
                    ? 'Assinatura confirmada pelo usuário '.$accountUsada
                    : 'Falha na assinatura do usuário '.$accountUsada.': '.$msg,
                'payload' => json_encode([
                    'user_id' => $ids['user_id'],
                    'login' => $ids['login'],
                    'account' => $accountUsada,
                    'via' => $cred['via'] ?? null,
                    'resultado' => $resultadoUm,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            if (! $ok) {
                return ['ok' => false, 'mensagem' => $msg !== '' ? $msg : 'LibreSign recusou a assinatura.', 'pedido' => $pedido];
            }

            $this->marcarSlotAssinadoLocal($pedidoId, $ids, $accountUsada);
            $pedido = $this->pedidos->obter($pedidoId);
            $this->notificarProgressoAposAssinatura($pedido, $ids);

            return [
                'ok' => true,
                'mensagem' => 'Assinatura registrada no LibreSign.',
                'pedido' => $pedido,
            ];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'mensagem' => $e->getMessage(), 'pedido' => $pedido];
        }
    }

    /**
     * Após uma assinatura: avisa quem já assinou (progresso) e quem ainda falta.
     *
     * @param  array<string,mixed>  $pedido
     * @param  array{user_id:?int,login:?string}  $ator
     */
    public function notificarProgressoAposAssinatura(array $pedido, array $ator): void
    {
        if (! $this->ativo() || ! class_exists(NotificationService::class)) {
            return;
        }

        $titulo = (string) ($pedido['titulo'] ?? 'Documento');
        $pedidoId = (int) ($pedido['id'] ?? 0);
        $status = (string) ($pedido['status'] ?? '');
        $completo = $status === 'assinado';
        $linkAssinados = url('/assinatura-digital/assinados');
        $linkPendencias = url('/assinatura-digital/pendencias');
        $atorId = (int) ($ator['user_id'] ?? 0);

        $vistos = [];
        foreach ($this->slotsDoPedido($pedido) as $slot) {
            $uid = (int) ($slot['user_id'] ?? 0);
            if ($uid <= 0 || isset($vistos[$uid]) || ($atorId > 0 && $uid === $atorId)) {
                continue;
            }
            $vistos[$uid] = true;
            $jaAssinou = $this->slotJaAssinado($slot);
            try {
                if ($jaAssinou) {
                    $texto = $completo
                        ? 'Documento concluído (todas as assinaturas): '.$titulo
                        : 'Nova assinatura no documento que você já assinou — ainda faltam outras: '.$titulo;
                    app(NotificationService::class)->createByUser(
                        $uid,
                        $texto,
                        $linkAssinados,
                        NotificationType::OTHER,
                    );
                } else {
                    app(NotificationService::class)->createByUser(
                        $uid,
                        'Documento ainda pendente da sua assinatura digital: '.$titulo,
                        $linkPendencias,
                        NotificationType::OTHER,
                    );
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($pedidoId > 0 && $vistos !== []) {
            try {
                DB::table('assinatura_digital.evento')->insert([
                    'pedido_id' => $pedidoId,
                    'tipo' => 'notificacao_progresso',
                    'mensagem' => 'Progresso notificado a: '.implode(', ', array_keys($vistos)),
                    'payload' => json_encode([
                        'user_ids' => array_keys($vistos),
                        'completo' => $completo,
                        'ator_id' => $atorId,
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            } catch (Throwable) {
                //
            }
        }
    }

    /**
     * Garante que o slot do usuário some da fila mesmo se o LibreSign atrasar o flag signed.
     *
     * @param  array{user_id:?int,login:?string}  $ids
     */
    private function marcarSlotAssinadoLocal(int $pedidoId, array $ids, string $account): void
    {
        $pedido = $this->pedidos->obter($pedidoId);
        if ($pedido === []) {
            return;
        }
        $meta = is_array($pedido['meta'] ?? null) ? $pedido['meta'] : [];
        $visuais = is_array($meta['assinaturas_visuais'] ?? null) ? $meta['assinaturas_visuais'] : [];
        if ($visuais === []) {
            return;
        }
        $mudou = false;
        foreach ($visuais as $i => $slot) {
            if (! is_array($slot) || ! $this->slotPertenceAoUsuario($slot, $ids)) {
                continue;
            }
            $acc = mb_strtolower(trim((string) ($slot['account'] ?? '')));
            if ($acc !== '' && $account !== '' && $acc !== mb_strtolower($account)) {
                continue;
            }
            $visuais[$i]['status'] = 'Assinado digitalmente';
            $visuais[$i]['signed'] = true;
            $visuais[$i]['quando'] = now()->toDateTimeString();
            $mudou = true;
        }
        if (! $mudou) {
            return;
        }
        $meta['assinaturas_visuais'] = $visuais;
        try {
            DB::table('assinatura_digital.pedido')->where('id', $pedidoId)->update([
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Notifica cada signatário com user_id ainda aguardando.
     *
     * @param  array<string,mixed>  $pedido
     */
    public function notificarSignatarios(array $pedido): void
    {
        if (! $this->ativo() || ! class_exists(NotificationService::class)) {
            return;
        }

        $titulo = (string) ($pedido['titulo'] ?? 'Documento');
        $pedidoId = (int) ($pedido['id'] ?? 0);
        $link = url('/assinatura-digital/pendencias');
        $texto = 'Documento pendente de sua assinatura digital: '.$titulo;

        $vistos = [];
        foreach ($this->slotsDoPedido($pedido) as $slot) {
            if ($this->slotJaAssinado($slot)) {
                continue;
            }
            $uid = (int) ($slot['user_id'] ?? 0);
            if ($uid <= 0 || isset($vistos[$uid])) {
                continue;
            }
            $vistos[$uid] = true;
            try {
                app(NotificationService::class)->createByUser(
                    $uid,
                    $texto,
                    $link,
                    NotificationType::OTHER,
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Almox/etapas compartilhadas: todos com permissão da etapa (não só o slot LS).
        foreach ($this->usuariosExtrasPorPermissaoEtapa($pedido) as $uid) {
            if (isset($vistos[$uid])) {
                continue;
            }
            $vistos[$uid] = true;
            try {
                app(NotificationService::class)->createByUser(
                    $uid,
                    $texto,
                    $link,
                    NotificationType::OTHER,
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($pedidoId > 0 && $vistos !== []) {
            try {
                DB::table('assinatura_digital.evento')->insert([
                    'pedido_id' => $pedidoId,
                    'tipo' => 'notificacao_pendencia',
                    'mensagem' => 'Notificados: '.implode(', ', array_keys($vistos)),
                    'payload' => json_encode(['user_ids' => array_keys($vistos)], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            } catch (Throwable) {
                //
            }
        }
    }

    /**
     * @param  array{user_id:?int,login:?string}  $ids
     * @param  array<string,mixed>  $pedido
     * @return array<string,mixed>|null
     */

    /**
     * Quando o pedido veio de uma etapa de módulo (ex.: almox Separação/Entrega),
     * amplia a notificação a todos os níveis autorizados naquela etapa.
     *
     * @param  array<string,mixed>  $pedido
     * @return list<int>
     */
    private function usuariosExtrasPorPermissaoEtapa(array $pedido): array
    {
        $meta = $pedido['meta'] ?? null;
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        if (! is_array($meta)) {
            return [];
        }
        $etapa = trim((string) ($meta['etapa'] ?? ''));
        $modulo = trim((string) ($meta['modulo_origem'] ?? $meta['origem'] ?? ''));
        if ($etapa === '') {
            return [];
        }

        if (str_contains($modulo, 'almox')
            && class_exists(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Support\PermissoesAlmoxarifado::class)) {
            return \iEducar\Packages\BuritiAlmoxarifadoEscolar\Support\PermissoesAlmoxarifado::usuariosQuePodemAssinar($etapa);
        }

        // Genérico: meta.niveis_notificacao = ["admin","institucional"]
        $niveis = $meta['niveis_notificacao'] ?? null;
        if (is_array($niveis) && $niveis !== []
            && class_exists(\iEducar\Packages\BuritiLagunaAmbiente\Support\Notificador::class)) {
            return \iEducar\Packages\BuritiLagunaAmbiente\Support\Notificador::usuariosDosNiveis($niveis);
        }

        return [];
    }

    /**
     * Agrupa pedidos LibreSign que são etapas do mesmo documento de origem.
     *
     * @param  list<array<string,mixed>>  $itens
     * @return list<array<string,mixed>>
     */
    private function consolidarPorDocumentoOrigem(array $itens): array
    {
        $porChave = [];
        foreach ($itens as $item) {
            $chave = (string) ($item['_chave_origem'] ?? ('ls:'.(int) ($item['pedido_id'] ?? 0)));
            if (! isset($porChave[$chave])) {
                $porChave[$chave] = $item;
                continue;
            }

            $atual = $porChave[$chave];
            // Base = pedido LS mais recente (PDF/etapas acumuladas); mescla progresso das demais etapas.
            $base = ((int) ($item['pedido_id'] ?? 0) > (int) ($atual['pedido_id'] ?? 0)) ? $item : $atual;
            $outro = $base === $item ? $atual : $item;

            $feitas = max((int) ($atual['assinaturas_feitas'] ?? 0), (int) ($item['assinaturas_feitas'] ?? 0));
            $total = max((int) ($atual['assinaturas_total'] ?? 0), (int) ($item['assinaturas_total'] ?? 0));
            $completo = ($item['estado'] ?? '') === 'completo' || ($atual['estado'] ?? '') === 'completo';
            if ($total > 0 && $feitas >= $total) {
                $completo = true;
            }
            $pendentes = array_values(array_unique(array_merge(
                is_array($atual['pendentes_outros'] ?? null) ? $atual['pendentes_outros'] : [],
                is_array($item['pendentes_outros'] ?? null) ? $item['pendentes_outros'] : [],
            )));
            if ($completo) {
                $pendentes = [];
            }
            $coletadas = array_merge(
                is_array($atual['assinaturas_coletadas'] ?? null) ? $atual['assinaturas_coletadas'] : [],
                is_array($item['assinaturas_coletadas'] ?? null) ? $item['assinaturas_coletadas'] : [],
            );

            $base['assinaturas_feitas'] = $feitas;
            $base['assinaturas_total'] = max($total, 1);
            $base['progresso'] = $feitas.'/'.max($total, 1);
            $base['assinatura_unica'] = max($total, 1) <= 1;
            $base['estado'] = $completo ? 'completo' : 'parcial';
            $base['estado_rotulo'] = $completo
                ? 'Pronto — todas as etapas'
                : 'Já assinei — faltam outras etapas';
            $base['pendentes_outros'] = $pendentes;
            $base['fluxo_multi_etapa'] = max($total, 1) > 1
                || ! empty($atual['fluxo_multi_etapa'])
                || ! empty($item['fluxo_multi_etapa']);
            $base['assinaturas_coletadas'] = $coletadas;
            if (($base['assinei_em_iso'] ?? null) === null && ($outro['assinei_em_iso'] ?? null) !== null) {
                $base['assinei_em'] = $outro['assinei_em'] ?? null;
                $base['assinei_em_relativo'] = $outro['assinei_em_relativo'] ?? null;
                $base['assinei_em_iso'] = $outro['assinei_em_iso'] ?? null;
            }
            $porChave[$chave] = $base;
        }

        $out = [];
        foreach ($porChave as $item) {
            unset($item['_chave_origem']);
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function chaveDocumentoOrigem(array $meta, string $titulo, int $pedidoLsId): string
    {
        $origemId = (int) ($meta['pedido_id'] ?? 0);
        $modulo = $this->chaveModuloAssinatura($meta, $titulo);
        if ($origemId > 0 && $modulo !== 'assinatura' && $modulo !== 'outros') {
            return $modulo.':'.$origemId;
        }
        if (preg_match('/Nota almoxarifado #(\d+)/ui', $titulo, $m)) {
            return 'almoxarifado:'.(int) $m[1];
        }

        return 'ls:'.$pedidoLsId;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function tituloDocumentoOrigem(string $titulo, array $meta): string
    {
        $origemId = (int) ($meta['pedido_id'] ?? 0);
        if ($origemId > 0 && (
            str_contains(mb_strtolower((string) ($meta['modulo_origem'] ?? $meta['origem'] ?? '')), 'almox')
            || preg_match('/Nota almoxarifado #\d+/ui', $titulo)
        )) {
            return 'Nota almoxarifado #'.$origemId;
        }
        // Remove sufixo de etapa no título: "Nota … #158 — Separação"
        $limpo = preg_replace('/\s*[—\-]\s*(Separação|Aceite|Entrega|Conferência)\s*$/ui', '', $titulo);

        return is_string($limpo) && trim($limpo) !== '' ? trim($limpo) : $titulo;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function chaveModuloAssinatura(array $meta, string $titulo = ''): string
    {
        $raw = mb_strtolower(trim((string) ($meta['modulo_origem'] ?? $meta['origem'] ?? '')));
        $tituloL = mb_strtolower($titulo);
        if ($raw === '' && (str_contains($tituloL, 'almox') || str_contains($tituloL, 'nota'))) {
            $raw = 'almoxarifado-escolar';
        }
        if (str_contains($raw, 'almox')) {
            return 'almoxarifado';
        }
        if (str_contains($raw, 'aliment')) {
            return 'alimentacao';
        }
        if (str_contains($raw, 'transport')) {
            return 'transporte';
        }
        if (str_contains($raw, 'bibliotec')) {
            return 'biblioteca';
        }
        if (str_contains($raw, 'solicit') || str_contains($raw, 'chamado')) {
            return 'solicitacao';
        }
        if (str_contains($raw, 'atendiment')) {
            return 'atendimento';
        }
        if (str_contains($raw, 'contrat')) {
            return 'contratacoes';
        }
        if (
            str_contains($raw, 'advanced')
            || str_contains($raw, 'report')
            || str_contains($raw, 'relator')
            || (
                $raw === ''
                && (
                    str_contains($tituloL, 'boletim')
                    || str_contains($tituloL, 'histórico')
                    || str_contains($tituloL, 'historico')
                    || str_contains($tituloL, 'diploma')
                    || str_contains($tituloL, 'ata de')
                )
            )
        ) {
            return 'relatorios';
        }
        if (str_contains($raw, 'servidor') || $raw === 'rh' || str_contains($raw, 'rh-')) {
            return 'servidores';
        }
        if (str_contains($raw, 'horario') || str_contains($raw, 'quadro')) {
            return 'horarios';
        }
        if (str_contains($raw, 'evento') || str_contains($raw, 'formacao') || str_contains($raw, 'formação')) {
            return 'eventos';
        }
        if (str_contains($raw, 'censo') || str_contains($raw, 'educacenso')) {
            return 'educacenso';
        }
        if (str_contains($raw, 'export')) {
            return 'exportacao';
        }
        if (str_contains($raw, 'busca') && str_contains($raw, 'ativa')) {
            return 'busca-ativa';
        }
        if (str_contains($raw, 'assinatura') || $raw === '') {
            return 'assinatura';
        }

        $slug = preg_replace('/[^a-z0-9\-]+/u', '-', $raw);

        return (is_string($slug) && $slug !== '') ? trim($slug, '-') : 'outros';
    }

    private function rotuloModuloAssinatura(string $chave): string
    {
        return match ($chave) {
            'almoxarifado' => 'Almoxarifado escolar',
            'alimentacao' => 'Alimentação escolar',
            'transporte' => 'Transporte escolar',
            'biblioteca' => 'Biblioteca',
            'solicitacao' => 'Chamados e solicitações',
            'atendimento' => 'Atendimento ao estudante',
            'contratacoes' => 'Contratações',
            'relatorios', 'advanced-reports' => 'Relatórios avançados',
            'servidores', 'servidor', 'rh' => 'Servidores',
            'horarios', 'horario' => 'Horários escolares',
            'eventos', 'formacao' => 'Eventos e formação',
            'educacenso', 'censo' => 'Educacenso',
            'exportacao' => 'Exportação de documentos',
            'busca-ativa' => 'Busca ativa',
            'assinatura' => 'Assinatura digital',
            'outros' => 'Outros',
            default => $this->rotuloModuloAssinaturaFallback($chave),
        };
    }

    private function rotuloModuloAssinaturaFallback(string $chave): string
    {
        if ($chave === '') {
            return 'Outros';
        }

        $texto = str_replace(['-', '_'], ' ', $chave);
        $traducoes = [
            'advanced' => 'avançados',
            'reports' => 'relatórios',
            'report' => 'relatório',
            'school' => 'escolar',
            'student' => 'estudante',
            'digital' => 'digital',
            'export' => 'exportação',
            'services' => 'serviços',
            'service' => 'serviço',
        ];
        $partes = preg_split('/\s+/u', mb_strtolower($texto)) ?: [];
        $saida = [];
        foreach ($partes as $parte) {
            $saida[] = $traducoes[$parte] ?? $parte;
        }
        $junto = trim(implode(' ', $saida));
        if ($junto === '') {
            return 'Outros';
        }

        return mb_strtoupper(mb_substr($junto, 0, 1), 'UTF-8').mb_substr($junto, 1);
    }

    private function slotPendenteDoUsuario(array $pedido, array $ids): ?array
    {
        foreach ($this->slotsDoPedido($pedido) as $slot) {
            if (! $this->slotPertenceAoUsuario($slot, $ids)) {
                continue;
            }
            if ($this->slotJaAssinado($slot)) {
                continue;
            }

            return $slot;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $pedido
     * @return list<array<string,mixed>>
     */
    private function slotsDoPedido(array $pedido): array
    {
        $meta = is_array($pedido['meta'] ?? null) ? $pedido['meta'] : [];
        $visuais = is_array($meta['assinaturas_visuais'] ?? null) ? $meta['assinaturas_visuais'] : [];
        if ($visuais !== []) {
            return array_values(array_filter($visuais, 'is_array'));
        }

        $signatarios = is_array($pedido['signatarios'] ?? null) ? $pedido['signatarios'] : [];

        return array_values(array_filter($signatarios, 'is_array'));
    }

    /**
     * @param  array<string,mixed>  $slot
     * @param  array{user_id:?int,login:?string}  $ids
     */
    private function slotPertenceAoUsuario(array $slot, array $ids): bool
    {
        $uid = (int) ($slot['user_id'] ?? 0);
        if ($ids['user_id'] !== null && $uid > 0 && $uid === (int) $ids['user_id']) {
            return true;
        }

        $loginSlot = mb_strtolower(trim((string) ($slot['login'] ?? '')));
        $loginUser = mb_strtolower(trim((string) ($ids['login'] ?? '')));
        if ($loginUser !== '' && $loginSlot !== '' && $loginSlot === $loginUser) {
            return true;
        }

        $account = mb_strtolower(trim((string) ($slot['account'] ?? '')));
        if ($loginUser !== '' && $account !== '' && $account === $loginUser) {
            return true;
        }

        return false;
    }

    /** @param  array<string,mixed>  $slot */
    private function slotJaAssinado(array $slot): bool
    {
        if (! empty($slot['signed'])) {
            return true;
        }

        $status = mb_strtolower(trim((string) ($slot['status'] ?? '')));
        if ($status === '' || str_contains($status, 'aguardando')) {
            return false;
        }
        // "Assinatura parcial" sozinho não prova que ESTE slot assinou.
        if ($status === 'assinatura parcial' || $status === 'parcial') {
            return $this->slotIndicaAssinaturaLocal($slot);
        }

        return str_contains($status, 'assinado')
            || str_contains($status, 'assinada')
            || $status === 'registrada'
            || str_contains($status, 'digitalmente');
    }

    /** Slot com data local de assinatura (não usar código — ele existe antes de assinar). */
    private function slotIndicaAssinaturaLocal(array $slot): bool
    {
        if (! empty($slot['signed'])) {
            return true;
        }

        return trim((string) ($slot['quando'] ?? '')) !== '';
    }

    /**
     * @return array{user_id:?int,login:?string}
     */
    private function identidadeUsuario(Authenticatable $user): array
    {
        $auth = $this->usuarios->usuarioAutenticado();
        $userId = $auth['id'] ?? null;
        if ($userId === null || $userId <= 0) {
            $userId = (int) ($user->getAuthIdentifier() ?? 0);
            $userId = $userId > 0 ? $userId : null;
        }
        $login = $auth['login'] ?? null;
        if ($login === null || $login === '') {
            $login = trim((string) ($user->login ?? '')) ?: null;
        }

        return ['user_id' => $userId, 'login' => $login];
    }
}
