# ROADMAP — Assinatura digital (LibreSign)

> Pacote: `buriti/i-educar-assinatura-digital-package` · Prioridade **P0** (transversal)  
> TR âncora: **5.3.87** · apoios: 5.3.85/86, 5.12.13 · Android: notificar + assinar web

## Visão

```text
Documento / protocolo i-Educar
        ↓
 Pedido de assinatura (SME)
        ↓
 LibreSign  ←→  Signatário interno (auth)
        ↓              Signatário externo (convite)
 PDF assinado + auditoria
        ↓
 App Android (push + abrir web)
```

## Fases

### F0 · Contrato e governança

- Definir adapter `LibreSignClient` (URL base, auth, timeouts, idempotência).
- Modelo de domínio: `PedidoAssinatura`, `Signatario` (interno|externo), `EventoAssinatura`, `DocumentoVinculo`.
- Papéis: quem pode solicitar / acompanhar / cancelar (SME × escola).
- Política LGPD para externos (base legal, retenção, revogação de convite).
- Decisão: API Android neste pacote vs recursos em `apps-api` que consomem este domínio.

### F1 · Fundação SME + LibreSign

- Schema `assinatura_digital` + migrations com `PackageTenant`.
- CRUD de pedido a partir de upload PDF ou referência a anexo existente.
- Integração: criar arquivo no LibreSign, registrar signatários internos, obter URL, sincronizar status.
- Telas Blade Buriti: listar pedidos, detalhe, pendências, download assinado.
- Webhook/polling de conclusão.

### F2 · Signatários externos

- Cadastro mínimo (nome, CPF/e-mail/telefone, vínculo opcional a aluno/pessoa).
- Convite com token de uso único/expirável.
- Rota pública de aceite + redirect para fluxo LibreSign web.
- Reenvio, expiração, recusa e auditoria.
- Página de status “aguardando / assinado / expirado” sem login SME.

### F3 · APIs Android (notificar + assinar web)

- `GET /api/apps/v1/assinaturas` — pendências do usuário autenticado (Sanctum).
- `GET /api/apps/v1/assinaturas/{id}` — detalhe + `url_assinar_web` + metadados do documento.
- `POST /api/apps/v1/assinaturas/{id}/visto` — marca ciência (opcional).
- Push: tópico/device quando novo pedido ou lembrete.
- Deep link / Universal Link para abrir Custom Tab / WebView na URL LibreSign.
- OpenAPI + exemplos no handoff mobile (sem UI nativa de assinatura).

### F4 · Catálogo de artefatos i-Educar

Conectar produtores:

| Origem | Exemplos |
| --- | --- |
| Escolar (5.3) | atas, históricos, atestados, planilhas pedagógicas |
| Eventos (5.12) | certificados com autoridade |
| ACT (5.15) | termos, editais, homologação |
| Solicitação (5.6) | anexos/protocolos de chamado |
| Exportação (4.x) | PDF gerado → pedido de assinatura |
| Protocolos genéricos | qualquer anexo/protocolo SME |

Cada origem registra `tipo`, `ref` (polimórfica) e gera o pedido sem sair do módulo de origem (botão “Solicitar assinatura”).

### F5 · Validação, PoC e 30 dias

- Verificação pública do documento (quando disponível).
- Roteiro PoC: interno + externo + push Android.
- Fechar ≤ 15% gaps sem ônus; adapter estável para não quebrar apps.

## Lotes rastreáveis

### Lote R1 — Fundação LibreSign + SME

- Integração LibreSign (criar/consultar/baixar)
- Pedido com signatários internos
- UI listagem/detalhe/download
- Auditoria básica

- [ ] Implementar / evidenciar

### Lote R2 — Externos + LGPD

- Convite externo, token, expiração
- Fluxo web público
- Consentimento / recusa

- [ ] Implementar / evidenciar

### Lote R3 — Apps Android

- Endpoints Sanctum pendências + `url_assinar_web`
- Push de nova solicitação / lembrete
- OpenAPI + coleção Postman

- [ ] Implementar / evidenciar

### Lote R4 — Artefatos i-Educar

- Hooks/botões em escolar, eventos, ACT, solicitação (mínimo 2 origens na PoC)
- Tipos catalogados + filtros SME

- [ ] Implementar / evidenciar

### Gate 100%

- [ ] Todos os lotes R* atende ou gap≤30dias
- [ ] Soma atende ≥ 85% dos itens de [`REQUISITOS.md`](./REQUISITOS.md)
- [ ] Demo: interno + externo + notificação app

## Conformidade 2.12 (antes do código)

1. `composer.json` (`buriti/i-educar-assinatura-digital-package`, PHP ≥8.3, `compatible: ["2.12"]`).
2. Scaffold `ServiceProvider`, rotas, migrations, views Buriti.
3. Schema `assinatura_digital` + guard `PackageTenant`.
4. Menu/processos em migration própria.
5. Sem UI Android neste monorepo — só API + web.
6. Seguir `PADRAO-PACOTE-2.12.md`.

## Riscos

- Disponibilidade/versão da API LibreSign no ambiente municipal.
- Externos sem e-mail válido (mitigar SMS/WhatsApp depois; PoC = e-mail/link).
- Deep link Android quebrado se URL LibreSign exigir cookies de terceiros (mitigar domínio próprio de bridge i-Educar).
- Atraso dos módulos produtores de PDF — aceitar upload manual na PoC.

## Ordem sugerida no programa Laguna

Após Onda 2 (motores) e com apps-api estável (Onda 3/5): encaixar como **Onda transversal** paralela à Onda 7/9 (documentos escolares/ACT) — ver também `packages/buriti/docs/laguna/ONDAS-PENDENTES-TR.md` (sugerir passo em Onda 11 ou nova **Onda 6.x transversal**).
