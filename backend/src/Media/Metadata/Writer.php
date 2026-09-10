<?php

declare(strict_types=1);

namespace App\Media\Metadata;

use App\Event\Media\WriteMetadata;
use App\Media\Enums\MetadataTags;
use App\Media\MetadataInterface;
use App\Utilities\Types;
use JamesHeinrich\GetID3\GetID3;
use JamesHeinrich\GetID3\Utils;
use JamesHeinrich\GetID3\Write\ID3v2;
use JamesHeinrich\GetID3\WriteTags;
use RuntimeException;

/**
 * Writes the managed tags into a media file and keeps the file's other tags where getID3 allows it.
 *
 * Restrictions from getID3:
 * Existing ID3v2 frames other than text, URL, TXXX, UFID and the first COMM/WXXX are dropped.
 * FLAC pictures can only be added, never replaced or removed.
 */
final class Writer
{
    private const array MANAGED_TAGS = [
        MetadataTags::Title->value,
        MetadataTags::Artist->value,
        MetadataTags::Album->value,
        MetadataTags::Genre->value,
        MetadataTags::UnsynchronisedLyric->value,
        MetadataTags::Isrc->value,
    ];

    private const array VORBIS_PICTURE_TAGS = [
        'picture',
        'coverartmime',
    ];

    private const int PICTURE_TYPE_COVER_FRONT = 3;
    private const int UFID_MAX_LENGTH = 64;
    private const float REPLAYGAIN_DEFAULT_REFERENCE_LOUDNESS = 89.0;

    public function __invoke(WriteMetadata $event): void
    {
        $path = $event->getPath();
        $metadata = $event->getMetadata();

        $pathExt = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $tagFormats = match ($pathExt) {
            'mp3', 'mp2' => ['id3v1', 'id3v2.3'],
            'flac' => ['metaflac'],
            'ogg' => ['vorbiscomment'],
            default => throw new RuntimeException('Cannot write tag formats based on file type.'),
        };

        $info = (new GetID3())->analyze($path);

        if (!empty($info['error'])) {
            throw new RuntimeException(
                'Cannot read existing tags: ' . implode(', ', Types::array($info['error']))
            );
        }

        $tagData = in_array($pathExt, ['flac', 'ogg'], true)
            ? $this->buildVorbisTagData($metadata, $info, $pathExt === 'flac')
            : $this->buildId3v2TagData($metadata, $info);

        $tagwriter = new WriteTags();
        $tagwriter->filename = $path;
        $tagwriter->tagformats = $tagFormats;
        $tagwriter->tag_data = $tagData;
        $tagwriter->overwrite_tags = true;
        $tagwriter->remove_other_tags = false;
        $tagwriter->tag_encoding = 'UTF8';
        $tagwriter->WriteTags();

        if (!empty($tagwriter->errors) || !empty($tagwriter->warnings)) {
            $messages = array_merge($tagwriter->errors, $tagwriter->warnings);

            throw new RuntimeException(implode(', ', $messages));
        }
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed>
     */
    private function buildId3v2TagData(MetadataInterface $metadata, array $info): array
    {
        $existing = self::getInfoSection($info, 'tags', 'id3v2');
        if ($existing === []) {
            // Fallback for ID3v1 files that would otherwise lose their values
            $existing = self::getInfoSection($info, 'tags', 'id3v1');
        }

        /** @var array<string, list<string>> $textFrames */
        $textFrames = [];

        // getID3 only rewrites text (T***) & URL (W***) frames from plain values
        // The managed tag, TXXX & WXXX are added separately afterwards.
        foreach ($existing as $key => $values) {
            $key = (string) $key;
            $frameName = ID3v2::ID3v2ShortFrameNameLookup(3, $key);

            if (
                in_array($key, self::MANAGED_TAGS, true)
                || in_array($frameName, ['', 'TXXX', 'WXXX'], true)
                || !in_array($frameName[0], ['T', 'W'], true)
            ) {
                continue;
            }

            $strings = array_values(array_map('strval', array_filter(Types::array($values), 'is_scalar')));
            if ($strings !== []) {
                $textFrames[$key] = $strings;
            }
        }

        $totalTracksEntries = Types::array($existing['totaltracks'] ?? []);
        $totalTracks = Types::stringOrNull($totalTracksEntries[0] ?? null, true);
        if ($totalTracks !== null && isset($textFrames['track_number'][0])) {
            $textFrames['track_number'] = [$textFrames['track_number'][0] . '/' . $totalTracks];
        }

        // getID3 writes these frames without a description, so only one of each can exist
        foreach (['comment', 'url_user'] as $key) {
            $firstValue = Types::array($existing[$key] ?? []);
            $firstValue = reset($firstValue);
            if (is_string($firstValue) && $firstValue !== '') {
                $textFrames[$key] = [$firstValue];
            }
        }

        foreach ($metadata->getKnownTags() as $key => $value) {
            $textFrames[$key] = [(string) $value];
        }

        $tagData = $textFrames;

        $txxxRows = $this->buildTxxxRows($metadata, $info);
        if ($txxxRows !== []) {
            $tagData['text'] = $txxxRows;
        }

        $ufid = Types::array(self::getInfoSection($info, 'id3v2', 'UFID')[0] ?? []);
        if (
            isset($ufid['ownerid'], $ufid['data'])
            && is_string($ufid['data'])
            && strlen($ufid['data']) <= self::UFID_MAX_LENGTH
        ) {
            $tagData['unique_file_identifier'] = [
                'ownerid' => $ufid['ownerid'],
                'data' => $ufid['data'],
            ];
        }

        $artwork = $metadata->getArtwork();
        if ($artwork !== null) {
            $tagData['attached_picture'] = [self::buildAttachedPicture($artwork)];
        }

        return $tagData;
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return list<array{
     *  encodingid: int,
     *  description: string,
     *  data: string
     * }>
     */
    private function buildTxxxRows(MetadataInterface $metadata, array $info): array
    {
        $managedDescriptions = array_keys($metadata->getExtraTags());

        /** @var array<string, string> $values */
        $values = [];

        foreach (self::getInfoSection($info, 'id3v2', 'TXXX') as $frame) {
            $frame = Types::array($frame);
            $description = Types::string($frame['description'] ?? null);

            if (
                isset($values[$description])
                || in_array(strtolower($description), $managedDescriptions, true)
            ) {
                continue;
            }

            $values[$description] = Utils::iconv_fallback(
                Types::string($frame['encoding'] ?? null, 'ISO-8859-1'),
                'UTF-8',
                Types::string($frame['data'] ?? null)
            );
        }

        foreach ($this->getExtraTagValues($metadata) as $key => $value) {
            $values[$key] = $value;
        }

        $rows = [];
        foreach ($values as $description => $data) {
            $rows[] = self::encodeTxxxRow((string) $description, $data);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed>
     */
    private function buildVorbisTagData(MetadataInterface $metadata, array $info, bool $isFlac): array
    {
        $managedTags = [
            ...self::MANAGED_TAGS,
            ...array_keys($metadata->getExtraTags()),
            ...self::VORBIS_PICTURE_TAGS,
        ];

        $tagData = [];

        foreach (self::getInfoSection($info, 'tags', 'vorbiscomment') as $key => $values) {
            $key = (string) $key;
            if (in_array($key, $managedTags, true)) {
                continue;
            }

            $strings = array_values(array_filter(Types::array($values), 'is_string'));
            if ($strings !== []) {
                $tagData[$key] = $strings;
            }
        }

        // getID3 parses ReplayGain comments into numbers while reading, so they have to be rebuilt
        $replayGain = self::getInfoSection($info, 'replay_gain');
        $trackGain = Types::array($replayGain['track'] ?? []);
        $albumGain = Types::array($replayGain['album'] ?? []);

        // getID3 fills in the default reference loudness even when the file has no such comment.
        // Better to omit it than to force it on every file that didn't have any value set.
        $referenceLoudness = $replayGain['reference_volume'] ?? null;
        if (
            is_numeric($referenceLoudness)
            && (float) $referenceLoudness === self::REPLAYGAIN_DEFAULT_REFERENCE_LOUDNESS
        ) {
            $referenceLoudness = null;
        }

        $replayGainTags = [
            'replaygain_track_gain' => [$trackGain['adjustment'] ?? null, '%.2f dB'],
            'replaygain_track_peak' => [$trackGain['peak'] ?? null, '%.6f'],
            'replaygain_album_gain' => [$albumGain['adjustment'] ?? null, '%.2f dB'],
            'replaygain_album_peak' => [$albumGain['peak'] ?? null, '%.6f'],
            'replaygain_reference_loudness' => [$referenceLoudness, '%.1f dB'],
        ];

        foreach ($replayGainTags as $key => [$value, $format]) {
            if (is_numeric($value)) {
                $tagData[$key] = [sprintf($format, (float) $value)];
            }
        }

        foreach ($metadata->getKnownTags() as $key => $value) {
            $tagData[$key] = [(string) $value];
        }

        foreach ($this->getExtraTagValues($metadata) as $key => $value) {
            $tagData[$key] = [$value];
        }

        $artwork = $metadata->getArtwork();
        if ($artwork === null) {
            return $tagData;
        }

        if (!$isFlac) {
            $tagData['metadata_block_picture'] = [self::encodeFlacPictureBlock($artwork)];
        } elseif (empty(self::getInfoSection($info, 'flac')['PICTURE'])) {
            // getID3 can only add FLAC pictures since its metaflac call doesn't remove PICTURE blocks
            $tagData['attached_picture'] = [self::buildAttachedPicture($artwork)];
        }

        return $tagData;
    }

    /**
     * @return array<string, string>
     */
    private function getExtraTagValues(MetadataInterface $metadata): array
    {
        $values = [];

        foreach ($metadata->getExtraTags() as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $values[$key] = (string) $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<array-key, mixed>
     */
    private static function getInfoSection(array $info, string ...$path): array
    {
        $section = $info;
        foreach ($path as $key) {
            $section = Types::array($section[$key] ?? []);
        }

        return $section;
    }

    /**
     * getID3 copies TXXX rows verbatim, we need to encode them here as ISO-8859-1 for ASCII & UTF-16 otherwise
     *
     * @return array{
     *  encodingid: int,
     *  description: string,
     *  data: string
     * }
     */
    private static function encodeTxxxRow(string $description, string $data): array
    {
        if (!preg_match('/[^\x00-\x7F]/', $description . $data)) {
            return [
                'encodingid' => 0,
                'description' => $description,
                'data' => $data,
            ];
        }

        return [
            'encodingid' => 1,
            'description' => "\xFF\xFE" . Utils::iconv_fallback('UTF-8', 'UTF-16LE', $description),
            'data' => "\xFF\xFE" . Utils::iconv_fallback('UTF-8', 'UTF-16LE', $data),
        ];
    }

    /**
     * @return array{
     *  encodingid: int,
     *  description: string,
     *  data: string,
     *  picturetypeid: int,
     *  mime: string
     * }
     */
    private static function buildAttachedPicture(string $artwork): array
    {
        return [
            'encodingid' => 0, // ISO-8859-1; 3=UTF8 but only allowed in ID3v2.4
            'description' => 'cover art',
            'data' => $artwork,
            'picturetypeid' => self::PICTURE_TYPE_COVER_FRONT,
            'mime' => 'image/jpeg',
        ];
    }

    /**
     * Encodes a picture as the base64 FLAC picture block used by the METADATA_BLOCK_PICTURE comment.
     * getID3 has a reader for it but no writer so we need to do this manually.
     */
    private static function encodeFlacPictureBlock(string $artwork): string
    {
        $mime = 'image/jpeg';
        $description = 'cover art';
        [$width, $height] = getimagesizefromstring($artwork) ?: [0, 0];

        return base64_encode(
            pack('N', self::PICTURE_TYPE_COVER_FRONT)
            . pack('N', strlen($mime)) . $mime
            . pack('N', strlen($description)) . $description
            . pack('N4', $width, $height, 0, 0)
            . pack('N', strlen($artwork)) . $artwork
        );
    }
}
