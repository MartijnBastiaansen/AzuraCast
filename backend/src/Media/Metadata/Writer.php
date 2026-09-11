<?php

declare(strict_types=1);

namespace App\Media\Metadata;

use App\Event\Media\WriteMetadata;
use App\Media\MetadataInterface;
use JamesHeinrich\GetID3\GetID3;
use JamesHeinrich\GetID3\Module\Tag\ID3v2 as ID3v2Reader;
use JamesHeinrich\GetID3\Utils;
use JamesHeinrich\GetID3\Write\ID3v2 as ID3v2Writer;
use JamesHeinrich\GetID3\WriteTags;
use RuntimeException;

/**
 * @phpstan-type TxxxFrame array{name: string, encodingid: int, description: string, data: string}
 */
final class Writer
{
    /**
     * Tag containers that store every field as a plain name/value string pair. Whatever is already
     * in one of these can be handed back to GetID3 unchanged, whether or not we know what it means.
     */
    private const array FREEFORM_TAG_CONTAINERS = ['vorbiscomment', 'ape', 'real'];

    /** The only two text encodings an ID3v2.3 frame is allowed to declare. */
    private const int ID3V23_ISO_8859_1 = 0;
    private const int ID3V23_UTF_16 = 1;

    public function __invoke(WriteMetadata $event): void
    {
        $path = $event->getPath();

        $metadata = $event->getMetadata();

        $pathExt = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // The container GetID3 reports a file's existing tags under is not always named after the
        // format they are written back as, so both are needed here.
        [$tagFormats, $sourceContainer] = match ($pathExt) {
            'mp3', 'mp2', 'mp1', 'riff' => [['id3v1', 'id3v2.3'], 'id3v2'],
            'mpc' => [['ape'], 'ape'],
            'flac' => [['metaflac'], 'vorbiscomment'],
            'real' => [['real'], 'real'],
            'ogg' => [['vorbiscomment'], 'vorbiscomment'],
            default => throw new RuntimeException('Cannot write tag formats based on file type.'),
        };

        $tagwriter = new WriteTags();
        $tagwriter->filename = $path;
        $tagwriter->tag_encoding = 'UTF8';
        $tagwriter->tagformats = $tagFormats;

        // GetID3 rebuilds each tag it writes from $tag_data alone, and its own merging path throws
        // by design, so the tags already in the file are merged in below instead.
        $tagwriter->overwrite_tags = true;

        // Leave tag containers we are not writing to alone. On MP3 this is what stops every save
        // from deleting the APEv2 block holding mp3gain's undo information.
        $tagwriter->remove_other_tags = false;

        $tagwriter->tag_data = $this->buildTagData($path, $metadata, $sourceContainer);
        $tagwriter->WriteTags();

        if (!empty($tagwriter->errors) || !empty($tagwriter->warnings)) {
            $messages = array_merge($tagwriter->errors, $tagwriter->warnings);

            throw new RuntimeException(implode(', ', $messages));
        }
    }

    /**
     * Overlay the fields AzuraCast manages onto the tags the file already carries, so that tags it
     * knows nothing about - ReplayGain, MusicBrainz identifiers, release dates - survive a save.
     *
     * @return array<string, mixed>
     */
    private function buildTagData(
        string $path,
        MetadataInterface $metadata,
        string $sourceContainer
    ): array {
        $isId3v2 = 'id3v2' === $sourceContainer;

        $info = $this->analyze($path);

        $tags = $this->readExistingTags($info, $sourceContainer);

        // ID3v2 has no frame for arbitrary field names; those live in TXXX, which GetID3 both
        // reports and expects in a shape of its own, so they are merged separately.
        $txxxFrames = $isId3v2
            ? $this->readExistingTxxxFrames($info)
            : [];

        foreach ($metadata->getKnownTags() as $tagName => $tagValue) {
            $this->mergeManagedField($tags, (string)$tagName, $tagValue);
        }

        foreach ($metadata->getExtraTags() as $tagName => $tagValue) {
            if ($isId3v2) {
                $this->mergeManagedTxxxFrame($txxxFrames, (string)$tagName, $tagValue);
            } else {
                $this->mergeManagedField($tags, (string)$tagName, $tagValue);
            }
        }

        // Filtered after merging, not before: a custom field can have any metadata tag assigned
        // to it, including ones with no writable frame behind them.
        if ($isId3v2) {
            $tags = $this->filterWritableId3v2Frames($tags);
        }

        /** @var array<string, mixed> $tagData */
        $tagData = array_map(
            static fn(array $tagValues) => array_values($tagValues),
            $tags
        );

        if (!empty($txxxFrames)) {
            // "name" only exists to match frames against AzuraCast's field names above.
            $tagData['text'] = array_map(
                static fn(array $frame) => [
                    'encodingid' => $frame['encodingid'],
                    'description' => $frame['description'],
                    'data' => $frame['data'],
                ],
                $txxxFrames
            );
        }

        $artContents = $metadata->getArtwork();
        if (null !== $artContents) {
            $tagData['attached_picture'] = [
                [
                    'encodingid' => 0, // ISO-8859-1; 3=UTF8 but only allowed in ID3v2.4
                    'description' => 'cover art',
                    'data' => $artContents,
                    'picturetypeid' => 0x03,
                    'mime' => 'image/jpeg',
                ],
            ];
        }

        return $tagData;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyze(string $path): array
    {
        $getid3 = new GetID3();
        $getid3->encoding = 'UTF-8';

        $info = $getid3->analyze($path);

        // Nothing can be read back reliably from a file GetID3 could not make sense of, and the
        // write itself will fail on it anyway.
        return empty($info['error']) ? $info : [];
    }

    /**
     * @param array<string, mixed> $info
     * @return array<string, array<array-key, string>>
     */
    private function readExistingTags(array $info, string $sourceContainer): array
    {
        /** @var array<string, array<array-key, string>> $tags */
        $tags = $info['tags'][$sourceContainer] ?? [];

        // An MP3 carrying only an ID3v1 tag still has an ID3v2 tag written to it; taking the
        // existing values from ID3v1 keeps them from being dropped along the way.
        if ('id3v2' === $sourceContainer && empty($tags)) {
            /** @var array<string, array<array-key, string>> $tags */
            $tags = $info['tags']['id3v1'] ?? [];
        }

        // Artwork is passed in separately, and GetID3 does not report it in a shape it can write.
        unset($tags['picture'], $tags['attached_picture']);

        if ('id3v2' === $sourceContainer) {
            // TXXX frames are taken from GetID3's parsed frames instead, which keep more.
            unset($tags['text']);

            return $this->restoreTrackNumberTotal($tags);
        }

        if (in_array($sourceContainer, self::FREEFORM_TAG_CONTAINERS, true)) {
            // These containers keep ReplayGain in ordinary fields, but GetID3 moves it out of the
            // tag listing while reading, so it has to be put back or the rewrite drops it.
            $replayGain = $info['replay_gain'] ?? [];

            foreach (ReplayGainTags::fromGetId3(is_array($replayGain) ? $replayGain : []) as $name => $value) {
                $tags[$name] ??= [$value];
            }
        }

        return $tags;
    }

    /**
     * GetID3 splits a "3/12" track number into a number and a total while reading, and only the
     * number has a frame to be written back to. Putting the two together keeps the total.
     *
     * @param array<string, array<array-key, string>> $tags
     * @return array<string, array<array-key, string>>
     */
    private function restoreTrackNumberTotal(array $tags): array
    {
        if (isset($tags['track_number'][0], $tags['totaltracks'][0])) {
            $tags['track_number'][0] .= '/' . $tags['totaltracks'][0];
        }
        unset($tags['totaltracks']);

        return $tags;
    }

    /**
     * Keep only the ID3v2 frames GetID3 can write from a value alone: the text (T...) frames plus
     * the comment and lyrics frames. Everything else carries fields of its own - a picture, an
     * owner identifier, a rating, a URL that has to validate - which GetID3 either flattens into
     * a string while reading or cannot generate at all, and one unwritable frame fails the whole
     * tag rather than just itself.
     *
     * @param array<string, array<array-key, mixed>> $tags
     * @return array<string, array<array-key, mixed>>
     */
    private function filterWritableId3v2Frames(array $tags): array
    {
        return array_filter(
            $tags,
            static function (int|string $tagName): bool {
                $frameName = ID3v2Writer::ID3v2ShortFrameNameLookup(3, (string)$tagName);

                return in_array($frameName, ['COMM', 'USLT'], true)
                    || ('T' === ($frameName[0] ?? '') && 'TXXX' !== $frameName);
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * The TXXX frames as GetID3 parsed them. Its condensed "tags" listing keys these by frame
     * description, which loses any description PHP turns into an array index, so they are taken
     * from the parsed frames instead.
     *
     * @param array<string, mixed> $info
     * @return list<TxxxFrame>
     */
    private function readExistingTxxxFrames(array $info): array
    {
        $txxxFrames = [];

        /** @var array<array-key, array<string, mixed>> $frames */
        $frames = $info['id3v2']['TXXX'] ?? [];

        foreach ($frames as $frame) {
            if (!isset($frame['description'], $frame['data'])) {
                continue;
            }

            $txxxFrames[] = $this->toWritableTxxxFrame($frame);
        }

        return $txxxFrames;
    }

    /**
     * GetID3 decodes a frame's description in place but leaves its value as the bytes the frame
     * held, converting only on the way into its tag listing. The value is handed straight back
     * here rather than decoded and re-encoded: its decoder mishandles some values - a value of
     * exactly "0" fails its truthiness check and comes back as raw bytes - and a value we do not
     * interpret has no business being converted at all. That does mean the description has to go
     * back into the frame's own encoding, since a frame declares one encoding for both.
     *
     * @param array<string, mixed> $frame
     * @return TxxxFrame
     */
    private function toWritableTxxxFrame(array $frame): array
    {
        $encodingId = isset($frame['encodingid']) ? (int)$frame['encodingid'] : self::ID3V23_ISO_8859_1;
        $name = (string)$frame['description'];
        $data = (string)$frame['data'];

        // A frame read from an ID3v2.4 tag can be UTF-16BE or UTF-8, neither of which ID3v2.3
        // allows, so those are the one case where the value does have to be re-encoded.
        if (self::ID3V23_ISO_8859_1 !== $encodingId && self::ID3V23_UTF_16 !== $encodingId) {
            $data = $this->encodeId3v23Text(
                $data,
                self::ID3V23_UTF_16,
                ID3v2Reader::TextEncodingNameLookup($encodingId)
            );
            $encodingId = self::ID3V23_UTF_16;
        }

        return [
            'name' => $name,
            'encodingid' => $encodingId,
            'description' => $this->encodeId3v23Text($name, $encodingId),
            'data' => $data,
        ];
    }

    /**
     * ID3v2.3 stores UTF-16 with a byte order mark. GetID3's own writer forces little endian -
     * some players treat big endian as corrupt - and its reader falls back to little endian when
     * a mark is missing, so anything else round-trips byte-swapped.
     */
    private function encodeId3v23Text(
        string $text,
        int $encodingId,
        string $sourceEncoding = 'UTF-8'
    ): string {
        return self::ID3V23_UTF_16 === $encodingId
            ? "\xFF\xFE" . Utils::iconv_fallback($sourceEncoding, 'UTF-16LE', $text)
            : Utils::iconv_fallback($sourceEncoding, 'ISO-8859-1', $text);
    }

    /**
     * AzuraCast owns the fields it passes in, so an empty value means the record no longer has one
     * and the tag should go - unlike the fields it does not manage, which are left as they are.
     *
     * @param array<string, array<array-key, mixed>> $tags
     */
    private function mergeManagedField(array &$tags, string $tagName, mixed $tagValue): void
    {
        if (null === $tagValue || '' === $tagValue) {
            unset($tags[$tagName]);

            return;
        }

        $tags[$tagName] = [$tagValue];
    }

    /**
     * @param list<TxxxFrame> $txxxFrames
     */
    private function mergeManagedTxxxFrame(array &$txxxFrames, string $tagName, mixed $tagValue): void
    {
        // Descriptions are matched case-insensitively, so a field written under a different casing
        // before is replaced rather than duplicated.
        $txxxFrames = array_values(
            array_filter(
                $txxxFrames,
                static fn(array $frame) => 0 !== strcasecmp($frame['name'], $tagName)
            )
        );

        if (null !== $tagValue && '' !== $tagValue) {
            // These are AzuraCast's own fields: an ASCII name and a number, so ISO-8859-1 holds
            // them exactly and the description needs no conversion.
            $txxxFrames[] = [
                'name' => $tagName,
                'encodingid' => self::ID3V23_ISO_8859_1,
                'description' => $tagName,
                'data' => (string)$tagValue,
            ];
        }
    }
}
