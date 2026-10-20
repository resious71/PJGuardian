<?php

declare(strict_types=1);

namespace PJGuardian\Services\Export;

/**
 * XLSX(P23) — 새 패키지 없이 ZipArchive + XMLWriter. 시트 XML은 임시 파일에 행마다 스트리밍으로 쓰고(전체 행을 메모리에 올리지 않음),
 * 마지막에 고정 부분([Content_Types].xml, _rels, workbook, styles)과 함께 zip으로 묶는다.
 *
 * - 문자열 셀은 모두 인라인 문자열(t="inlineStr")만 쓴다. 수식 셀(<f>)은 만들지 않는다(주입 방지). 값은 SheetText::clean.
 * - 숫자 열(int)은 숫자 셀, 날짜 열(datetime)은 KST로 바꾼 Excel 일련값 + "yyyy-mm-dd hh:mm" 형식 셀.
 * - 시트 1: 머리글 굵게·고정(freeze), 자동 필터, 열 너비, 줄바꿈이 있는 문자열은 셀 안 줄바꿈 형식. 시트 2 "조건": [항목, 값].
 */
final class XlsxWriter implements SheetWriter
{
    private const S_HEADER = 1;
    private const S_WRAP = 2;
    private const S_DATE = 3;
    private const S_INT = 4;

    private \XMLWriter $x;
    /** @var list<array{0:string, 1:string, 2:int}> */
    private array $columns = [];
    private int $rowNo = 0;
    private string $sheetFile;

    public function __construct(private readonly string $path, private readonly \DateTimeZone $tz, private readonly string $sheetName, private readonly string $infoName)
    {
        $this->sheetFile = $path . '.sheet1.xml';
        $this->x = new \XMLWriter();
        if (!$this->x->openUri($this->sheetFile)) {
            throw new \RuntimeException('내보내기 임시 파일을 만들 수 없음');
        }
    }

    public function begin(array $columns): void
    {
        $this->columns = $columns;
        $x = $this->x;
        $x->startDocument('1.0', 'UTF-8', 'yes');
        $x->startElement('worksheet');
        $x->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $x->writeRaw('<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>');
        $x->writeRaw('<sheetFormatPr defaultRowHeight="15"/>');
        $x->startElement('cols');
        foreach ($columns as $i => $c) {
            $x->startElement('col');
            $x->writeAttribute('min', (string) ($i + 1));
            $x->writeAttribute('max', (string) ($i + 1));
            $x->writeAttribute('width', (string) $c[2]);
            $x->writeAttribute('customWidth', '1');
            $x->endElement();
        }
        $x->endElement();
        $x->startElement('sheetData');
        $this->writeRow(array_map(static fn (array $c): string => $c[0], $columns), true);
    }

    public function row(array $row): void
    {
        $this->writeRow($row, false);
        if ($this->rowNo % 500 === 0) {
            $this->x->flush();
        }
    }

    public function finish(array $info): string
    {
        $x = $this->x;
        $x->endElement(); // sheetData
        $last = self::col(count($this->columns) - 1) . max(1, $this->rowNo);
        $x->startElement('autoFilter');
        $x->writeAttribute('ref', 'A1:' . $last);
        $x->endElement();
        $x->endElement(); // worksheet
        $x->endDocument();
        $x->flush();
        unset($this->x);

        $zip = new \ZipArchive();
        if ($zip->open($this->path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($this->sheetFile);
            throw new \RuntimeException('내보내기 파일을 만들 수 없음');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $name1 = self::xml(self::sheetName($this->sheetName));
        $name2 = self::xml(self::sheetName($this->infoName));
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $name1 . '" sheetId="1" r:id="rId1"/><sheet name="' . $name2 . '" sheetId="2" r:id="rId2"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
            . "'" . $name1 . "'!\$A\$1:\$" . self::col(count($this->columns) - 1) . '$' . max(1, $this->rowNo) . '</definedName></definedNames>'
            . '</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFile($this->sheetFile, 'xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet2.xml', self::infoSheet($info));
        $ok = $zip->close();
        @unlink($this->sheetFile);
        if (!$ok) {
            @unlink($this->path);
            throw new \RuntimeException('내보내기 파일을 만들 수 없음');
        }
        return $this->path;
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function extension(): string
    {
        return 'xlsx';
    }

    /** 쓰다 만 임시 파일 정리(중단) */
    public function abort(): void
    {
        unset($this->x);
        @unlink($this->sheetFile);
        @unlink($this->path);
    }

    /** @param list<mixed> $values */
    private function writeRow(array $values, bool $header): void
    {
        $this->rowNo++;
        $x = $this->x;
        $x->startElement('row');
        $x->writeAttribute('r', (string) $this->rowNo);
        foreach ($values as $i => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $ref = self::col($i) . $this->rowNo;
            $type = $header ? 'string' : ($this->columns[$i][1] ?? 'string');
            $x->startElement('c');
            $x->writeAttribute('r', $ref);
            if ($type === 'int') {
                $x->writeAttribute('s', (string) self::S_INT);
                $x->writeElement('v', (string) (int) $v);
            } elseif ($type === 'datetime' && ($serial = $this->serial((string) $v)) !== null) {
                $x->writeAttribute('s', (string) self::S_DATE);
                $x->writeElement('v', $serial);
            } else {
                $s = SheetText::clean((string) $v);
                $x->writeAttribute('t', 'inlineStr');
                if ($header) {
                    $x->writeAttribute('s', (string) self::S_HEADER);
                } elseif (str_contains($s, "\n")) {
                    $x->writeAttribute('s', (string) self::S_WRAP);
                }
                $x->startElement('is');
                $x->startElement('t');
                $x->writeAttribute('xml:space', 'preserve');
                $x->text($s);
                $x->endElement();
                $x->endElement();
            }
            $x->endElement();
        }
        $x->endElement();
    }

    /** UTC ISO → KST Excel 일련값(1900 날짜 체계, 1899-12-30 기준) */
    private function serial(string $iso): ?string
    {
        try {
            $t = new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
        $local = $t->getTimestamp() + $this->tz->getOffset($t);
        return rtrim(rtrim(sprintf('%.8F', $local / 86400 + 25569), '0'), '.');
    }

    /** 0 → A, 25 → Z, 26 → AA */
    public static function col(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }

    private static function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** 시트 이름: 31자, []:*?/\ 금지 */
    private static function sheetName(string $s): string
    {
        return mb_substr((string) preg_replace('#[\[\]:*?/\\\\]#', '_', $s), 0, 31);
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd hh:mm"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="맑은 고딕"/></font><font><b/><sz val="11"/><name val="맑은 고딕"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /** @param list<array{0:string, 1:string}> $info */
    private static function infoSheet(array $info): string
    {
        $x = new \XMLWriter();
        $x->openMemory();
        $x->startDocument('1.0', 'UTF-8', 'yes');
        $x->startElement('worksheet');
        $x->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $x->writeRaw('<cols><col min="1" max="1" width="22" customWidth="1"/><col min="2" max="2" width="80" customWidth="1"/></cols>');
        $x->startElement('sheetData');
        foreach (array_values($info) as $n => [$k, $v]) {
            $x->startElement('row');
            $x->writeAttribute('r', (string) ($n + 1));
            foreach ([$k, $v] as $i => $text) {
                $x->startElement('c');
                $x->writeAttribute('r', self::col($i) . ($n + 1));
                $x->writeAttribute('t', 'inlineStr');
                if ($i === 0) {
                    $x->writeAttribute('s', (string) self::S_HEADER);
                }
                $x->startElement('is');
                $x->startElement('t');
                $x->writeAttribute('xml:space', 'preserve');
                $x->text(SheetText::clean($text));
                $x->endElement();
                $x->endElement();
                $x->endElement();
            }
            $x->endElement();
        }
        $x->endElement();
        $x->endElement();
        $x->endDocument();
        return $x->outputMemory();
    }
}
