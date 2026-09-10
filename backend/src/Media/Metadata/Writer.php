<?php

declare(strict_types=1);

namespace App\Media\Metadata;

use App\Event\Media\WriteMetadata;
use App\Media\MetadataInterface;
use JamesHeinrich\GetID3\GetID3;
use JamesHeinrich\GetID3\Write\ID3v2 as ID3v2Writer;
use JamesHeinrich\GetID3\WriteTags;
use RuntimeException;

final class Writer
{
    /**
     * Tag containers that store every field as a plain name/value string pair. Whatever is already
     * in one of these can be handed back to GetID3 unchanged, whether or not we know what it means.
     */
    private const array FREEFORM_TAG_CONTAINERS = ['vorbiscomment', 'ape', 'real'];

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

        /** @var array<string, mixed> $tagData */
        $tagData = array_map(
            static fn(array $tagValues) => array_values($tagValues),
            $tags
        );

        if (!empty($txxxFrames)) {
            $tagData['text'] = $txxxFrames;
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
            return $this->filterMergeableId3v2Frames($tags);
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
     * Keep only the ID3v2 frames GetID3 can write from a value alone: the text (T...) frames plus
     * the comment and lyrics frames. Everything else carries fields of its own - a picture, an
     * owner identifier, a rating, a URL that has to validate - which GetID3 flattens into strings
     * while reading and so cannot put back intact.
     *
     * @param array<string, array<array-key, string>> $tags
     * @return array<string, array<array-key, string>>
     */
    private function filterMergeableId3v2Frames(array $tags): array
    {
        // GetID3 splits a "3/12" track number into a number and a total while reading; putting it
        // back together keeps the total from being lost.
        if (isset($tags['track_number'][0], $tags['totaltracks'][0])) {
            $tags['track_number'][0] .= '/' . $tags['totaltracks'][0];
        }
        unset($tags['totaltracks']);

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
     * @return list<array{description: string, data: string}>
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

            $txxxFrames[] = [
                'description' => (string)$frame['description'],
                'data' => (string)$frame['data'],
            ];
        }

        return $txxxFrames;
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
     * @param list<array{description: string, data: string}> $txxxFrames
     */
    private function mergeManagedTxxxFrame(array &$txxxFrames, string $tagName, mixed $tagValue): void
    {
        // Descriptions are matched case-insensitively, so a field written under a different casing
        // before is replaced rather than duplicated.
        $txxxFrames = array_values(
            array_filter(
                $txxxFrames,
                static fn(array $frame) => 0 !== strcasecmp($frame['description'], $tagName)
            )
        );

        if (null !== $tagValue && '' !== $tagValue) {
            $txxxFrames[] = [
                'description' => $tagName,
                'data' => (string)$tagValue,
            ];
        }
    }
}
