# TODO — Assinatura digital (LibreSign)

> Pacote: `buriti/i-educar-assinatura-digital-package` · Prioridade **P0**

## Backlog produto

### Integração LibreSign

- [ ] Config (`LIBRESIGN_BASE_URL`, credenciais, timeout, verify SSL)
- [ ] Client HTTP com retries/idempotência
- [ ] Criar documento / anexar PDF
- [ ] Registrar signatários (interno e externo)
- [ ] Obter URL de assinatura web
- [ ] Consultar status / webhook de conclusão
- [ ] Baixar PDF assinado e persistir no storage i-Educar
- [ ] Tratar erros (expirado, recusado, falha ICP)

### SME (web)

- [ ] Menu/processo “Assinatura digital”
- [ ] Listar pedidos (filtros: status, escola, tipo, prazo)
- [ ] Criar pedido (upload ou anexo existente)
- [ ] Selecionar signatários internos (usuários i-Educar)
- [ ] Adicionar signatários externos (nome + CPF/e-mail/telefone)
- [ ] Ordem de assinatura (paralela / sequencial)
- [ ] Reenviar convite / cancelar / prorrogar prazo
- [ ] Detalhe com timeline de eventos
- [ ] Download do original e do assinado
- [ ] Identidade visual Buriti (topo, trilho, modal)

### Externos

- [ ] Token de convite seguro (hash, expiração, uso único opcional)
- [ ] Página pública de status + CTA “Assinar na web”
- [ ] Consentimento LGPD / ciência do documento
- [ ] Recusa com motivo
- [ ] Rate limit e anti-abuso no endpoint público

### APIs Android (notificar + assinar web)

- [ ] Auth Sanctum (perfil servidor / responsável / candidato conforme caso)
- [ ] `GET` pendências de assinatura do usuário
- [ ] `GET` detalhe + `url_assinar_web` + metadados
- [ ] `POST` marcar como visto
- [ ] Push: nova solicitação + lembrete de prazo
- [ ] Deep link documentado para o time mobile
- [ ] OpenAPI / Postman no handoff
- [ ] **Não** implementar canvas nativo de assinatura neste pacote

### Catálogo / origens i-Educar

- [ ] Tipo `ata_resultados` / `historico` / `atestado` (5.3.87)
- [ ] Tipo `certificado_evento` (5.12.13)
- [ ] Tipo `protocolo_generico` / anexo de chamado
- [ ] Tipo `documento_act` (editais/termos)
- [ ] Botão “Solicitar assinatura” em pelo menos 2 módulos na PoC
- [ ] API interna `AssinaturaDigital::solicitar(...)` para outros pacotes

### Segurança e auditoria

- [ ] Auditoria Laguna (`AuditoriaFluxo`) em criar/reenviar/cancelar/concluir
- [ ] Escopo por escola/rede conforme perfil
- [ ] Não vazar URL de assinatura de um usuário para outro
- [ ] Rotação/revogação de tokens de convite

## Scaffold 2.12 (quando iniciar código)

- [ ] `composer.json` (`buriti/i-educar-assinatura-digital-package`, PHP ≥8.3, illuminate/support, `compatible: ["2.12"]`)
- [ ] `ServiceProvider` + `const PACKAGE`
- [ ] `routes/web.php` + rotas públicas de convite
- [ ] `routes/api.php` (ou recursos no apps-api)
- [ ] Migrations schema `assinatura_digital` + `PackageTenant`
- [ ] Processos/menu
- [ ] Config `config/assinatura-digital.php`
- [ ] README + INSTALL
- [ ] Entrada em `packages/plug-and-play.json` (fora do ignore)
- [ ] Revisar `PADRAO-PACOTE-2.12.md`

## Cobertura de requisitos

- [ ] Percorrer 100% de [`REQUISITOS.md`](./REQUISITOS.md) e marcar atende/gap/N/A
- [ ] Abrir tarefas para cada gap (IDs explícitos)
- [ ] Evidências PoC (interno, externo, push)
- [ ] Lista ≤15% com datas ≤30 dias

### Lotes (mesmo do ROADMAP)

- [ ] Lote R1 — Fundação LibreSign + SME
- [ ] Lote R2 — Externos + LGPD
- [ ] Lote R3 — Apps Android
- [ ] Lote R4 — Artefatos i-Educar

## Gate PoC

- [ ] Pedido assinado por usuário interno (SME)
- [ ] Pedido assinado por externo via convite web
- [ ] Notificação no canal app + abertura da URL web
- [ ] PDF assinado recuperável + auditoria
- [ ] Matriz ≥ 85% atende ou plano 30 dias

## Fora deste pacote

- [ ] UI Android de assinatura manuscrita nativa (equipe mobile — opcional futuro)
- [ ] Publicação nas lojas
- [ ] Operação/infra da instância LibreSign (LibreCode / ops municipal)

## Referências

- `EPIC.md` · `ROADMAP.md` · `REQUISITOS.md`
- LibreSign / LibreCode: https://librecode.coop/
- `packages/buriti/PADRAO-PACOTE-2.12.md`
- `packages/buriti/i-educar-apps-api-package/` (Sanctum, push, handoff)
- TR: `packages/buriti/docs/laguna/MATRIZ-REQUISITOS-TR.md` · item **5.3.87**
