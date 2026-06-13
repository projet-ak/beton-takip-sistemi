<?php
/**
 * XlsxWriter — Hafif XLSX yazar (ZipArchive, bağımlılık yok)
 *
 * Stil indeksleri:
 *   S_HEADER   = 1  Başlık: bold beyaz, koyu yeşil arka plan
 *   S_TEXT     = 2  Metin verisi, ince kenarlık
 *   S_NUMBER   = 3  Sayı (#,##0.00), sağa hizalı
 *   S_DATE     = 4  Tarih (dd.mm.yyyy), ortaya hizalı
 *   S_TOT_TEXT = 5  Toplam satırı metin, bold
 *   S_TOT_NUM  = 6  Toplam satırı sayı, bold, sağa hizalı
 */
class XlsxWriter
{
    const S_HEADER   = 1;
    const S_TEXT     = 2;
    const S_NUMBER   = 3;
    const S_DATE     = 4;
    const S_TOT_TEXT = 5;
    const S_TOT_NUM  = 6;

    private array  $rows    = [];
    private array  $ss      = [];
    private array  $ssMap   = [];
    private array  $colW    = [];
    private int    $numCols = 0;
    private string $sheetName;

    public function __construct(string $sheetName = 'Veriler')
    {
        $this->sheetName = $sheetName;
    }

    /** Başlık satırı */
    public function header(array $labels): void
    {
        $cells = [];
        foreach ($labels as $i => $lbl) {
            $cells[] = ['v' => (string)$lbl, 't' => 's', 's' => self::S_HEADER];
            $this->minWidth($i, mb_strlen((string)$lbl) + 4);
        }
        $this->numCols = max($this->numCols, count($cells));
        $this->rows[]  = ['cells' => $cells];
    }

    /**
     * Veri satırı ekle.
     * $cells: [['v' => değer, 't' => 'text'|'number'|'date'], ...]
     */
    public function row(array $cells): void
    {
        $built = [];
        foreach ($cells as $i => $c) {
            $v  = $c['v'] ?? '';
            $ct = $c['t'] ?? 'text';
            if ($ct === 'number') {
                $num     = ($v === '' || $v === null) ? '' : (float)$v;
                $built[] = ['v' => $num, 't' => 'n', 's' => self::S_NUMBER];
                $this->minWidth($i, 14);
            } elseif ($ct === 'date') {
                $serial  = ($v !== '' && $v !== null) ? $this->dateSerial((string)$v) : '';
                $built[] = ['v' => $serial, 't' => 'n', 's' => self::S_DATE];
                $this->minWidth($i, 13);
            } else {
                $str     = (string)$v;
                $built[] = ['v' => $str, 't' => 's', 's' => self::S_TEXT];
                $this->minWidth($i, min(mb_strlen($str) + 2, 40));
            }
        }
        $this->numCols = max($this->numCols, count($built));
        $this->rows[]  = ['cells' => $built];
    }

    /** Toplam / özet satırı (bold) */
    public function total(array $cells): void
    {
        $built = [];
        foreach ($cells as $i => $c) {
            $v  = $c['v'] ?? '';
            $ct = $c['t'] ?? 'text';
            if ($ct === 'number') {
                $built[] = ['v' => ($v === '') ? '' : (float)$v, 't' => 'n', 's' => self::S_TOT_NUM];
            } else {
                $built[] = ['v' => (string)$v, 't' => 's', 's' => self::S_TOT_TEXT];
            }
        }
        $this->numCols = max($this->numCols, count($built));
        $this->rows[]  = ['cells' => $built];
    }

    /** Tarayıcıya gönder ve çık */
    public function download(string $filename): never
    {
        $xlsx = $this->build();
        // BOM veya önceki output'u temizle (config.php BOM sorunu)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . strlen($xlsx));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo $xlsx;
        exit;
    }

    // ── Özel ────────────────────────────────────────────────────

    private function minWidth(int $col, int $w): void
    {
        $this->colW[$col] = max($this->colW[$col] ?? 6, $w);
    }

    private function addSS(string $s): int
    {
        if (!isset($this->ssMap[$s])) {
            $this->ssMap[$s] = count($this->ss);
            $this->ss[]      = $s;
        }
        return $this->ssMap[$s];
    }

    private function dateSerial(string $date): float
    {
        // Sadece tarih kısmını al (Y-m-d)
        $d = substr($date, 0, 10);
        $ts = strtotime($d . ' 12:00:00 UTC');
        return $ts !== false ? (float)($ts / 86400 + 25569) : 0;
    }

    private function colLetter(int $n): string  // 0 tabanlı
    {
        $s = '';
        $n++;
        while ($n > 0) {
            $n--;
            $s = chr(65 + $n % 26) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }

    private function xe(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function build(): string
    {
        // sheet() önce çalışmalı — shared strings burada doldurulur
        $sheetXml = $this->buildSheet();

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',        $this->buildContentTypes());
        $zip->addFromString('_rels/.rels',                $this->buildRootRels());
        $zip->addFromString('xl/workbook.xml',            $this->buildWorkbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRels());
        $zip->addFromString('xl/styles.xml',              $this->buildStyles());
        $zip->addFromString('xl/sharedStrings.xml',       $this->buildSSXml());
        $zip->addFromString('xl/worksheets/sheet1.xml',   $sheetXml);

        $zip->close();
        $data = file_get_contents($tmp);
        unlink($tmp);
        return $data;
    }

    private function buildSheet(): string
    {
        $lastCol = $this->colLetter(max(0, $this->numCols - 1));

        // Sütun genişlikleri
        $colsXml = '';
        for ($i = 0; $i < $this->numCols; $i++) {
            $w = $this->colW[$i] ?? 10;
            $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1)
                . '" width="' . $w . '" bestFit="1" customWidth="1"/>';
        }

        // Satırlar
        $rowsXml = '';
        foreach ($this->rows as $ri => $row) {
            $rn       = $ri + 1;
            $isHeader = ($ri === 0);
            $ht       = $isHeader ? ' ht="20" customHeight="1"' : ' ht="15" customHeight="1"';
            $rowsXml .= '<row r="' . $rn . '"' . $ht . '>';
            foreach ($row['cells'] as $ci => $cell) {
                $ref   = $this->colLetter($ci) . $rn;
                $style = ' s="' . ($cell['s'] ?? 0) . '"';
                $v     = $cell['v'];
                $t     = $cell['t'];

                if ($v === '' || $v === null) {
                    $rowsXml .= '<c r="' . $ref . '"' . $style . '/>';
                } elseif ($t === 's') {
                    $idx      = $this->addSS((string)$v);
                    $rowsXml .= '<c r="' . $ref . '" t="s"' . $style . '><v>' . $idx . '</v></c>';
                } else {
                    // 'n' — sayı veya tarih
                    $rowsXml .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
                }
            }
            $rowsXml .= '</row>';
        }

        $pane      = '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>';
        $autoFilter = count($this->rows) > 0
            ? '<autoFilter ref="A1:' . $lastCol . '1"/>'
            : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0">' . $pane . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<cols>' . $colsXml . '</cols>'
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . $autoFilter
            . '</worksheet>';
    }

    private function buildStyles(): string
    {
        // Özel sayı formatları: 164 = #,##0.00 | 165 = dd.mm.yyyy
        $numFmts = '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="#,##0.00"/>'
            . '<numFmt numFmtId="165" formatCode="dd\.mm\.yyyy"/>'
            . '</numFmts>';

        // Yazı tipleri: 0=normal | 1=header bold beyaz | 2=total bold
        $fonts = '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>';

        // Dolgular: 0=yok | 1=gray125(zorunlu) | 2=koyu yeşil header
        $fills = '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid">'
            . '<fgColor rgb="FF00584E"/><bgColor indexed="64"/>'
            . '</patternFill></fill>'
            . '</fills>';

        // Kenarlıklar: 0=yok | 1=ince gri (tüm kenarlar)
        $thin    = 'style="thin"><color rgb="FFB8B8B8"/';
        $borders = '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border>'
            . '<left '   . $thin . '></left>'
            . '<right '  . $thin . '></right>'
            . '<top '    . $thin . '></top>'
            . '<bottom ' . $thin . '></bottom>'
            . '<diagonal/>'
            . '</border>'
            . '</borders>';

        $cSXfs = '<cellStyleXfs count="1">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
            . '</cellStyleXfs>';

        // Stil tablosu (xfId="0" = base style)
        // 0: varsayılan
        // 1: header
        // 2: metin
        // 3: sayı
        // 4: tarih
        // 5: toplam metin
        // 6: toplam sayı
        $ap = 'applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"';
        $cXfs = '<cellXfs count="7">'
            . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0"   fontId="1" fillId="2" borderId="1" xfId="0" ' . $ap . '>'
            .   '<alignment horizontal="center" vertical="center" wrapText="0"/></xf>'
            . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'
            .   '<alignment wrapText="0"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1">'
            .   '<alignment horizontal="right" wrapText="0"/></xf>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1">'
            .   '<alignment horizontal="center" wrapText="0"/></xf>'
            . '<xf numFmtId="0"   fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1">'
            .   '<alignment wrapText="0"/></xf>'
            . '<xf numFmtId="164" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1">'
            .   '<alignment horizontal="right" wrapText="0"/></xf>'
            . '</cellXfs>';

        $cellStyles = '<cellStyles count="1">'
            . '<cellStyle name="Normal" xfId="0" builtinId="0"/>'
            . '</cellStyles>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $numFmts . $fonts . $fills . $borders . $cSXfs . $cXfs . $cellStyles
            . '</styleSheet>';
    }

    private function buildSSXml(): string
    {
        $n    = count($this->ss);
        $body = '';
        foreach ($this->ss as $s) {
            $body .= '<si><t xml:space="preserve">' . $this->xe($s) . '</t></si>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' count="' . $n . '" uniqueCount="' . $n . '">' . $body . '</sst>';
    }

    private function buildWorkbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>'
            . '<sheet name="' . $this->xe($this->sheetName) . '" sheetId="1" r:id="rId1"/>'
            . '</sheets>'
            . '</workbook>';
    }

    private function buildWorkbookRels(): string
    {
        $ns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $ns . 'worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="' . $ns . 'styles" Target="styles.xml"/>'
            . '<Relationship Id="rId3" Type="' . $ns . 'sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';
    }

    private function buildRootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function buildContentTypes(): string
    {
        $pfx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml"  ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml"           ContentType="' . $pfx . 'sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml"  ContentType="' . $pfx . 'worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml"             ContentType="' . $pfx . 'styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml"      ContentType="' . $pfx . 'sharedStrings+xml"/>'
            . '</Types>';
    }
}
