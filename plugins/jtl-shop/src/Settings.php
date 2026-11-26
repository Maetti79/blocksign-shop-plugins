<?php

declare(strict_types=1);

namespace Plugin\blocksign_esign;

use BlockSign\ESign\ProductFiles;
use BlockSign\ESign\RequiredSignatures;
use BlockSign\ESign\ShopConfiguration;
use BlockSign\ESign\SignatureSettings;
use RuntimeException;

final class Settings
{
    public function __construct(
        private readonly array $data,
        private readonly string $webRoot,
    ) {
    }

    public static function load(string $webRoot): ?self
    {
        $path = getenv('BLOCKSIGN_JTL_CONFIG');
        if (!$path) {
            return null;
        }
        $directory = ShopConfiguration::privateDirectory(dirname($path), [$webRoot, $_SERVER['DOCUMENT_ROOT'] ?? '']);
        $path = realpath($path);
        if ($directory === null || !$path || dirname($path) !== $directory || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Configure a private BlockSign JTL settings file outside the web root.');
        }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid BlockSign JTL settings.');
        }

        return new self($data, $webRoot);
    }

    public function asset(string $file): string
    {
        $directory = ShopConfiguration::privateDirectory((string) ($this->data['files'] ?? ''), [$this->webRoot, $_SERVER['DOCUMENT_ROOT'] ?? '']);
        $path = $directory === null ? false : realpath($directory . '/' . ProductFiles::validate($file));
        if (!$path || dirname($path) !== $directory || !is_readable($path) || !is_file($path)) {
            throw new RuntimeException('A private BlockSign PDF is unavailable.');
        }
        return $path;
    }

    public function archive(): ?string
    {
        $path = $this->data['archive'] ?? '';
        if ($path === '') {
            return null;
        }
        $archive = ShopConfiguration::privateDirectory($path, [$this->webRoot, $_SERVER['DOCUMENT_ROOT'] ?? '']);
        if ($archive === null || !is_writable($archive)) {
            throw new RuntimeException('Configure a writable private BlockSign archive.');
        }
        return $archive;
    }

    public function webRoot(): string
    {
        return $this->webRoot;
    }

    public function snapshot(int $product, array $categories): ?array
    {
        $rule = $this->data['default'] ?? [];
        foreach ($categories as $category) {
            $rule = array_replace($rule, $this->data['categories'][$category] ?? []);
        }
        $rule = array_replace($rule, $this->data['products'][$product] ?? []);
        if (!$rule || ($rule['enabled'] ?? true) !== true) {
            return null;
        }
        $placement = SignatureSettings::validate(array_replace(SignatureSettings::DEFAULTS, $rule['placement'] ?? []));
        $documents = RequiredSignatures::parseAdditional($rule['documents'] ?? '');
        return ProductFiles::snapshot($rule, $placement, $documents, $this->asset(...));
    }
}
