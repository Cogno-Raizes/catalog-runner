# Catalog Runner (Natural Systems → CSV → Google Drive)

Este projeto executa o teu `fetch_naturalsystems.php` online (Render), envia os CSV gerados para uma pasta no Google Drive, e expõe um endpoint HTTP para agendamento diário via cron-job.org.

## Estrutura
- `fetch_naturalsystems.php` — o teu script original (lê `NATURALSYSTEMS_API_KEY` das variáveis de ambiente; gera CSVs em `./output/csv`).
- `public/index.php` — endpoint `/` que corre o script e faz upload dos CSV para o Google Drive.
- `src/GoogleDriveClient.php` — cliente mínimo do Google Drive.
- `composer.json` — dependências PHP (google/apiclient).
- `render.yaml` + `Dockerfile` — deploy simples na Render.

## Variáveis de Ambiente (Render)
- **RUN_SECRET** — segredo para proteger o endpoint (`?key=...`).
- **NATURALSYSTEMS_API_KEY** — a tua API key para obter o token de 24h.
- **DRIVE_FOLDER_ID** — ID da pasta no teu Google Drive onde os CSV ficam.
- **GOOGLE_APPLICATION_CREDENTIALS** — `/etc/secrets/gcp-key.json` (já definido em `render.yaml`).

## Secret File (Render)
- `gcp-key.json` — JSON da Service Account (Google Cloud) com Drive API ativada.
  - Partilha a **pasta do Drive** com o e-mail da Service Account como **Editor**.

## Endpoint de execução
Depois do deploy, chama:
```
https://<teu-servico>.onrender.com/?key=<RUN_SECRET>
```
O endpoint:
1. Executa `fetch_naturalsystems.php`.
2. Procura CSVs em `output/csv`.
3. Faz upload para o `DRIVE_FOLDER_ID`.
4. Devolve JSON com IDs dos ficheiros no Drive.

## Agendamento
Usa cron-job.org para chamar o URL acima todos os dias às 06:00 (Europe/Lisbon).
