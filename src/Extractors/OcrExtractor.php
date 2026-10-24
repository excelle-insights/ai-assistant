<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Extractors;

use ExcelleInsights\AiAssistant\Contracts\TextExtractorInterface;
use ExcelleInsights\AiAssistant\Dto\ExtractedDocument;
use ExcelleInsights\AiAssistant\Dto\UploadedFile;
use ExcelleInsights\AiAssistant\Support\Config;

/**
 * OCR stub for image uploads (png/jpg/webp/tiff/bmp) and scanned PDFs.
 *
 * The package ships no OCR engine — it only wires one up when the host has it,
 * either as an installed class (spatie/pdf-to-text style, or a cloud vision
 * client the host registered directly in `documents.extractors`) or as a local
 * binary configured with `documents.ocr_binary` (e.g. "tesseract").
 *
 * With nothing configured supports() is false, so DocumentService records
 * `needs_extractor` with an actionable message instead of pretending an image
 * had no text.
 */
final class OcrExtractor implements TextExtractorInterface
{
    use MatchesType;

    private const MIMES = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'image/tiff', 'image/bmp', 'image/gif'];
    private const EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'tif', 'tiff', 'bmp'];

    public const NO_ENGINE_MESSAGE =
        'No OCR engine is configured for image uploads. Set documents.ocr_binary to a local tesseract '
        . 'binary, or register a TextExtractorInterface for image/* in documents.extractors '
        . '(a cloud-vision client works the same way).';

    public function supports(string $mimeOrExtension): bool
    {
        return $this->isImage($mimeOrExtension) && $this->engine() !== null;
    }

    public function isImage(string $mimeOrExtension): bool
    {
        return $this->matchesType(strtolower(trim(explode(';', $mimeOrExtension)[0])), self::MIMES, self::EXTENSIONS);
    }

    /** 'binary' when an OCR command exists, otherwise null. */
    public function engine(): ?string
    {
        return $this->commandParts() === null ? null : 'binary';
    }

    public function extract(UploadedFile $file, array $options = []): ExtractedDocument
    {
        $parts = $this->commandParts();
        if ($parts === null) {
            throw new \DomainException(self::NO_ENGINE_MESSAGE);
        }
        [$binary, $extraArgs, $toStdout] = $parts;

        $path = $this->tempFile($file->bytes, $file->extension() !== '' ? $file->extension() : 'png');
        if ($path === null) {
            throw new \RuntimeException('Could not stage "' . $file->name . '" for OCR.');
        }

        $outPath = null;
        try {
            if ($toStdout) {
                $command = escapeshellarg($binary) . ($extraArgs !== '' ? ' ' . $extraArgs : '')
                    . ' ' . escapeshellarg($path) . ' stdout 2>/dev/null';
                $text = $this->runCommand($command);
            } else {
                $outPath = $path . '.txt';
                $command = escapeshellarg($binary) . ($extraArgs !== '' ? ' ' . $extraArgs : '')
                    . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($outPath) . ' 2>/dev/null';
                $this->runCommand($command);
                $text = is_file($outPath) ? @file_get_contents($outPath) : null;
            }

            $text = $this->tidy(is_string($text) ? $text : '');
            if ($text === '') {
                return new ExtractedDocument(
                    text: '',
                    pageCount: 0,
                    meta: ['extractor' => 'ocr', 'filename' => $file->name, 'empty' => true],
                );
            }
            return new ExtractedDocument(
                text: $text,
                pages: [['page' => 1, 'text' => $text]],
                language: null,
                pageCount: 1,
                meta: ['extractor' => 'ocr', 'filename' => $file->name, 'mime' => $file->mime],
            );
        } finally {
            @unlink($path);
            if ($outPath !== null) {
                @unlink($outPath);
            }
        }
    }

    /**
     * Resolve `documents.ocr_binary` (+ optional `documents.ocr_args`,
     * `documents.ocr_stdout`) into [binary, args, toStdout].
     *
     * @return array{0:string,1:string,2:bool}|null
     */
    private function commandParts(): ?array
    {
        $configured = Config::string('documents.ocr_binary', '');
        if ($configured === '') {
            return null;
        }
        $binary = $this->executable($configured);
        if ($binary === null) {
            return null;
        }
        $args = Config::string('documents.ocr_args', '');
        $toStdout = Config::bool('documents.ocr_stdout', true);
        return [$binary, $args, $toStdout];
    }
}
