<?php
declare(strict_types=1);
namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Core\ZipHelper — dependency-free ZIP archive support (zlib only, no
 * ZipArchive extension) for the File Manager: write a .zip (deflate 8)
 * and read one back (stored 0 + deflate 8). Security: extract() returns
 * raw entry names — callers must sanitize paths before saving.
 * ═══════════════════════════════════════════════════════════════════════
 */
final class ZipHelper
{
    /**
     * Build a .zip archive from entries.
     *
     * @param array<int, array{path: string, content: ?string}> $entries
     * @return string  Raw zip bytes.
     */
    public static function create(array $entries): string
    {
        $local   = '';
        $central = [];
        $offset  = 0;

        foreach ($entries as $entry) {
            $name    = ltrim((string) ($entry['path'] ?? ''), '/');
            if ($name === '') continue;
            $content = (string) ($entry['content'] ?? '');

            $crc       = crc32($content);
            $compressed = gzdeflate($content, 9) ?: $content;
            $csize     = strlen($compressed);
            $usize     = strlen($content);
            $nlen      = strlen($name);

            // Local file header (30 bytes fixed + name + data)
            $local .= pack(
                'VvvvvvVVVvv',
                0x04034b50,  // local file header signature
                20,          // version needed (2.0)
                0x0800,      // flags: UTF-8 file names
                8,           // compression method: deflate
                0,           // mod time
                0,           // mod date
                $crc,
                $csize,
                $usize,
                $nlen,
                0            // extra field length
            ) . $name . $compressed;

            // Central directory entry (46 bytes fixed + name)
            $central[] = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,  // central file header signature
                20,          // version made by
                20,          // version needed
                0x0800,      // flags
                8,           // method
                0,           // mod time
                0,           // mod date
                $crc,
                $csize,
                $usize,
                $nlen,
                0,           // extra length
                0,           // comment length
                0,           // disk number start
                0,           // internal attrs
                0,           // external attrs
                $offset      // local header offset
            ) . $name;

            $offset += 30 + $nlen + $csize;
        }

        $cd     = implode('', $central);
        $cdSize = strlen($cd);

        // End of central directory record (22 bytes)
        $eocd = pack(
            'VvvvvVVv',
            0x06054b50,          // EOCD signature
            0,                   // disk number
            0,                   // disk with central dir
            count($central),     // entries on this disk
            count($central),     // total entries
            $cdSize,             // central dir size
            $offset,             // central dir offset
            0                    // comment length
        );

        return $local . $cd . $eocd;
    }

    /**
     * Parse a .zip archive into entries: only stored (0) and deflate (8)
     * entries are decompressed, directories skipped, CRC32 verified, and
     * corrupt data dropped. Entry names are returned raw and MUST be
     * sanitized by the caller before saving.
     *
     * @return array<int, array{path: string, content: string}>
     */
    public static function extract(string $data): array
    {
        $entries = [];

        // End of central directory: "PK\x05\x06" within the last 64KB.
        $eocdPos = strrpos($data, "PK\x05\x06");
        if ($eocdPos === false || $eocdPos + 22 > strlen($data)) return $entries;

        $eocd = unpack(
            'vdisk/vcdDisk/ventriesDisk/ventries/VcdSize/VcdOffset/vcommentLen',
            substr($data, $eocdPos + 4, 18)
        );
        if ($eocd === false) return $entries;

        $count = (int) $eocd['entries'];
        $pos   = (int) $eocd['cdOffset'];
        $len   = strlen($data);

        for ($i = 0; $i < $count; $i++) {
            // Guard: central directory entry must fit in the buffer.
            if ($pos + 46 > $len || substr($data, $pos, 4) !== "PK\x01\x02") break;

            $hdr = unpack(
                'vverMade/vverNeed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vinternal/Vexternal/Voffset',
                substr($data, $pos + 4, 42)
            );
            if ($hdr === false) break;

            $name = substr($data, $pos + 46, (int) $hdr['nlen']);
            $pos += 46 + (int) $hdr['nlen'] + (int) $hdr['elen'] + (int) $hdr['clen'];

            // Skip directory entries and unsupported compression methods.
            if ($name === '' || str_ends_with($name, '/')) continue;
            $method = (int) $hdr['method'];
            if ($method !== 0 && $method !== 8) continue;

            // Local file header sits at hdr['offset']; its name/extra
            // lengths locate the compressed payload start.
            $lpos = (int) $hdr['offset'];
            if ($lpos + 30 > $len || substr($data, $lpos, 4) !== "PK\x03\x04") continue;
            $local = unpack('vver/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($data, $lpos + 4, 26));
            if ($local === false) continue;

            $dataStart = $lpos + 30 + (int) $local['nlen'] + (int) $local['elen'];
            if ($dataStart + (int) $hdr['csize'] > $len) continue;

            $compressed = substr($data, $dataStart, (int) $hdr['csize']);
            $content    = $method === 0 ? $compressed : @gzinflate($compressed);
            if ($content === false) continue;

            // CRC32 integrity check.
            if ((crc32($content) & 0xFFFFFFFF) !== ((int) $hdr['crc'] & 0xFFFFFFFF)) continue;

            $entries[] = ['path' => $name, 'content' => $content];
        }

        return $entries;
    }
}
