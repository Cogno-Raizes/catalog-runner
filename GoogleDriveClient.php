<?php
namespace App;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class GoogleDriveClient {
  private Drive $drive;

  public function __construct(string $serviceAccountJsonPath) {
    if (!is_readable($serviceAccountJsonPath)) {
      throw new \RuntimeException("Credenciais Google inválidas: $serviceAccountJsonPath");
    }
    $client = new Client();
    $client->setAuthConfig($serviceAccountJsonPath);
    $client->setScopes([Drive::DRIVE_FILE]);
    $this->drive = new Drive($client);
  }

  public function uploadCsv(string $filePath, string $folderId): string {
    $file = new DriveFile(['name' => basename($filePath), 'parents' => [$folderId]]);
    $res = $this->drive->files->create($file, [
      'data' => file_get_contents($filePath),
      'mimeType' => 'text/csv',
      'uploadType' => 'multipart',
    ]);
    return $res->id;
  }
}
