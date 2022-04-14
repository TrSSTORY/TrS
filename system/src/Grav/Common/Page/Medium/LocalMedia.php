<?php

/**
 * @package    Grav\Common\Page
 *
 * @copyright  Copyright (c) 2015 - 2022 Trilby Media, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Common\Page\Medium;

use FilesystemIterator;
use Grav\Common\Data\Blueprint;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Media\Interfaces\MediaObjectInterface;
use Grav\Common\Security;
use Grav\Framework\Filesystem\Filesystem;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use function dirname;
use function is_array;

/**
 * Class AbstractMedia
 * @package Grav\Common\Page\Medium
 */
abstract class LocalMedia extends AbstractMedia
{
    /**
     * @return string
     */
    public function getType(): string
    {
        return 'local';
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'local';
    }

    /**
     * Return media path.
     *
     * @param string|null $filename
     * @return string|null
     */
    public function getPath(string $filename = null): ?string
    {
        if (!$this->path) {
            return null;
        }

        return GRAV_WEBROOT . '/' . $this->path . ($filename ? '/' . $filename : '');
    }

    /**
     * @param string $filename
     * @return string
     */
    public function getUrl(string $filename): string
    {
        return $this->getPath($filename);
    }

    /**
     * @param string|null $path
     * @return void
     */
    public function setPath(?string $path): void
    {
        // Make path relative from GRAV_WEBROOT.
        $locator = $this->getLocator();
        if ($locator->isStream($path)) {
            $path = $locator->findResource($path, false) ?: null;
        } else {
            $path = Folder::getRelativePath($path, GRAV_WEBROOT) ?: null;
        }

        $this->path = $path;
    }

    /**
     * Create Medium from a file.
     *
     * @param  string $filename
     * @param  array  $params
     * @return Medium|null
     */
    public function createFromFile(string $filename, array $params = []): ?MediaObjectInterface
    {
        $info = $this->index[$filename] ?? null;
        if (null === $info) {
            $locator = $this->getLocator();
            if ($locator->isStream($filename)) {
                $filename = (string)$locator->getResource($filename);
                if (!$filename) {
                    return null;
                }
            }

            // Find out if the file is in this media folder or fall back to MediumFactory.
            $relativePath = Folder::getRelativePath($filename, $this->getPath());
            $info = $this->index[$relativePath] ?? null;
            if (null === $info && file_exists($filename)) {
                return MediumFactory::fromFile($filename, $params);
            }

            $filename = $relativePath;
        }

        $this->addMediaDefaults($filename, $info);
        if (!is_array($info)) {
            return null;
        }

        $params += $info;

        return $this->createFromArray($params);
    }

    /**
     * Create Medium from array of parameters
     *
     * @param  array          $items
     * @param  Blueprint|null $blueprint
     * @return Medium|null
     */
    public function createFromArray(array $items = [], Blueprint $blueprint = null): ?MediaObjectInterface
    {
        return MediumFactory::fromArray($items, $blueprint);
    }

    /**
     * Create a new ImageMedium by scaling another ImageMedium object.
     *
     * @param  MediaObjectInterface $medium
     * @param  int $from
     * @param  int $to
     * @return MediaObjectInterface|null
     */
    public function scaledFromMedium(MediaObjectInterface $medium, int $from, int $to = 1): ?MediaObjectInterface
    {
        $result = MediumFactory::scaledFromMedium($medium, $from, $to);

        return is_array($result) ? $result['file'] : $result;
    }

    /**
     * @param string $filename
     * @return string
     * @throws RuntimeException
     */
    public function readFile(string $filename, array $info = null): string
    {
        error_clear_last();
        $filepath = $this->getPath($filename);
        $contents = @file_get_contents($filepath);
        if (false === $contents) {
            throw new RuntimeException('Reading media file failed: ' . (error_get_last()['message'] ?? sprintf('Cannot read %s', $filename)));
        }

        return $contents;
    }

    /**
     * @param string $filepath
     * @return resource
     * @throws RuntimeException
     */
    public function readStream(string $filename, array $info = null)
    {
        error_clear_last();
        $filepath = $this->getPath($filename);
        $contents = @fopen($filepath, 'rb');
        if (false === $contents) {
            throw new RuntimeException('Reading media file failed: ' . (error_get_last()['message'] ?? sprintf('Cannot open %s', $filename)));
        }

        return $contents;
    }

    /**
     * @param string $filename
     * @param string $destination
     * @return bool
     */
    protected function fileExists(string $filename, string $destination): bool
    {
        return is_file("{$destination}/{$filename}");
    }

    /**
     * @param string $filename
     * @return array
     */
    protected function readImageSize(string $filename, array $info = null): array
    {
        error_clear_last();
        $filepath = $this->getPath($filename);
        $sizes = @getimagesize($filepath);
        if (false === $sizes) {
            throw new RuntimeException(error_get_last()['message'] ?? 'Unable to get image size');
        }

        $sizes = [
            'width' => $sizes[0],
            'height' => $sizes[1],
            'mime' => $sizes['mime']
        ];

        // TODO: This is going to be slow without any indexing!
        /*
        // Add missing jpeg exif data.
        $exifReader = $this->getExifReader();
        if (null !== $exifReader && !isset($sizes['exif']) && $sizes['mime'] === 'image/jpeg') {
        try {
            $exif = $exifReader->read($filepath);
            $sizes['exif'] = array_diff_key($exif->getData(), array_flip($this->standard_exif));
        } catch (\RuntimeException $e) {
        }
        */

        return $sizes;
    }

    /**
     * Load file listing from the filesystem.
     *
     * @return array
     */
    protected function loadFileInfo(): array
    {
        $media = [];
        $files = new FilesystemIterator($this->path, FilesystemIterator::UNIX_PATHS | FilesystemIterator::SKIP_DOTS);
        foreach ($files as $item) {
            if (!$item->isFile()) {
                continue;
            }

            // Include extra information.
            $info = [
                'modified' => $item->getMTime(),
                'size' => $item->getSize()
            ];

            $media[$item->getFilename()] = $info;
        }

        return $media;
    }

    /**
     * @param array|null $settings
     * @return string
     */
    protected function getDestination(?array $settings = null): string
    {
        $settings = $this->getUploadSettings($settings);
        $path = $settings['destination'] ?? $this->getPath();
        if (!$path) {
            throw new RuntimeException($this->translate('GRAV.MEDIA.BAD_DESTINATION'), 400);
        }

        return $path;
    }

    /**
     * Internal logic to move uploaded file.
     *
     * @param UploadedFileInterface $uploadedFile
     * @param string $filename
     * @param string $path
     */
    protected function doMoveUploadedFile(UploadedFileInterface $uploadedFile, string $filename, string $path): void
    {
        $filepath = sprintf('%s/%s', $path, $filename);

        // Do not use streams internally.
        $locator = $this->getLocator();
        if ($locator->isStream($filepath)) {
            $filepath = (string)$locator->findResource($filepath, true, true);
        }

        Folder::create(dirname($filepath));

        $uploadedFile->moveTo($filepath);
    }

    /**
     * Internal logic to copy file.
     *
     * @param string $src
     * @param string $dst
     * @param string $path
     */
    protected function doCopy(string $src, string $dst, string $path): void
    {
        $src = sprintf('%s/%s', $path, $src);
        $dst = sprintf('%s/%s', $path, $dst);

        // Do not use streams internally.
        $locator = $this->getLocator();
        if ($locator->isStream($dst)) {
            $dst = (string)$locator->findResource($dst, true, true);
        }

        Folder::create(dirname($dst));

        copy($src, $dst);
    }

    /**
     * Internal logic to rename file.
     *
     * @param string $from
     * @param string $to
     * @param string $path
     */
    protected function doRename(string $from, string $to, string $path): void
    {
        $fromPath = $path . '/' . $from;

        $locator = $this->getLocator();
        if ($locator->isStream($fromPath)) {
            $fromPath = $locator->findResource($fromPath, true, true);
        }

        if (!is_file($fromPath)) {
            return;
        }

        $mediaPath = dirname($fromPath);
        $toPath = $mediaPath . '/' . $to;
        if ($locator->isStream($toPath)) {
            $toPath = $locator->findResource($toPath, true, true);
        }

        if (is_file($toPath)) {
            // TODO: translate error message
            throw new RuntimeException(sprintf('%s already exists (%s)', $to, $mediaPath), 500);
        }

        $result = rename($fromPath, $toPath);
        if (!$result) {
            // TODO: translate error message
            throw new RuntimeException(sprintf('%s -> %s (%s)', $from, $to, $mediaPath), 500);
        }

        // TODO: Add missing logic to handle retina files.
        if (is_file($fromPath . '.meta.yaml')) {
            $result = rename($fromPath . '.meta.yaml', $toPath . '.meta.yaml');
            if (!$result) {
                // TODO: translate error message
                throw new RuntimeException(sprintf('Meta %s -> %s (%s)', $from, $to, $mediaPath), 500);
            }
        }
    }

    /**
     * Internal logic to remove file.
     *
     * @param string $filename
     * @param string $path
     */
    protected function doRemove(string $filename, string $path): void
    {
        $filesystem = Filesystem::getInstance(false);

        $locator = $this->getLocator();
        $folder = $locator->isStream($path) ? (string)$locator->findResource($path, true, true) : $path;

        // If path doesn't exist, there's nothing to do.
        $pathname = $filesystem->pathname($filename);
        $targetPath = rtrim(sprintf('%s/%s', $folder, $pathname), '/');
        if (!is_dir($targetPath)) {
            return;
        }

        // Remove requested media file.
        if ($this->fileExists($filename, $path)) {
            $result = unlink("{$folder}/{$filename}");
            if (!$result) {
                throw new RuntimeException($this->translate('PLUGIN_ADMIN.FILE_COULD_NOT_BE_DELETED') . ': ' . $filename, 500);
            }
        }

        // Remove associated metadata.
        $this->doRemoveMetadata($filename, $path);

        // Remove associated 2x, 3x and their .meta.yaml files.
        $dir = scandir($targetPath, SCANDIR_SORT_NONE);
        if (false === $dir) {
            throw new RuntimeException($this->translate('PLUGIN_ADMIN.FILE_COULD_NOT_BE_DELETED') . ': ' . $filename, 500);
        }

        $basename = $filesystem->basename($filename);
        $fileParts = (array)$filesystem->pathinfo($filename);

        foreach ($dir as $file) {
            $preg_name = preg_quote($fileParts['filename'], '`');
            $preg_ext = preg_quote($fileParts['extension'] ?? '.', '`');
            $preg_filename = preg_quote($basename, '`');

            if (preg_match("`({$preg_name}@\d+x\.{$preg_ext}(?:\.meta\.yaml)?$|{$preg_filename}\.meta\.yaml)$`", $file)) {
                $testPath = $targetPath . '/' . $file;
                if ($locator->isStream($testPath)) {
                    $testPath = (string)$locator->findResource($testPath, true, true);
                    $locator->clearCache($testPath);
                }

                if (is_file($testPath)) {
                    $result = unlink($testPath);
                    if (!$result) {
                        throw new RuntimeException($this->translate('PLUGIN_ADMIN.FILE_COULD_NOT_BE_DELETED') . ': ' . $filename, 500);
                    }
                }
            }
        }
    }

    /**
     * @param string $filename
     * @param string $path
     */
    protected function doSanitizeSvg(string $filename, string $path): void
    {
        $filepath = sprintf('%s/%s', $path, $filename);

        // Do not use streams internally.
        $locator = $this->getLocator();
        if ($locator->isStream($filepath)) {
            $filepath = (string)$locator->findResource($filepath, true, true);
        }

        Security::sanitizeSVG($filepath);
    }
}
