<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\StorageLocationAdapters;
use App\Entity\Enums\StorageLocationTypes;
use App\Entity\Repository\StationMediaRepository;
use App\Entity\StationMedia;
use App\Entity\StorageLocation;
use App\Media\MetadataManager;
use App\Service\PlaylistConfiguration\DummyMediaGenerator;
use App\Service\PlaylistConfiguration\Schema\MediaEntry;
use App\Tests\Module;
use App\Utilities\Types;
use Codeception\Test\Unit;
use Doctrine\ORM\EntityManagerInterface;
use JamesHeinrich\GetID3\GetID3;
use Symfony\Component\Filesystem\Filesystem;

final class MediaMetadataWriterTest extends Unit
{
    private const string TEST_ARTIST = 'Test Artist';
    private const string TEST_TITLE = 'Test Title';
    private const string TEST_ALBUM = 'Test Album';

    private EntityManagerInterface $em;
    private DummyMediaGenerator $dummyMediaGenerator;
    private StationMediaRepository $mediaRepo;
    private MetadataManager $metadataManager;

    private string $storagePath;

    private StorageLocation $storageLocation;

    /** @var StationMedia[] */
    private array $generatedMedia = [];

    protected function _inject(Module $testsModule): void
    {
        $this->em = $testsModule->em;

        $di = $testsModule->container;
        $this->dummyMediaGenerator = $di->get(DummyMediaGenerator::class);
        $this->mediaRepo = $di->get(StationMediaRepository::class);
        $this->metadataManager = $di->get(MetadataManager::class);
    }

    protected function _before(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/azuracast_metadata_writer_' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->storagePath);

        $this->storageLocation = new StorageLocation(
            StorageLocationTypes::StationMedia,
            StorageLocationAdapters::Local
        );
        $this->storageLocation->path = $this->storagePath;

        $this->em->persist($this->storageLocation);
        $this->em->flush();
    }

    protected function _after(): void
    {
        foreach ($this->generatedMedia as $media) {
            $this->em->remove($media);
        }
        $this->generatedMedia = [];

        $this->em->remove($this->storageLocation);
        $this->em->flush();
        $this->em->clear();

        (new Filesystem())->remove($this->storagePath);
    }

    public function testKnownTagsAreWrittenWhenNoExtraMetadataIsSet(): void
    {
        $media = $this->generateMedia();

        // A media row without any cue/fade values still exports every extra metadata key, just with
        // null values, so the writer must cope with an extra tag set that is never empty.
        self::assertSame(
            [
                'amplify' => null,
                'cross_start_next' => null,
                'cue_in' => null,
                'cue_out' => null,
                'fade_in' => null,
                'fade_out' => null,
            ],
            $media->toMetadata()->getExtraTags()
        );

        $this->mediaRepo->writeToFile($media);

        $tags = $this->readId3v2Tags($media);

        self::assertSame([self::TEST_TITLE], $tags['title'] ?? null);
        self::assertSame([self::TEST_ARTIST], $tags['artist'] ?? null);
        self::assertSame([self::TEST_ALBUM], $tags['album'] ?? null);
        self::assertArrayNotHasKey('text', $tags);
    }

    public function testExtraMetadataIsWrittenAsTxxxFramesAndReadBack(): void
    {
        $media = $this->generateMedia();
        $media->extra_metadata = [
            'cue_in' => 1.5,
            'fade_out' => 2.5,
        ];

        $this->mediaRepo->writeToFile($media);

        $tags = $this->readId3v2Tags($media);

        self::assertSame([self::TEST_TITLE], $tags['title'] ?? null);
        self::assertSame(
            [
                'cue_in' => '1.5',
                'fade_out' => '2.5',
            ],
            $tags['text'] ?? null
        );

        $metadata = $this->metadataManager->read($this->getLocalPath($media));

        self::assertSame(self::TEST_TITLE, $metadata->getKnownTags()['title'] ?? null);
        self::assertSame('1.5', $metadata->getExtraTags()['cue_in'] ?? null);
        self::assertSame('2.5', $metadata->getExtraTags()['fade_out'] ?? null);
    }

    private function generateMedia(): StationMedia
    {
        $media = $this->dummyMediaGenerator->generate(
            $this->storageLocation,
            new MediaEntry(
                ref: 'media',
                path: 'metadata-writer-test.mp3',
                uniqueId: '',
                length: 3.0,
                artist: self::TEST_ARTIST,
                title: self::TEST_TITLE,
                album: self::TEST_ALBUM,
                genre: null
            )
        );

        self::assertInstanceOf(StationMedia::class, $media);
        $this->generatedMedia[] = $media;

        return $media;
    }

    private function getLocalPath(StationMedia $media): string
    {
        return $this->storagePath . '/' . $media->path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readId3v2Tags(StationMedia $media): array
    {
        $info = (new GetID3())->analyze($this->getLocalPath($media));

        return Types::array($info['tags']['id3v2'] ?? []);
    }
}
