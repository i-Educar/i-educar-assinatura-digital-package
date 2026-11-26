# Instalação — Assinatura digital (LibreSign)

## 1. LibreSign de desenvolvimento

```bash
cd docker/libresign
cp .env.example .env
docker compose up -d
chmod +x scripts/bootstrap.sh
./scripts/bootstrap.sh
```

URL: http://localhost:8088 · Docs: [developer manual](https://docs.libresign.coop/main/developer_manual/) · Stack NCDD: [nextcloud-docker-development](https://github.com/LibreCodeCoop/nextcloud-docker-development)

## 2. Variáveis no i-Educar

```env
ASSINATURA_DIGITAL_ATIVO=true
LIBRESIGN_BASE_URL=http://localhost:8088
LIBRESIGN_USER=ieducar
LIBRESIGN_PASSWORD=Ieducar-Dev!
LIBRESIGN_VERIFY_SSL=false
LIBRESIGN_SIGNER1=signer1
LIBRESIGN_SIGNER1_PASSWORD=Signer1-Dev!
LIBRESIGN_SIGNER2=signer2
LIBRESIGN_SIGNER2_PASSWORD=Signer2-Dev!
ASSINATURA_SOMENTE_LIBRESIGN=true
ASSINATURA_VALIDACAO_PUBLICA=true
```

Se o app roda em container Docker:

```env
LIBRESIGN_BASE_URL=http://host.docker.internal:8088
```

## 3. Migrar e testar

```bash
php artisan migrate
php artisan assinatura:teste unica
php artisan assinatura:teste multipla
```

UI: `/assinatura-digital/testes`

## Comportamento

| Item | Comportamento |
| --- | --- |
| PDF assinado | Fica **apenas** no LibreSign/Nextcloud |
| Ver / Baixar | Proxy HTTP no i-Educar (`X-Assinatura-Storage: libresign-proxy`) |
| Validação | Código registrado na Exportação unificada → `/exportacao/validar/{codigo}` |
