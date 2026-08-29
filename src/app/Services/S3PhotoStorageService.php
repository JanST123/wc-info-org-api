<?php

namespace App\Services;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use RuntimeException;

class S3PhotoStorageService
{
    private S3Client $client;

    public function __construct()
    {
        $config = config('wcinfo.s3');

        $this->client = new S3Client([
            'region' => $config['region'],
            'endpoint' => $config['endpoint'],
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ],
            'use_path_style_endpoint' => $config['use_path_style_endpoint'],
        ]);
    }

    public function exists(int $toiletId, string $filename): bool
    {
        try {
            $this->client->headObject([
                'Bucket' => $this->bucket(),
                'Key' => $this->key($toiletId, $filename),
            ]);

            return true;
        } catch (S3Exception $e) {
            return false;
        }
    }

    public function url(int $toiletId, string $filename): ?string
    {
        if (! $this->exists($toiletId, $filename)) {
            return null;
        }

        return config('wcinfo.s3.public_url') . $this->key($toiletId, $filename);
    }

    public function put(int $toiletId, string $filename, string $content, string $contentType = 'image/jpeg'): void
    {
        $this->client->putObject([
            'Bucket' => $this->bucket(),
            'Key' => $this->key($toiletId, $filename),
            'Body' => $content,
            'ContentDisposition' => 'inline',
            'CacheControl' => 'public',
            'ContentType' => $contentType,
        ]);
    }

    public function delete(int $toiletId, string $filename): bool
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket(),
                'Key' => $this->key($toiletId, $filename),
            ]);

            return true;
        } catch (S3Exception $e) {
            return false;
        }
    }

    public function copy(int $toiletId, string $sourceFilename, string $targetFilename): bool
    {
        try {
            $this->client->copyObject([
                'Bucket' => $this->bucket(),
                'Key' => $this->key($toiletId, $targetFilename),
                'CopySource' => $this->bucket() . '/' . $this->key($toiletId, $sourceFilename),
            ]);

            return true;
        } catch (S3Exception $e) {
            return false;
        }
    }

    public function rename(int $toiletId, string $oldFilename, string $newFilename): bool
    {
        if ($this->copy($toiletId, $oldFilename, $newFilename)) {
            $this->delete($toiletId, $oldFilename);
            return true;
        }

        return false;
    }

    public static function pathForId(int $toiletId): string
    {
        return implode('/', str_split((string) $toiletId));
    }

    private function key(int $toiletId, string $filename): string
    {
        return self::pathForId($toiletId) . '/' . $filename;
    }

    private function bucket(): string
    {
        return config('wcinfo.s3.bucket');
    }
}
