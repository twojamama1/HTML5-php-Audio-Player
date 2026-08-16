<?php
declare(strict_types=1);

/*
 * Music Player backend - FLAC / MP3 / WAV
 * Memory-safe: album artwork is loaded only when the browser requests it.
 */
const MUSIC_ROOT = '/var/www/html';

const AUDIO_EXTENSIONS = ['flac', 'mp3', 'wav'];

$action = $_GET['action'] ?? 'scan';

switch ($action) {
    case 'scan':
        scanLibrary();
        break;

    case 'file':
        streamAudio();
        break;

    case 'cover':
        streamCover();
        break;

    default:
        jsonResponse(['ok' => false, 'error' => 'Unknown action'], 400);
}


/* =============================================================
 * LIBRARY SCAN
 * ============================================================= */

function scanLibrary(): never
{
    $root = realpath(MUSIC_ROOT);

    if ($root === false || !is_dir($root) || !is_readable($root)) {
        jsonResponse([
            'ok' => false,
            'error' => 'Music directory does not exist or is not readable.',
            'path' => MUSIC_ROOT
        ], 500);
    }

    $tracks = [];

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());

            if (!in_array($extension, AUDIO_EXTENSIONS, true)) {
                continue;
            }

            $absolute = $file->getPathname();
            $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
            $meta = readTextMetadata($absolute, $extension);

            $tracks[] = [
                'id'       => hash('sha256', $relative),
                'filename' => $file->getFilename(),
                'relative' => $relative,
                'format'   => strtoupper($extension),
                'url'      => 'api.php?action=file&path=' . rawurlencode($relative),
                'cover'    => 'api.php?action=cover&path=' . rawurlencode($relative),
                'title'    => $meta['title'] !== ''
                    ? $meta['title']
                    : pathinfo($file->getFilename(), PATHINFO_FILENAME),
                'artist'   => $meta['artist'],
                'album'    => $meta['album'],
            ];
        }
    } catch (Throwable $e) {
        jsonResponse([
            'ok' => false,
            'error' => 'Scan failed.',
            'details' => $e->getMessage()
        ], 500);
    }

    usort(
        $tracks,
        static fn(array $a, array $b): int =>
            strnatcasecmp($a['relative'], $b['relative'])
    );

    jsonResponse([
        'ok' => true,
        'tracks' => $tracks
    ]);
}


/* =============================================================
 * TEXT METADATA DISPATCH
 * ============================================================= */

function emptyMetadata(): array
{
    return [
        'title' => '',
        'artist' => '',
        'album' => ''
    ];
}

function readTextMetadata(string $filename, string $extension): array
{
    return match ($extension) {
        'flac' => readFlacTextMetadata($filename),
        'mp3'  => readMp3TextMetadata($filename),
        'wav'  => readWavTextMetadata($filename),
        default => emptyMetadata(),
    };
}


/* =============================================================
 * FLAC METADATA
 * ============================================================= */

function readFlacTextMetadata(string $filename): array
{
    $result = emptyMetadata();
    $fp = @fopen($filename, 'rb');

    if ($fp === false) {
        return $result;
    }

    try {
        if (fread($fp, 4) !== 'fLaC') {
            return $result;
        }

        while (!feof($fp)) {
            $header = fread($fp, 4);

            if ($header === false || strlen($header) !== 4) {
                break;
            }

            $first = ord($header[0]);
            $isLast = ($first & 0x80) !== 0;
            $type = $first & 0x7F;
            $length =
                (ord($header[1]) << 16) |
                (ord($header[2]) << 8) |
                ord($header[3]);

            // VORBIS_COMMENT
            if ($type === 4 && $length > 0 && $length <= 4 * 1024 * 1024) {
                $data = readExactly($fp, $length);

                if ($data !== null) {
                    parseVorbisComments($data, $result);
                    unset($data);
                }
            } else {
                if ($length > 0) {
                    fseek($fp, $length, SEEK_CUR);
                }
            }

            if ($isLast) {
                break;
            }
        }
    } finally {
        fclose($fp);
    }

    return $result;
}

function parseVorbisComments(string $data, array &$result): void
{
    $total = strlen($data);
    $offset = 0;

    if ($total < 8) {
        return;
    }

    $vendorLength = readLe32FromString($data, $offset);

    if ($vendorLength === null || $offset + $vendorLength + 4 > $total) {
        return;
    }

    $offset += $vendorLength;
    $commentCount = readLe32FromString($data, $offset);

    if ($commentCount === null) {
        return;
    }

    $commentCount = min($commentCount, 10000);

    for ($i = 0; $i < $commentCount; $i++) {
        $commentLength = readLe32FromString($data, $offset);

        if (
            $commentLength === null ||
            $commentLength < 1 ||
            $commentLength > 1024 * 1024 ||
            $offset + $commentLength > $total
        ) {
            break;
        }

        $comment = substr($data, $offset, $commentLength);
        $offset += $commentLength;

        $eq = strpos($comment, '=');

        if ($eq === false) {
            continue;
        }

        $key = strtoupper(substr($comment, 0, $eq));
        $value = trim(substr($comment, $eq + 1));

        if ($key === 'TITLE' && $result['title'] === '') {
            $result['title'] = $value;
        } elseif (
            ($key === 'ARTIST' || $key === 'ALBUMARTIST') &&
            $result['artist'] === ''
        ) {
            $result['artist'] = $value;
        } elseif ($key === 'ALBUM' && $result['album'] === '') {
            $result['album'] = $value;
        }
    }
}


/* =============================================================
 * MP3 / ID3 METADATA
 * ============================================================= */

function readMp3TextMetadata(string $filename): array
{
    $result = emptyMetadata();
    $fp = @fopen($filename, 'rb');

    if ($fp === false) {
        return $result;
    }

    try {
        parseId3v2TextFromFile($fp, $result);

        // Fall back to ID3v1 if fields are still missing.
        if ($result['title'] === '' || $result['artist'] === '' || $result['album'] === '') {
            parseId3v1TextFromFile($fp, $result);
        }
    } finally {
        fclose($fp);
    }

    return $result;
}

function parseId3v2TextFromFile($fp, array &$result): void
{
    fseek($fp, 0, SEEK_SET);
    $header = fread($fp, 10);

    if ($header === false || strlen($header) !== 10 || substr($header, 0, 3) !== 'ID3') {
        return;
    }

    $version = ord($header[3]);

    if ($version < 2 || $version > 4) {
        return;
    }

    $flags = ord($header[5]);
    $tagSize = syncSafeToInt(substr($header, 6, 4));

    if ($tagSize <= 0 || $tagSize > 64 * 1024 * 1024) {
        return;
    }

    $tagEnd = 10 + $tagSize;

    // Extended header.
    if (($flags & 0x40) !== 0 && ($version === 3 || $version === 4)) {
        $sizeBytes = fread($fp, 4);

        if ($sizeBytes === false || strlen($sizeBytes) !== 4) {
            return;
        }

        $extendedSize = $version === 4
            ? syncSafeToInt($sizeBytes)
            : (unpack('N', $sizeBytes)[1] ?? 0);

        if ($extendedSize <= 0 || $extendedSize > $tagSize) {
            return;
        }

        // v2.3 size excludes the 4-byte size field; v2.4 includes it.
        $skip = $version === 3 ? $extendedSize : max(0, $extendedSize - 4);
        fseek($fp, $skip, SEEK_CUR);
    }

    if ($version === 2) {
        parseId3v22TextFrames($fp, $tagEnd, $result);
        return;
    }

    while (ftell($fp) + 10 <= $tagEnd) {
        $frameHeader = fread($fp, 10);

        if ($frameHeader === false || strlen($frameHeader) !== 10) {
            break;
        }

        $frameId = substr($frameHeader, 0, 4);

        if ($frameId === "\0\0\0\0" || !preg_match('/^[A-Z0-9]{4}$/', $frameId)) {
            break;
        }

        $frameSize = $version === 4
            ? syncSafeToInt(substr($frameHeader, 4, 4))
            : (unpack('N', substr($frameHeader, 4, 4))[1] ?? 0);

        if ($frameSize <= 0 || ftell($fp) + $frameSize > $tagEnd) {
            break;
        }

        if (in_array($frameId, ['TIT2', 'TPE1', 'TALB'], true) && $frameSize <= 1024 * 1024) {
            $payload = readExactly($fp, $frameSize);

            if ($payload !== null && $payload !== '') {
                $text = decodeId3Text($payload);

                if ($frameId === 'TIT2' && $result['title'] === '') {
                    $result['title'] = $text;
                } elseif ($frameId === 'TPE1' && $result['artist'] === '') {
                    $result['artist'] = $text;
                } elseif ($frameId === 'TALB' && $result['album'] === '') {
                    $result['album'] = $text;
                }
            }
        } else {
            fseek($fp, $frameSize, SEEK_CUR);
        }

        if ($result['title'] !== '' && $result['artist'] !== '' && $result['album'] !== '') {
            break;
        }
    }
}

function parseId3v22TextFrames($fp, int $tagEnd, array &$result): void
{
    while (ftell($fp) + 6 <= $tagEnd) {
        $header = fread($fp, 6);

        if ($header === false || strlen($header) !== 6) {
            break;
        }

        $id = substr($header, 0, 3);

        if ($id === "\0\0\0" || !preg_match('/^[A-Z0-9]{3}$/', $id)) {
            break;
        }

        $size =
            (ord($header[3]) << 16) |
            (ord($header[4]) << 8) |
            ord($header[5]);

        if ($size <= 0 || ftell($fp) + $size > $tagEnd) {
            break;
        }

        if (in_array($id, ['TT2', 'TP1', 'TAL'], true) && $size <= 1024 * 1024) {
            $payload = readExactly($fp, $size);
            $text = $payload !== null ? decodeId3Text($payload) : '';

            if ($id === 'TT2' && $result['title'] === '') {
                $result['title'] = $text;
            } elseif ($id === 'TP1' && $result['artist'] === '') {
                $result['artist'] = $text;
            } elseif ($id === 'TAL' && $result['album'] === '') {
                $result['album'] = $text;
            }
        } else {
            fseek($fp, $size, SEEK_CUR);
        }
    }
}

function parseId3v1TextFromFile($fp, array &$result): void
{
    if (fseek($fp, -128, SEEK_END) !== 0) {
        return;
    }

    $tag = fread($fp, 128);

    if ($tag === false || strlen($tag) !== 128 || substr($tag, 0, 3) !== 'TAG') {
        return;
    }

    if ($result['title'] === '') {
        $result['title'] = decodeLegacyText(substr($tag, 3, 30));
    }

    if ($result['artist'] === '') {
        $result['artist'] = decodeLegacyText(substr($tag, 33, 30));
    }

    if ($result['album'] === '') {
        $result['album'] = decodeLegacyText(substr($tag, 63, 30));
    }
}

function decodeId3Text(string $payload): string
{
    if ($payload === '') {
        return '';
    }

    $encoding = ord($payload[0]);
    $text = substr($payload, 1);

    $decoded = match ($encoding) {
        0 => convertToUtf8($text, 'ISO-8859-1'),
        1 => decodeUtf16Bom($text),
        2 => convertToUtf8($text, 'UTF-16BE'),
        3 => $text,
        default => $text,
    };

    return cleanMetadataText($decoded);
}

function decodeUtf16Bom(string $text): string
{
    if (str_starts_with($text, "\xFF\xFE")) {
        return convertToUtf8(substr($text, 2), 'UTF-16LE');
    }

    if (str_starts_with($text, "\xFE\xFF")) {
        return convertToUtf8(substr($text, 2), 'UTF-16BE');
    }

    return convertToUtf8($text, 'UTF-16');
}

function decodeLegacyText(string $text): string
{
    return cleanMetadataText(convertToUtf8($text, 'Windows-1252'));
}

function convertToUtf8(string $text, string $from): string
{
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($text, 'UTF-8', $from);
    }

    if (function_exists('iconv')) {
        $converted = @iconv($from, 'UTF-8//IGNORE', $text);
        return $converted === false ? $text : $converted;
    }

    return $text;
}

function cleanMetadataText(string $text): string
{
    $text = str_replace("\0", '', $text);
    return trim($text);
}


/* =============================================================
 * WAV / RIFF INFO METADATA
 * ============================================================= */

function readWavTextMetadata(string $filename): array
{
    $result = emptyMetadata();
    $fp = @fopen($filename, 'rb');

    if ($fp === false) {
        return $result;
    }

    try {
        $header = fread($fp, 12);

        if (
            $header === false ||
            strlen($header) !== 12 ||
            substr($header, 0, 4) !== 'RIFF' ||
            substr($header, 8, 4) !== 'WAVE'
        ) {
            return $result;
        }

        $fileSize = filesize($filename) ?: 0;

        while (!feof($fp) && ftell($fp) + 8 <= $fileSize) {
            $chunkHeader = fread($fp, 8);

            if ($chunkHeader === false || strlen($chunkHeader) !== 8) {
                break;
            }

            $chunkId = substr($chunkHeader, 0, 4);
            $chunkSize = unpack('V', substr($chunkHeader, 4, 4))[1] ?? 0;
            $chunkStart = ftell($fp);

            if ($chunkSize < 0 || $chunkStart + $chunkSize > $fileSize) {
                break;
            }

            if ($chunkId === 'LIST' && $chunkSize >= 4) {
                $listType = fread($fp, 4);

                if ($listType === 'INFO') {
                    parseWavInfoList($fp, $chunkStart + $chunkSize, $result);
                }
            } elseif (($chunkId === 'id3 ' || $chunkId === 'ID3 ') && $chunkSize > 0) {
                // Some WAV files carry an ID3v2 tag in an ID3 chunk.
                parseId3v2TextAtOffset($fp, $chunkStart, $chunkSize, $result);
            }

            $next = $chunkStart + $chunkSize + ($chunkSize % 2);
            fseek($fp, $next, SEEK_SET);

            if ($result['title'] !== '' && $result['artist'] !== '' && $result['album'] !== '') {
                break;
            }
        }
    } finally {
        fclose($fp);
    }

    return $result;
}

function parseWavInfoList($fp, int $end, array &$result): void
{
    while (ftell($fp) + 8 <= $end) {
        $header = fread($fp, 8);

        if ($header === false || strlen($header) !== 8) {
            break;
        }

        $id = substr($header, 0, 4);
        $size = unpack('V', substr($header, 4, 4))[1] ?? 0;
        $start = ftell($fp);

        if ($size < 0 || $start + $size > $end) {
            break;
        }

        if (in_array($id, ['INAM', 'IART', 'IPRD'], true) && $size <= 1024 * 1024) {
            $raw = readExactly($fp, $size);

            if ($raw !== null) {
                $value = decodeLegacyText(rtrim($raw, "\0 \t\r\n"));

                if ($id === 'INAM' && $result['title'] === '') {
                    $result['title'] = $value;
                } elseif ($id === 'IART' && $result['artist'] === '') {
                    $result['artist'] = $value;
                } elseif ($id === 'IPRD' && $result['album'] === '') {
                    $result['album'] = $value;
                }
            }
        }

        fseek($fp, $start + $size + ($size % 2), SEEK_SET);
    }
}

function parseId3v2TextAtOffset($fp, int $offset, int $maxLength, array &$result): void
{
    $original = ftell($fp);
    fseek($fp, $offset, SEEK_SET);

    $header = fread($fp, 10);

    if ($header === false || strlen($header) !== 10 || substr($header, 0, 3) !== 'ID3') {
        fseek($fp, $original, SEEK_SET);
        return;
    }

    $version = ord($header[3]);
    $tagSize = syncSafeToInt(substr($header, 6, 4));

    if ($version < 2 || $version > 4 || $tagSize <= 0 || $tagSize + 10 > $maxLength) {
        fseek($fp, $original, SEEK_SET);
        return;
    }

    // Build only the small tag area for the shared parser by using a temporary stream.
    $tag = $header . (readExactly($fp, $tagSize) ?? '');

    if (strlen($tag) === $tagSize + 10) {
        $tmp = fopen('php://temp', 'w+b');
        fwrite($tmp, $tag);
        rewind($tmp);
        parseId3v2TextFromFile($tmp, $result);
        fclose($tmp);
    }

    fseek($fp, $original, SEEK_SET);
}


/* =============================================================
 * COVER ART
 * ============================================================= */

function streamCover(): never
{
    $path = resolveRequestedAudio();
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($extension === 'flac') {
        if (streamFlacEmbeddedCover($path)) {
            exit;
        }
    } elseif ($extension === 'mp3') {
        if (streamMp3EmbeddedCover($path)) {
            exit;
        }
    } elseif ($extension === 'wav') {
        if (streamWavEmbeddedCover($path)) {
            exit;
        }
    }

    if (streamFolderCover($path)) {
        exit;
    }

    http_response_code(404);
    exit;
}

function streamFlacEmbeddedCover(string $path): bool
{
    $fp = @fopen($path, 'rb');

    if ($fp === false) {
        return false;
    }

    try {
        if (fread($fp, 4) !== 'fLaC') {
            return false;
        }

        $fallback = null;

        while (!feof($fp)) {
            $header = fread($fp, 4);

            if ($header === false || strlen($header) !== 4) {
                break;
            }

            $first = ord($header[0]);
            $isLast = ($first & 0x80) !== 0;
            $type = $first & 0x7F;
            $blockLength =
                (ord($header[1]) << 16) |
                (ord($header[2]) << 8) |
                ord($header[3]);

            $blockStart = ftell($fp);

            if ($type === 6) {
                $picture = inspectFlacPictureBlock($fp, $blockStart, $blockLength);

                if ($picture !== null) {
                    if ($picture['picture_type'] === 3) {
                        sendPictureFromFile(
                            $fp,
                            $picture['data_offset'],
                            $picture['data_length'],
                            $picture['mime']
                        );
                    }

                    if ($fallback === null) {
                        $fallback = $picture;
                    }
                }
            }

            fseek($fp, $blockStart + $blockLength, SEEK_SET);

            if ($isLast) {
                break;
            }
        }

        if ($fallback !== null) {
            sendPictureFromFile(
                $fp,
                $fallback['data_offset'],
                $fallback['data_length'],
                $fallback['mime']
            );
        }
    } finally {
        if (is_resource($fp)) {
            fclose($fp);
        }
    }

    return false;
}

function inspectFlacPictureBlock($fp, int $blockStart, int $blockLength): ?array
{
    if ($blockLength < 32) {
        return null;
    }

    $blockEnd = $blockStart + $blockLength;
    fseek($fp, $blockStart, SEEK_SET);

    $pictureType = readBe32FromFile($fp);
    $mimeLength = readBe32FromFile($fp);

    if (
        $pictureType === null ||
        $mimeLength === null ||
        $mimeLength < 1 ||
        $mimeLength > 255 ||
        ftell($fp) + $mimeLength > $blockEnd
    ) {
        return null;
    }

    $mime = fread($fp, $mimeLength);

    if ($mime === false || strlen($mime) !== $mimeLength) {
        return null;
    }

    $mime = strtolower(trim($mime));

    if (!isAllowedImageMime($mime)) {
        return null;
    }

    $descriptionLength = readBe32FromFile($fp);

    if (
        $descriptionLength === null ||
        $descriptionLength > 1024 * 1024 ||
        ftell($fp) + $descriptionLength > $blockEnd
    ) {
        return null;
    }

    fseek($fp, $descriptionLength, SEEK_CUR);

    // width, height, depth, indexed colors
    for ($i = 0; $i < 4; $i++) {
        if (readBe32FromFile($fp) === null) {
            return null;
        }
    }

    $imageLength = readBe32FromFile($fp);

    if ($imageLength === null || $imageLength < 1) {
        return null;
    }

    $imageOffset = ftell($fp);

    if ($imageOffset + $imageLength > $blockEnd) {
        return null;
    }

    return [
        'picture_type' => $pictureType,
        'mime' => $mime,
        'data_offset' => $imageOffset,
        'data_length' => $imageLength
    ];
}

function streamMp3EmbeddedCover(string $path): bool
{
    $fp = @fopen($path, 'rb');

    if ($fp === false) {
        return false;
    }

    try {
        return streamId3PictureFromCurrentFile($fp, 0, filesize($path) ?: PHP_INT_MAX);
    } finally {
        if (is_resource($fp)) {
            fclose($fp);
        }
    }
}

function streamWavEmbeddedCover(string $path): bool
{
    $fp = @fopen($path, 'rb');

    if ($fp === false) {
        return false;
    }

    try {
        $header = fread($fp, 12);

        if (
            $header === false ||
            strlen($header) !== 12 ||
            substr($header, 0, 4) !== 'RIFF' ||
            substr($header, 8, 4) !== 'WAVE'
        ) {
            return false;
        }

        $fileSize = filesize($path) ?: 0;

        while (!feof($fp) && ftell($fp) + 8 <= $fileSize) {
            $chunkHeader = fread($fp, 8);

            if ($chunkHeader === false || strlen($chunkHeader) !== 8) {
                break;
            }

            $chunkId = substr($chunkHeader, 0, 4);
            $chunkSize = unpack('V', substr($chunkHeader, 4, 4))[1] ?? 0;
            $chunkStart = ftell($fp);

            if ($chunkSize < 0 || $chunkStart + $chunkSize > $fileSize) {
                break;
            }

            if ($chunkId === 'id3 ' || $chunkId === 'ID3 ') {
                if (streamId3PictureFromCurrentFile($fp, $chunkStart, $chunkSize)) {
                    return true;
                }
            }

            fseek($fp, $chunkStart + $chunkSize + ($chunkSize % 2), SEEK_SET);
        }
    } finally {
        if (is_resource($fp)) {
            fclose($fp);
        }
    }

    return false;
}

function streamId3PictureFromCurrentFile($fp, int $offset, int $maxLength): bool
{
    fseek($fp, $offset, SEEK_SET);
    $header = fread($fp, 10);

    if ($header === false || strlen($header) !== 10 || substr($header, 0, 3) !== 'ID3') {
        return false;
    }

    $version = ord($header[3]);

    if ($version < 2 || $version > 4) {
        return false;
    }

    $flags = ord($header[5]);
    $tagSize = syncSafeToInt(substr($header, 6, 4));

    if ($tagSize <= 0 || $tagSize + 10 > $maxLength || $tagSize > 64 * 1024 * 1024) {
        return false;
    }

    $tagEnd = $offset + 10 + $tagSize;

    if (($flags & 0x40) !== 0 && ($version === 3 || $version === 4)) {
        $sizeBytes = fread($fp, 4);

        if ($sizeBytes === false || strlen($sizeBytes) !== 4) {
            return false;
        }

        $extendedSize = $version === 4
            ? syncSafeToInt($sizeBytes)
            : (unpack('N', $sizeBytes)[1] ?? 0);

        if ($extendedSize <= 0 || ftell($fp) + $extendedSize > $tagEnd + 4) {
            return false;
        }

        $skip = $version === 3 ? $extendedSize : max(0, $extendedSize - 4);
        fseek($fp, $skip, SEEK_CUR);
    }

    if ($version === 2) {
        while (ftell($fp) + 6 <= $tagEnd) {
            $fh = fread($fp, 6);
            if ($fh === false || strlen($fh) !== 6) break;

            $id = substr($fh, 0, 3);
            if ($id === "\0\0\0") break;

            $size =
                (ord($fh[3]) << 16) |
                (ord($fh[4]) << 8) |
                ord($fh[5]);

            $frameStart = ftell($fp);

            if ($size <= 0 || $frameStart + $size > $tagEnd) break;

            if ($id === 'PIC') {
                $encoding = fread($fp, 1);
                $format = fread($fp, 3);
                $pictureType = fread($fp, 1);

                if ($encoding === false || $format === false || $pictureType === false) {
                    return false;
                }

                $mime = match (strtoupper($format)) {
                    'JPG', 'JPEG' => 'image/jpeg',
                    'PNG' => 'image/png',
                    default => ''
                };

                if ($mime === '') return false;

                $descriptionBytes = readId3TerminatedStringLength($fp, ord($encoding), $frameStart + $size);
                if ($descriptionBytes === null) return false;

                $dataOffset = ftell($fp);
                $dataLength = ($frameStart + $size) - $dataOffset;

                if ($dataLength > 0) {
                    sendPictureFromFile($fp, $dataOffset, $dataLength, $mime);
                }
            }

            fseek($fp, $frameStart + $size, SEEK_SET);
        }

        return false;
    }

    while (ftell($fp) + 10 <= $tagEnd) {
        $fh = fread($fp, 10);

        if ($fh === false || strlen($fh) !== 10) {
            break;
        }

        $id = substr($fh, 0, 4);

        if ($id === "\0\0\0\0" || !preg_match('/^[A-Z0-9]{4}$/', $id)) {
            break;
        }

        $size = $version === 4
            ? syncSafeToInt(substr($fh, 4, 4))
            : (unpack('N', substr($fh, 4, 4))[1] ?? 0);

        $frameStart = ftell($fp);

        if ($size <= 0 || $frameStart + $size > $tagEnd) {
            break;
        }

        if ($id === 'APIC') {
            $encodingByte = fread($fp, 1);

            if ($encodingByte === false || strlen($encodingByte) !== 1) {
                return false;
            }

            $mime = readNullTerminatedAscii($fp, $frameStart + $size, 255);

            if ($mime === null) {
                return false;
            }

            $mime = strtolower($mime);

            if (!isAllowedImageMime($mime)) {
                return false;
            }

            $pictureType = fread($fp, 1);

            if ($pictureType === false || strlen($pictureType) !== 1) {
                return false;
            }

            $descriptionBytes = readId3TerminatedStringLength(
                $fp,
                ord($encodingByte),
                $frameStart + $size
            );

            if ($descriptionBytes === null) {
                return false;
            }

            $dataOffset = ftell($fp);
            $dataLength = ($frameStart + $size) - $dataOffset;

            if ($dataLength > 0) {
                sendPictureFromFile($fp, $dataOffset, $dataLength, $mime);
            }
        }

        fseek($fp, $frameStart + $size, SEEK_SET);
    }

    return false;
}

function readNullTerminatedAscii($fp, int $end, int $maxLength): ?string
{
    $result = '';

    while (ftell($fp) < $end && strlen($result) < $maxLength) {
        $char = fread($fp, 1);

        if ($char === false || $char === '') {
            return null;
        }

        if ($char === "\0") {
            return $result;
        }

        $result .= $char;
    }

    return null;
}

function readId3TerminatedStringLength($fp, int $encoding, int $end): ?int
{
    $start = ftell($fp);
    $twoByte = ($encoding === 1 || $encoding === 2);

    while (ftell($fp) < $end) {
        if ($twoByte) {
            if (ftell($fp) + 2 > $end) {
                return null;
            }

            $bytes = fread($fp, 2);

            if ($bytes === false || strlen($bytes) !== 2) {
                return null;
            }

            if ($bytes === "\0\0") {
                return ftell($fp) - $start;
            }
        } else {
            $byte = fread($fp, 1);

            if ($byte === false || $byte === '') {
                return null;
            }

            if ($byte === "\0") {
                return ftell($fp) - $start;
            }
        }
    }

    return null;
}

function streamFolderCover(string $audioPath): bool
{
    $directory = dirname($audioPath);

    $candidates = [
        'cover.jpg', 'cover.jpeg', 'cover.png', 'cover.webp',
        'folder.jpg', 'folder.jpeg', 'folder.png', 'folder.webp',
        'front.jpg', 'front.jpeg', 'front.png', 'front.webp',
        'Cover.jpg', 'Cover.jpeg', 'Cover.png', 'Cover.webp',
        'Folder.jpg', 'Folder.jpeg', 'Folder.png', 'Folder.webp'
    ];

    foreach ($candidates as $name) {
        $candidate = $directory . DIRECTORY_SEPARATOR . $name;

        if (!is_file($candidate) || !is_readable($candidate)) {
            continue;
        }

        $mime = imageMimeFromExtension(strtolower(pathinfo($candidate, PATHINFO_EXTENSION)));

        if ($mime === null) {
            continue;
        }

        $size = filesize($candidate);

        if ($size === false || $size < 1) {
            continue;
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Cache-Control: public, max-age=86400');

        $fp = fopen($candidate, 'rb');

        if ($fp === false) {
            continue;
        }

        streamBytes($fp, $size);
        fclose($fp);
        return true;
    }

    return false;
}

function sendPictureFromFile($fp, int $offset, int $length, string $mime): never
{
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $length);
    header('Cache-Control: public, max-age=86400');

    fseek($fp, $offset, SEEK_SET);
    streamBytes($fp, $length);
    exit;
}

function isAllowedImageMime(string $mime): bool
{
    return in_array($mime, [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp'
    ], true);
}

function imageMimeFromExtension(string $extension): ?string
{
    return match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => null,
    };
}


/* =============================================================
 * AUDIO STREAMING / SEEKING
 * ============================================================= */

function streamAudio(): never
{
    $path = resolveRequestedAudio();
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    $mime = match ($extension) {
        'flac' => 'audio/flac',
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        default => 'application/octet-stream',
    };

    $size = filesize($path);

    if ($size === false || $size < 1) {
        http_response_code(404);
        exit;
    }

    $start = 0;
    $end = $size - 1;

    if (
        isset($_SERVER['HTTP_RANGE']) &&
        preg_match('/bytes=(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $m)
    ) {
        if ($m[1] !== '') {
            $start = (int)$m[1];
        }

        if ($m[2] !== '') {
            $end = (int)$m[2];
        }

        if ($start < 0 || $start >= $size || $end < $start) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }

        $end = min($end, $size - 1);
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }

    $length = $end - $start + 1;

    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $length);
    header('Cache-Control: private, max-age=3600');

    $fp = @fopen($path, 'rb');

    if ($fp === false) {
        http_response_code(404);
        exit;
    }

    fseek($fp, $start, SEEK_SET);

    try {
        streamBytes($fp, $length);
    } finally {
        fclose($fp);
    }

    exit;
}


/* =============================================================
 * PATH SAFETY
 * ============================================================= */

function resolveRequestedAudio(): string
{
    $relative = $_GET['path'] ?? '';

    if (!is_string($relative)) {
        http_response_code(400);
        exit;
    }

    $relative = str_replace('\\', '/', $relative);
    $relative = ltrim($relative, '/');

    if (
        $relative === '' ||
        str_contains($relative, "\0") ||
        preg_match('~(^|/)\.\.(/|$)~', $relative)
    ) {
        http_response_code(400);
        exit;
    }

    $root = realpath(MUSIC_ROOT);

    if ($root === false) {
        http_response_code(500);
        exit;
    }

    $requested = realpath(
        $root . DIRECTORY_SEPARATOR .
        str_replace('/', DIRECTORY_SEPARATOR, $relative)
    );

    if (
        $requested === false ||
        !is_file($requested) ||
        !is_readable($requested)
    ) {
        http_response_code(404);
        exit;
    }

    $extension = strtolower(pathinfo($requested, PATHINFO_EXTENSION));

    if (!in_array($extension, AUDIO_EXTENSIONS, true)) {
        http_response_code(404);
        exit;
    }

    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (!str_starts_with($requested, $prefix)) {
        http_response_code(403);
        exit;
    }

    return $requested;
}


/* =============================================================
 * HELPERS
 * ============================================================= */

function streamBytes($fp, int $length): void
{
    $remaining = $length;
    $chunkSize = 1024 * 1024;

    while ($remaining > 0 && !feof($fp)) {
        $toRead = min($chunkSize, $remaining);
        $buffer = fread($fp, $toRead);

        if ($buffer === false || $buffer === '') {
            break;
        }

        echo $buffer;
        $remaining -= strlen($buffer);
        unset($buffer);

        if (connection_aborted()) {
            break;
        }

        flush();
    }
}

function readExactly($fp, int $length): ?string
{
    if ($length < 0) {
        return null;
    }

    if ($length === 0) {
        return '';
    }

    $data = '';
    $remaining = $length;

    while ($remaining > 0) {
        $chunk = fread($fp, min(1024 * 1024, $remaining));

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $data .= $chunk;
        $remaining -= strlen($chunk);
    }

    return $data;
}

function readLe32FromString(string $data, int &$offset): ?int
{
    if ($offset + 4 > strlen($data)) {
        return null;
    }

    $value = unpack('V', substr($data, $offset, 4))[1] ?? null;
    $offset += 4;

    return $value;
}

function readBe32FromFile($fp): ?int
{
    $bytes = fread($fp, 4);

    if ($bytes === false || strlen($bytes) !== 4) {
        return null;
    }

    return unpack('N', $bytes)[1] ?? null;
}

function syncSafeToInt(string $bytes): int
{
    if (strlen($bytes) !== 4) {
        return 0;
    }

    return
        ((ord($bytes[0]) & 0x7F) << 21) |
        ((ord($bytes[1]) & 0x7F) << 14) |
        ((ord($bytes[2]) & 0x7F) << 7) |
        (ord($bytes[3]) & 0x7F);
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}
