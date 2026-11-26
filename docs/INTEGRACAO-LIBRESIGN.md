# Integração LibreSign — implementação Laguna

Referências:

- [Developer manual](https://docs.libresign.coop/main/developer_manual/)
- [API guide](https://docs.libresign.coop/main/developer_manual/api/guide-api.html)
- [NCDD](https://github.com/LibreCodeCoop/nextcloud-docker-development)
- Compose local: `docker/libresign/`

## Decisão de armazenamento

O **PDF assinado permanece no LibreSign**. O i-Educar guarda só metadados (`assinatura_digital.pedido`) e faz:

- **Ver** → `GET /assinatura-digital/pedidos/{id}/ver` (inline, proxy)
- **Baixar** → `GET /assinatura-digital/pedidos/{id}/baixar` (attachment, proxy)
- **Validar** → registra no `ValidadorDocumentoBridge` (Exportação) com links de ver/baixar

Não há `Storage::put` do PDF no disco do i-Educar quando `ASSINATURA_SOMENTE_LIBRESIGN=true`.

## Adapter

```text
LibreSignClient
  requestSignature(name, pdfBinary, signers) → id, uuid, status
  listFiles / findFileByUuid
  signByUuid(signRequestUuid, method, asUser, asPassword)
  downloadFromUrl(url) → pdfBytes
  ping()
```

Base OCS: `{LIBRESIGN_BASE_URL}/ocs/v2.php/apps/libresign/api/v1`  
Header obrigatório: `OCS-APIRequest: true`  
Auth: Basic (usuário + senha ou App Password)

## Status LibreSign

| Código | Significado |
| --- | --- |
| 0 | Draft |
| 1 | Able to sign |
| 2 | Partially signed |
| 3 | Signed (terminal) |
| 4 | Deleted |

## Testes

| Cenário | Signatários | Comando / UI |
| --- | --- | --- |
| Única | `signer1` | `assinatura:teste unica` · `/testes` |
| Múltipla | `signer1` + `signer2` | `assinatura:teste multipla` · `/testes` |

PHPUnit (Http::fake): `tests/Feature/AssinaturaUnicaEMultiplaTest.php`

## Variáveis

```env
ASSINATURA_DIGITAL_ATIVO=true
LIBRESIGN_BASE_URL=http://localhost:8088
LIBRESIGN_USER=ieducar
LIBRESIGN_PASSWORD=ieducar
LIBRESIGN_VERIFY_SSL=false
ASSINATURA_SOMENTE_LIBRESIGN=true
ASSINATURA_VALIDACAO_PUBLICA=true
```
