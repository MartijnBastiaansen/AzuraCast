<?php

declare(strict_types=1);

namespace Unit;

use App\Event\Media\WriteMetadata;
use App\Media\Enums\MetadataTags;
use App\Media\Metadata;
use App\Media\Metadata\Writer;
use Codeception\Test\Unit;
use JamesHeinrich\GetID3\GetID3;
use JamesHeinrich\GetID3\WriteTags;
use RuntimeException;

final class MediaMetadataWriterTest extends Unit
{
    /**
     * The fields the media editor owns, as StationMedia::toMetadata() passes them: always present,
     * null when the record has no value for them.
     */
    private const array NO_MANAGED_TAGS = [
        MetadataTags::Title->value => null,
        MetadataTags::Artist->value => null,
        MetadataTags::Album->value => null,
        MetadataTags::Genre->value => null,
        MetadataTags::UnsynchronisedLyric->value => null,
        MetadataTags::Isrc->value => null,
    ];

    private const array NO_EXTRA_TAGS = [
        'amplify' => null,
        'cross_start_next' => null,
        'cue_in' => null,
        'cue_out' => null,
        'fade_in' => null,
        'fade_out' => null,
    ];

    private Writer $writer;

    private string $path;

    protected function _before(): void
    {
        $this->writer = new Writer();

        $this->path = tempnam(sys_get_temp_dir(), 'azuracast_media_') . '.mp3';
        file_put_contents($this->path, self::buildSilentMp3());
    }

    protected function _after(): void
    {
        @unlink($this->path);
    }

    public function testExtraTagsDoNotPreventKnownTagsFromBeingWritten(): void
    {
        $this->write(
            [
                MetadataTags::Title->value => 'Test Title',
                MetadataTags::Artist->value => 'Test Artist',
            ],
            [
                'cue_in' => 1.5,
                'fade_out' => 2.5,
            ]
        );

        $tags = $this->readTags();

        self::assertSame(['Test Title'], $tags['title'] ?? null);
        self::assertSame(['Test Artist'], $tags['artist'] ?? null);
    }

    public function testExtraTagsAreWrittenAsIndividualTxxxFramesWhenFilled(): void
    {
        $this->write(
            [MetadataTags::Title->value => 'Test Title'],
            [
                'cue_in' => 1.5,
                'fade_out' => 2.5,
            ]
        );

        self::assertSame(
            [
                'cue_in' => '1.5',
                'fade_out' => '2.5',
            ],
            $this->readTags()['text'] ?? null
        );
    }

    public function testEmptyExtraTagsWriteNoTxxxFrames(): void
    {
        $this->write([MetadataTags::Title->value => 'Test Title'], []);

        $tags = $this->readTags();

        self::assertSame(['Test Title'], $tags['title'] ?? null);
        self::assertArrayNotHasKey('text', $tags);
    }

    /**
     * Everything AzuraCast is not given stays in the file, whether or not it means anything to it.
     */
    public function testTagsAzuraCastDoesNotManageSurviveASave(): void
    {
        $this->seedTags([
            'TITLE' => ['Original Title'],
            'YEAR' => ['1998'],
            'PUBLISHER' => ['Some Label'],
            'COMPOSER' => ['A Composer'],
            'TEXT' => [
                ['description' => 'REPLAYGAIN_TRACK_GAIN', 'data' => '-7.45 dB'],
                ['description' => 'REPLAYGAIN_TRACK_PEAK', 'data' => '1.031567'],
                ['description' => 'MusicBrainz Track Id', 'data' => 'abc-123'],
            ],
        ]);

        $this->write([MetadataTags::Title->value => 'New Title'], ['cue_out' => 205.5]);

        $tags = $this->readTags();

        self::assertSame(['New Title'], $tags['title'] ?? null);
        self::assertSame(['1998'], $tags['year'] ?? null);
        self::assertSame(['Some Label'], $tags['publisher'] ?? null);
        self::assertSame(['A Composer'], $tags['composer'] ?? null);
        self::assertSame(
            [
                'REPLAYGAIN_TRACK_GAIN' => '-7.45 dB',
                'REPLAYGAIN_TRACK_PEAK' => '1.031567',
                'MusicBrainz Track Id' => 'abc-123',
                'cue_out' => '205.5',
            ],
            $tags['text'] ?? null
        );
    }

    /**
     * GetID3's condensed tag listing keys TXXX frames by description, which turns a description
     * PHP reads as an array index into one, so the frames are taken from its parsed output.
     */
    public function testTxxxFramesWithAwkwardDescriptionsSurviveASave(): void
    {
        $this->seedTags([
            'TEXT' => [
                ['description' => '123', 'data' => 'numeric description'],
                ['description' => '', 'data' => 'no description'],
            ],
        ]);

        $this->write([MetadataTags::Title->value => 'Test Title'], []);

        $frames = [];
        foreach ($this->readInfo()['id3v2']['TXXX'] ?? [] as $frame) {
            $frames[] = [$frame['description'] ?? null, $frame['data'] ?? null];
        }

        self::assertSame(
            [
                ['123', 'numeric description'],
                ['', 'no description'],
            ],
            $frames
        );
    }

    /**
     * GetID3 splits "3/12" into a track number and a total while reading, and only the first half
     * has a frame to be written back to.
     */
    public function testTrackNumberKeepsItsTotal(): void
    {
        $this->seedTags(['TRACK_NUMBER' => ['3/12']]);

        $this->write([MetadataTags::Title->value => 'Test Title'], []);

        $tags = $this->readTags();

        self::assertSame(['3'], $tags['track_number'] ?? null);
        self::assertSame(['12'], $tags['totaltracks'] ?? null);
    }

    /**
     * Writing ID3 tags to an MP3 must not touch its APEv2 block, which is where mp3gain keeps the
     * information needed to undo its gain adjustment.
     */
    public function testApeTagIsLeftAloneWhenWritingId3TagsToAnMp3(): void
    {
        $this->seedTags(
            [
                'MP3GAIN_UNDO' => ['-006,-006,N'],
                'MP3GAIN_MINMAX' => ['104,178'],
                'REPLAYGAIN_TRACK_GAIN' => ['-4.475000 dB'],
            ],
            ['ape']
        );

        $this->write([MetadataTags::Title->value => 'New Title'], ['cue_in' => 2.0]);

        $apeItems = $this->readInfo()['ape']['items'] ?? [];

        self::assertSame(['-006,-006,N'], $apeItems['mp3gain_undo']['data'] ?? null);
        self::assertSame(['104,178'], $apeItems['mp3gain_minmax']['data'] ?? null);
        self::assertSame(['-4.475000 dB'], $apeItems['replaygain_track_gain']['data'] ?? null);
        self::assertSame(['New Title'], $this->readTags()['title'] ?? null);
    }

    /**
     * The fields the editor owns are the exception to leaving tags alone: emptying one of them
     * has to remove it from the file rather than leave the old value behind.
     */
    public function testClearedManagedFieldsAreRemovedFromTheFile(): void
    {
        $this->seedTags([
            'TITLE' => ['Original Title'],
            'GENRE' => ['Rock'],
            'YEAR' => ['1998'],
            'TEXT' => [['description' => 'cue_in', 'data' => '5.0']],
        ]);

        $this->write([MetadataTags::Title->value => 'Kept Title'], []);

        $tags = $this->readTags();

        self::assertSame(['Kept Title'], $tags['title'] ?? null);
        self::assertArrayNotHasKey('genre', $tags);
        self::assertArrayNotHasKey('text', $tags);
        self::assertSame(['1998'], $tags['year'] ?? null);
    }

    public function testRepeatedSavesLeaveTheFileUnchanged(): void
    {
        $this->seedTags([
            'YEAR' => ['1998'],
            'TEXT' => [['description' => 'REPLAYGAIN_TRACK_GAIN', 'data' => '-7.45 dB']],
        ]);

        $knownTags = [MetadataTags::Title->value => 'Stable Title'];
        $extraTags = ['cue_in' => 1.0];

        $this->write($knownTags, $extraTags);

        $tags = $this->readTags();
        $size = filesize($this->path);

        $this->write($knownTags, $extraTags);
        $this->write($knownTags, $extraTags);

        clearstatcache(true, $this->path);

        self::assertSame($tags, $this->readTags());
        self::assertSame($size, filesize($this->path));
    }

    /**
     * @param array<value-of<MetadataTags>, mixed> $knownTags
     * @param array<string, mixed> $extraTags
     */
    private function write(array $knownTags, array $extraTags): void
    {
        $metadata = new Metadata();
        $metadata->setKnownTags($knownTags + self::NO_MANAGED_TAGS);
        $metadata->setExtraTags($extraTags + self::NO_EXTRA_TAGS);

        ($this->writer)(new WriteMetadata($metadata, $this->path));
    }

    /**
     * Put tags on the test file the way an external tagger would, straight through GetID3.
     *
     * @param array<string, mixed> $tagData
     * @param string[] $tagFormats
     */
    private function seedTags(array $tagData, array $tagFormats = ['id3v1', 'id3v2.3']): void
    {
        $tagWriter = new WriteTags();
        $tagWriter->filename = $this->path;
        $tagWriter->tagformats = $tagFormats;
        $tagWriter->overwrite_tags = true;
        $tagWriter->remove_other_tags = false;
        $tagWriter->tag_encoding = 'UTF8';
        $tagWriter->tag_data = $tagData;
        $tagWriter->WriteTags();

        if (!empty($tagWriter->errors)) {
            throw new RuntimeException(implode(', ', $tagWriter->errors));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readTags(): array
    {
        return $this->readInfo()['tags']['id3v2'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function readInfo(): array
    {
        $getId3 = new GetID3();
        $getId3->encoding = 'UTF-8';

        return $getId3->analyze($this->path);
    }

    /**
     * A handful of silent MPEG-1 Layer III frames, enough for GetID3 to recognise the file as
     * an MP3 and to allow ID3v2 tags to be written to it.
     */
    private static function buildSilentMp3(): string
    {
        // FF FB: sync word, MPEG-1, Layer III, no CRC.
        // 90:    128 kbit/s, 44.1 kHz, no padding.
        // 04:    stereo, original.
        $frameHeader = "\xFF\xFB\x90\x04";

        // floor(144 * 128000 / 44100) = 417 bytes per frame, header included.
        $frame = $frameHeader . str_repeat("\x00", 413);

        return str_repeat($frame, 100);
    }
}
