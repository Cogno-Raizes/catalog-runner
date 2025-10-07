<?php
namespace App;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class GoogleDriveClient
{
  private Drive $drive;

  /**
   * @param string|null $credsFilePath   Path para JSON (ex: /etc/secrets/gcp-key.json)
   *                                     Se não existir/legível, tenta env var GOOGLE_CREDENTIALS_JSON (conteúdo JSON)
   */
  public function __construct(?string $credsFilePath = null)
  {
    $config = null;

    // 1) Tentar ficheiro
    if ($credsFilePath && is_readable($credsFilePath)) {
      $raw = file_get_contents($credsFilePath);
      $conf = json_decode((string)$raw, true);
      if (is_array($conf)) {
        $config = $conf;
      } else {
        throw new \RuntimeException("Credenciais Google inválidas (JSON malformado no ficheiro): $credsFilePath");
      }
    }

    // 2) Tentar env var com o JSON completo (alternativa)
    if ($config === null) {
      $jsonEnv = getenv('GOOGLE_CREDENTIALS_JSON');
      if ($jsonEnv && trim($jsonEnv) !== '') {
        $conf = json_decode($jsonEnv, true);
        if (!is_array($conf)) {
          throw new \RuntimeException("Credenciais Google inválidas (GOOGLE_CREDENTIALS_JSON não contém JSON válido).");
        }
        $config = $conf;
      }
    }

    if ($config === null) {
      throw new \RuntimeException("Credenciais Google não encontradas/legíveis. Verifica o Secret File em '$credsFilePath' ou define GOOGLE_CREDENTIALS_JSON.");
    }

    // Instanciar cliente
    $client = new Client();
    $client->setAuthConfig($config);             // aceita array de config
    $client->setScopes([Drive::DRIVE_FILE]);     // acesso de ficheiros (precisas de ter a pasta partilhada com a SA)
    $this->drive = new Drive($client);
  }

  public function uploadCsv(string $filePath, string $driveFolderId, ?string $name = null): string
  {
    if (!file_exists($filePath)) {
      throw new \RuntimeException("Ficheiro local não encontrado: $filePath");
    }
    $name = $name ?: basename($filePath);

    $file = new DriveFile([
      'name'    => $name,
      'parents' => [$driveFolderId],
    ]);

    $res = $this->drive->files->create($file, [
      'data'       => file_get_contents($filePath),
      'mimeType'   => 'text/csv',
      'uploadType' => 'multipart',
      'fields'     => 'id,name,parents'
    ]);

    return $res->id;
  }
}
