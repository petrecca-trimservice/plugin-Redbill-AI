<?php
/**
 * MSG Universal Parser
 *
 * Parser per file MSG (formato OLE - Compound Document)
 * Estrae allegati da file email in formato Outlook MSG
 *
 * @package MSG_Extractor
 * @since 8.0
 */

if (!defined('ABSPATH')) exit;

class MSG_Universal_Parser_V7 {
    private $data;
    private $sectorSize;
    private $shortSectorSize;
    private $fat = [];
    private $miniFat = [];
    private $miniStreamData = '';
    private $directory = [];
    public $attachments = [];

    public function __construct($filepath) {
        if (!file_exists($filepath)) return;
        $this->data = file_get_contents($filepath);
        $this->parse();
    }

    private function parse() {
        $len = strlen($this->data);
        if ($len < 512) return;
        if (substr($this->data, 0, 8) != pack("H*", "D0CF11E0A1B11AE1")) return;

        $u = unpack("vsec_exp/vshort_sec_exp", substr($this->data, 30, 4));
        $this->sectorSize = 1 << $u['sec_exp'];
        $this->shortSectorSize = 1 << $u['short_sec_exp'];

        $dir_start_sect = unpack("V", substr($this->data, 48, 4))[1];
        $minifat_start_sect = unpack("V", substr($this->data, 60, 4))[1];

        $difat = substr($this->data, 76, 436);
        $fat_sectors = array_values(unpack("V*", $difat));

        $raw_fat = "";
        foreach ($fat_sectors as $sec) {
            if ($sec == 0xFFFFFFFF || $sec == 0xFFFFFFFE) continue;
            $raw_fat .= $this->readSector($sec);
        }
        if (strlen($raw_fat) > 0) $this->fat = array_values(unpack("V*", $raw_fat));

        $dir_stream = $this->readChain($dir_start_sect);
        $num_entries = strlen($dir_stream) / 128;

        for ($i = 0; $i < $num_entries; $i++) {
            $this->directory[$i] = $this->parseDirEntry(substr($dir_stream, $i * 128, 128), $i);
        }

        if (isset($this->directory[0])) {
            $rootEntry = $this->directory[0];
            $this->miniStreamData = $this->readChain($rootEntry['start_sect']);
            $raw_minifat = $this->readChain($minifat_start_sect);
            if (strlen($raw_minifat) > 0) $this->miniFat = array_values(unpack("V*", $raw_minifat));
        }

        $this->findAttachments();
    }

    private function readSector($sect) {
        $offset = ($sect + 1) * $this->sectorSize;
        if ($offset + $this->sectorSize > strlen($this->data)) return "";
        return substr($this->data, $offset, $this->sectorSize);
    }

    private function readChain($start_sect) {
        if ($start_sect == 0xFFFFFFFE || $start_sect == 0xFFFFFFFF) return "";
        $stream = "";
        $curr = $start_sect;
        $loops = 0;
        while ($curr != 0xFFFFFFFE && $curr != 0xFFFFFFFF && $loops < 10000) {
            $stream .= $this->readSector($curr);
            if (!isset($this->fat[$curr])) break;
            $curr = $this->fat[$curr];
            $loops++;
        }
        return $stream;
    }

    private function readMiniChain($start_sect) {
        if (empty($this->miniStreamData)) return "";
        $stream = "";
        $curr = $start_sect;
        $loops = 0;
        $max_len = strlen($this->miniStreamData);
        while ($curr != 0xFFFFFFFE && $curr != 0xFFFFFFFF && $loops < 5000) {
            $offset = $curr * $this->shortSectorSize;
            if ($offset >= $max_len) break;
            $stream .= substr($this->miniStreamData, $offset, $this->shortSectorSize);
            if (!isset($this->miniFat[$curr])) break;
            $curr = $this->miniFat[$curr];
            $loops++;
        }
        return $stream;
    }

    private function parseDirEntry($binary, $index) {
        $nameRaw = substr($binary, 0, 64);
        $nameLen = unpack("v", substr($binary, 64, 2))[1];
        $name = str_replace("\x00", "", substr($nameRaw, 0, $nameLen));
        $type = ord($binary[66]);
        $leftChild = unpack("V", substr($binary, 68, 4))[1];
        $rightChild = unpack("V", substr($binary, 72, 4))[1];
        $childRoot = unpack("V", substr($binary, 76, 4))[1];
        $start_sect = unpack("V", substr($binary, 116, 4))[1];
        $size = unpack("V", substr($binary, 120, 4))[1];
        return ['index' => $index, 'name' => $name, 'type' => $type, 'leftChild' => $leftChild, 'rightChild' => $rightChild, 'child_root' => $childRoot, 'start_sect' => $start_sect, 'size' => $size];
    }

    private function findAttachments() {
        foreach ($this->directory as $id => $entry) {
            if ($entry['type'] == 1 && stripos($entry['name'], '__attach') === 0) {
                $attData = $this->extractAttachmentFromStorage($entry);
                if ($attData) $this->attachments[] = $attData;
            }
        }
    }

    private function extractAttachmentFromStorage($storageEntry) {
        $childrenIndices = $this->getAllChildren($storageEntry['child_root']);
        $filename = "unknown_" . $storageEntry['index'];
        $binaryData = null;

        foreach ($childrenIndices as $childIdx) {
            $child = $this->directory[$childIdx];
            $name = $child['name'];
            if (strpos($name, '3707001F') !== false) {
                $f = $this->getStreamData($child);
                $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $f);
            } elseif (strpos($name, '3001001F') !== false && strpos($filename, 'unknown') !== false) {
                $f = $this->getStreamData($child);
                $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $f);
            } elseif (strpos($name, '37010102') !== false) {
                $binaryData = $this->getStreamData($child);
            }
        }
        if ($binaryData && $filename) return ['name' => $filename, 'data' => $binaryData];
        return null;
    }

    private function getAllChildren($rootId) {
        $results = [];
        if ($rootId == 0xFFFFFFFF) return $results;
        $stack = [$rootId];
        while (!empty($stack)) {
            $currId = array_pop($stack);
            if (!isset($this->directory[$currId])) continue;
            $results[] = $currId;
            $node = $this->directory[$currId];
            if (isset($node['leftChild']) && $node['leftChild'] != 0xFFFFFFFF) $stack[] = $node['leftChild'];
            if (isset($node['rightChild']) && $node['rightChild'] != 0xFFFFFFFF) $stack[] = $node['rightChild'];
        }
        return $results;
    }

    private function getStreamData($entry) {
        if ($entry['size'] < 4096) return substr($this->readMiniChain($entry['start_sect']), 0, $entry['size']);
        else return substr($this->readChain($entry['start_sect']), 0, $entry['size']);
    }
}
