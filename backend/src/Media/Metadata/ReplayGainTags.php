<?php

declare(strict_types=1);

namespace App\Media\Metadata;

/**
 * GetID3 lifts ReplayGain out of the tag listing while reading a file and reports it as parsed
 * numbers under "replay_gain" instead. Anything that wants the original tags back - both the
 * reader, to store them, and the writer, to avoid dropping them on a rewrite - has to rebuild
 * them from that.
 */
final class ReplayGainTags
{
    /**
     * @param array<string, mixed> $replayGain The "replay_gain" key of a GetID3 analysis.
     * @return array<string, string>
     */
    public static function fromGetId3(array $replayGain): array
    {
        $tags = [];

        if (isset($replayGain['track']['peak'])) {
            $tags['replaygain_track_peak'] = (string)$replayGain['track']['peak'];
        }
        if (isset($replayGain['track']['originator'])) {
            $tags['replaygain_track_originator'] = (string)$replayGain['track']['originator'];
        }
        if (isset($replayGain['track']['adjustment'])) {
            $tags['replaygain_track_gain'] = $replayGain['track']['adjustment'] . ' dB';
        }
        if (isset($replayGain['album']['peak'])) {
            $tags['replaygain_album_peak'] = (string)$replayGain['album']['peak'];
        }
        if (isset($replayGain['album']['originator'])) {
            $tags['replaygain_album_originator'] = (string)$replayGain['album']['originator'];
        }
        if (isset($replayGain['album']['adjustment'])) {
            $tags['replaygain_album_gain'] = $replayGain['album']['adjustment'] . ' dB';
        }

        if (isset($replayGain['reference_volume'])) {
            // ReplayGain states its reference loudness in dB (83 or 89), not in LUFS, in every
            // container GetID3 reads it from. It matters now that these tags are written back.
            $tags['replaygain_reference_loudness'] = $replayGain['reference_volume'] . ' dB';
        }

        return $tags;
    }
}
