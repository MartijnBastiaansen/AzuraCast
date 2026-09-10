<?php

declare(strict_types=1);

namespace Functional;

use FunctionalTester;

class Api_Stations_FilesCest extends CestAbstract
{
    /**
     * @before setupComplete
     * @before login
     */
    public function editOnlyRewritesTheFileWhenFileBackedFieldsChange(FunctionalTester $I): void
    {
        $I->wantTo('Only rewrite the media file when a save changes something stored in the file.');

        $station = $this->getTestStation();
        $media = $this->uploadTestSong();

        $localPath = $station->media_storage_location->path . '/' . $media->path;
        $editUrl = '/api/station/' . $station->id . '/file/' . $media->id;

        $hashBefore = md5_file($localPath);
        $mtimeBefore = $media->mtime;

        $I->sendPut($editUrl, ['playlists' => []]);
        $I->seeResponseCodeIsSuccessful();

        $media = $this->em->refetch($media);

        $I->assertSame($hashBefore, md5_file($localPath));
        $I->assertSame($mtimeBefore, $media->mtime);

        $I->sendPut($editUrl, ['title' => 'Renamed Title']);
        $I->seeResponseCodeIsSuccessful();

        $media = $this->em->refetch($media);

        $I->assertNotSame($hashBefore, md5_file($localPath));
        $I->assertGreaterThan($mtimeBefore, $media->mtime);
    }
}
