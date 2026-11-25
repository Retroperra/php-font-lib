<?php
namespace FontLib\WOFF2;

class Converter
{
    const KNOWN_TAGS = [
        'cmap','head','hhea','hmtx','maxp','name','OS/2','post','cvt ','fpgm',
        'glyf','loca','prep','CFF ','VORG','EBDT','EBLC','gasp','hdmx','kern',
        'LTSH','PCLT','VDMX','vhea','vmtx','BASE','GDEF','GPOS','GSUB','EBSC',
        'JSTF','MATH','CBDT','CBLC','COLR','CPAL','SVG ','sbix','acnt','avar',
        'bdat','bloc','bsln','cvar','fdsc','feat','fmtx','fvar','gvar','hsty',
        'just','lcar','mort','morx','opbd','prop','trak','Zapf','Silf','Glat',
        'Gloc','Feat','Sill',
    ];

    /**
     * Konverterar WOFF2-data till SFNT-data (utan transformerade tabeller).
     */
    public static function toSfnt($data)
    {
        if (substr($data, 0, 4) !== 'wOF2') {
            throw new \RuntimeException('Inte en WOFF2-fil.');
        }
        if (!function_exists('brotli_uncompress')) {
            throw new \RuntimeException(
                'PHP-tillägget "brotli" krävs för att läsa WOFF2 (brotli_uncompress saknas).'
            );
        }

        $h = unpack(
            'Nsig/Nflavor/Nlength/nnumTables/nreserved/NtotalSfntSize/NtotalCompressedSize',
            $data
        );
        if ($h['flavor'] === 0x74746366) { // 'ttcf'
            throw new \RuntimeException('WOFF2-samlingar (ttcf) stöds inte.');
        }

        // Tabellkatalog börjar efter 48 byte header
        $pos    = 48;
        $tables = [];
        for ($i = 0; $i < $h['numTables']; $i++) {
            $flags = ord($data[$pos++]);
            $idx   = $flags & 0x3F;
            $ver   = ($flags >> 6) & 0x03;

            if ($idx === 63) {
                $tag = substr($data, $pos, 4);
                $pos += 4;
            } else {
                $tag = self::KNOWN_TAGS[$idx];
            }

            $orig = self::readBase128($data, $pos);

            // glyf/loca: version 3 = ingen transform. Övriga: version 0 = ingen transform.
            $isGlyfLoca  = ($tag === 'glyf' || $tag === 'loca');
            $transformed = $isGlyfLoca ? ($ver !== 3) : ($ver !== 0);
            $len = $transformed ? self::readBase128($data, $pos) : $orig;

            $tables[] = ['tag' => $tag, 'len' => $len, 'transformed' => $transformed];
        }

        // All tabelldata ligger i en enda Brotli-ström
        $raw = brotli_uncompress(substr($data, $pos, $h['totalCompressedSize']));
        if ($raw === false) {
            throw new \RuntimeException('Brotli-avkomprimeringen misslyckades.');
        }

        $out = [];
        $off = 0;
        foreach ($tables as $t) {
            $body = substr($raw, $off, $t['len']);
            $off += $t['len'];
            if (!$t['transformed']) {
                $out[$t['tag']] = $body;
            }
        }

        return self::buildSfnt($h['flavor'], $out);
    }

    private static function readBase128($data, &$pos)
    {
        $result = 0;
        for ($i = 0; $i < 5; $i++) {
            $b = ord($data[$pos++]);
            $result = ($result << 7) | ($b & 0x7F);
            if (($b & 0x80) === 0) {
                return $result;
            }
        }
        throw new \RuntimeException('Ogiltigt UIntBase128-värde.');
    }

    private static function buildSfnt($flavor, array $tables)
    {
        ksort($tables, SORT_STRING);
        $n = count($tables);

        $entrySelector = (int)floor(log($n, 2));
        $searchRange   = (1 << $entrySelector) * 16;
        $rangeShift    = $n * 16 - $searchRange;

        $header  = pack('Nnnnn', $flavor, $n, $searchRange, $entrySelector, $rangeShift);
        $offset  = 12 + 16 * $n;
        $records = '';
        $bodies  = '';

        foreach ($tables as $tag => $body) {
            $tag    = (string)$tag;
            $len    = strlen($body);
            $padded = $body . str_repeat("\0", (4 - $len % 4) % 4);

            $records .= $tag . pack('NNN', self::checksum($padded), $offset, $len);
            $bodies  .= $padded;
            $offset  += strlen($padded);
        }

        return $header . $records . $bodies;
    }

    private static function checksum($padded)
    {
        $sum = 0;
        if ($padded === '') {
            return 0;
        }
        foreach (unpack('N*', $padded) as $v) {
            $sum = ($sum + $v) & 0xFFFFFFFF;
        }
        return $sum;
    }
}