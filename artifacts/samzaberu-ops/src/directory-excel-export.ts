type RestaurantExportRow = {
  number: string;
  ou: string;
  legalEntity: string;
  address: string;
  restaurantPhone: string;
  actualDirector: string;
  actualPhone: string;
  generalDirector: string;
  generalPhone: string;
  email: string;
};

type ZipEntry = {
  name: string;
  data: Uint8Array;
};

const EXPORT_BUTTON_ID = 'directory-restaurants-excel-export';
const encoder = new TextEncoder();

const excelHeaders: Array<{ key: keyof RestaurantExportRow; label: string; width: number }> = [
  { key: 'number', label: '№', width: 7 },
  { key: 'ou', label: 'ОУ', width: 20 },
  { key: 'legalEntity', label: 'ООО', width: 28 },
  { key: 'address', label: 'Адрес', width: 42 },
  { key: 'restaurantPhone', label: 'Номер ресторана', width: 22 },
  { key: 'actualDirector', label: 'Фактический директор', width: 32 },
  { key: 'actualPhone', label: 'Телефон директора', width: 22 },
  { key: 'generalDirector', label: 'Генеральный директор', width: 32 },
  { key: 'generalPhone', label: 'Телефон ген. директора', width: 22 },
  { key: 'email', label: 'Почта', width: 34 },
];

const normalizeCellText = (value: string | null | undefined): string =>
  (value ?? '').replace(/\s+/g, ' ').trim().replace(/^—$/, '');

const phoneCellText = (cell: Element | undefined): string =>
  normalizeCellText(cell?.querySelector('.directory-phone-value span')?.textContent ?? cell?.textContent);

const collectRestaurantRows = (): RestaurantExportRow[] => {
  const rows: RestaurantExportRow[] = [];
  document.querySelectorAll<HTMLElement>('.directory-groups .directory-group').forEach((group) => {
    const ou = normalizeCellText(group.querySelector('.directory-group-title strong')?.textContent).replace(/^ОУ\s+/u, '');
    group.querySelectorAll<HTMLTableRowElement>('table.restaurants tbody tr').forEach((row) => {
      const cells = Array.from(row.cells);
      if (cells.length < 8) return;
      rows.push({
        number: normalizeCellText(cells[0]?.textContent),
        ou,
        legalEntity: normalizeCellText(cells[1]?.querySelector('strong')?.textContent),
        address: normalizeCellText(cells[1]?.querySelector('small')?.textContent),
        restaurantPhone: phoneCellText(cells[2]),
        actualDirector: normalizeCellText(cells[3]?.textContent),
        actualPhone: phoneCellText(cells[4]),
        generalDirector: normalizeCellText(cells[5]?.textContent),
        generalPhone: phoneCellText(cells[6]),
        email: normalizeCellText(cells[7]?.textContent),
      });
    });
  });
  return rows;
};

const escapeXml = (value: string): string => value
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;')
  .replace(/'/g, '&apos;');

const columnName = (index: number): string => {
  let value = index + 1;
  let result = '';
  while (value > 0) {
    const remainder = (value - 1) % 26;
    result = String.fromCharCode(65 + remainder) + result;
    value = Math.floor((value - 1) / 26);
  }
  return result;
};

const inlineStringCell = (ref: string, value: string, style: number): string =>
  `<c r="${ref}" t="inlineStr" s="${style}"><is><t xml:space="preserve">${escapeXml(value)}</t></is></c>`;

const buildWorksheetXml = (rows: RestaurantExportRow[]): string => {
  const headerCells = excelHeaders.map((column, index) => inlineStringCell(`${columnName(index)}1`, column.label, 1)).join('');
  const dataRows = rows.map((row, rowIndex) => {
    const excelRow = rowIndex + 2;
    const cells = excelHeaders.map((column, columnIndex) =>
      inlineStringCell(`${columnName(columnIndex)}${excelRow}`, row[column.key] ?? '', 2)).join('');
    return `<row r="${excelRow}" ht="28" customHeight="1">${cells}</row>`;
  }).join('');
  const columns = excelHeaders.map((column, index) =>
    `<col min="${index + 1}" max="${index + 1}" width="${column.width}" customWidth="1"/>`).join('');
  const lastColumn = columnName(excelHeaders.length - 1);
  const lastRow = Math.max(rows.length + 1, 1);

  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>
  <sheetFormatPr defaultRowHeight="18"/>
  <cols>${columns}</cols>
  <sheetData><row r="1" ht="30" customHeight="1">${headerCells}</row>${dataRows}</sheetData>
  <autoFilter ref="A1:${lastColumn}${lastRow}"/>
</worksheet>`;
};

const buildStylesXml = (): string => `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><sz val="11"/><name val="Calibri"/><family val="2"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><family val="2"/><color rgb="FF0F172A"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border><left style="thin"><color rgb="FFE2E8F0"/></left><right style="thin"><color rgb="FFE2E8F0"/></right><top style="thin"><color rgb="FFE2E8F0"/></top><bottom style="thin"><color rgb="FFE2E8F0"/></bottom><diagonal/></border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="3">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>`;

const contentTypesXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>`;

const packageRelsXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>`;

const workbookXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Рестораны и директора" sheetId="1" r:id="rId1"/></sheets>
</workbook>`;

const workbookRelsXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>`;

const buildCoreXml = (): string => {
  const now = new Date().toISOString();
  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>Рестораны и директора</dc:title><dc:creator>Evrasia AI Bot</dc:creator><cp:lastModifiedBy>Evrasia AI Bot</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">${now}</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">${now}</dcterms:modified>
</cp:coreProperties>`;
};

const appXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>Evrasia AI Bot</Application><AppVersion>1.6.9</AppVersion>
</Properties>`;

const crcTable = (() => {
  const table = new Uint32Array(256);
  for (let n = 0; n < 256; n += 1) {
    let c = n;
    for (let k = 0; k < 8; k += 1) c = (c & 1) ? (0xedb88320 ^ (c >>> 1)) : (c >>> 1);
    table[n] = c >>> 0;
  }
  return table;
})();

const crc32 = (bytes: Uint8Array): number => {
  let crc = 0xffffffff;
  for (const byte of bytes) crc = crcTable[(crc ^ byte) & 0xff]! ^ (crc >>> 8);
  return (crc ^ 0xffffffff) >>> 0;
};

const concatBytes = (parts: Uint8Array[]): Uint8Array => {
  const result = new Uint8Array(parts.reduce((sum, part) => sum + part.length, 0));
  let offset = 0;
  for (const part of parts) { result.set(part, offset); offset += part.length; }
  return result;
};

const zipTimestamp = (date = new Date()) => {
  const year = Math.max(date.getFullYear(), 1980);
  return {
    time: ((date.getHours() & 0x1f) << 11) | ((date.getMinutes() & 0x3f) << 5) | ((Math.floor(date.getSeconds() / 2)) & 0x1f),
    date: (((year - 1980) & 0x7f) << 9) | (((date.getMonth() + 1) & 0x0f) << 5) | (date.getDate() & 0x1f),
  };
};

const localHeader = (name: Uint8Array, data: Uint8Array, crc: number, time: number, date: number): Uint8Array => {
  const buffer = new ArrayBuffer(30);
  const view = new DataView(buffer);
  view.setUint32(0, 0x04034b50, true);
  view.setUint16(4, 20, true);
  view.setUint16(6, 0x0800, true);
  view.setUint16(8, 0, true);
  view.setUint16(10, time, true);
  view.setUint16(12, date, true);
  view.setUint32(14, crc, true);
  view.setUint32(18, data.length, true);
  view.setUint32(22, data.length, true);
  view.setUint16(26, name.length, true);
  view.setUint16(28, 0, true);
  return concatBytes([new Uint8Array(buffer), name, data]);
};

const centralHeader = (name: Uint8Array, data: Uint8Array, crc: number, time: number, date: number, offset: number): Uint8Array => {
  const buffer = new ArrayBuffer(46);
  const view = new DataView(buffer);
  view.setUint32(0, 0x02014b50, true);
  view.setUint16(4, 20, true);
  view.setUint16(6, 20, true);
  view.setUint16(8, 0x0800, true);
  view.setUint16(10, 0, true);
  view.setUint16(12, time, true);
  view.setUint16(14, date, true);
  view.setUint32(16, crc, true);
  view.setUint32(20, data.length, true);
  view.setUint32(24, data.length, true);
  view.setUint16(28, name.length, true);
  view.setUint16(30, 0, true);
  view.setUint16(32, 0, true);
  view.setUint16(34, 0, true);
  view.setUint16(36, 0, true);
  view.setUint32(38, 0, true);
  view.setUint32(42, offset, true);
  return concatBytes([new Uint8Array(buffer), name]);
};

const endOfCentralDirectory = (entries: number, centralSize: number, centralOffset: number): Uint8Array => {
  const buffer = new ArrayBuffer(22);
  const view = new DataView(buffer);
  view.setUint32(0, 0x06054b50, true);
  view.setUint16(4, 0, true);
  view.setUint16(6, 0, true);
  view.setUint16(8, entries, true);
  view.setUint16(10, entries, true);
  view.setUint32(12, centralSize, true);
  view.setUint32(16, centralOffset, true);
  view.setUint16(20, 0, true);
  return new Uint8Array(buffer);
};

const createZip = (entries: ZipEntry[]): Uint8Array => {
  const locals: Uint8Array[] = [];
  const centrals: Uint8Array[] = [];
  const stamp = zipTimestamp();
  let offset = 0;

  for (const entry of entries) {
    const name = encoder.encode(entry.name);
    const crc = crc32(entry.data);
    const local = localHeader(name, entry.data, crc, stamp.time, stamp.date);
    locals.push(local);
    centrals.push(centralHeader(name, entry.data, crc, stamp.time, stamp.date, offset));
    offset += local.length;
  }

  const central = concatBytes(centrals);
  return concatBytes([...locals, central, endOfCentralDirectory(entries.length, central.length, offset)]);
};

const createWorkbook = (rows: RestaurantExportRow[]): Uint8Array => createZip([
  { name: '[Content_Types].xml', data: encoder.encode(contentTypesXml) },
  { name: '_rels/.rels', data: encoder.encode(packageRelsXml) },
  { name: 'docProps/core.xml', data: encoder.encode(buildCoreXml()) },
  { name: 'docProps/app.xml', data: encoder.encode(appXml) },
  { name: 'xl/workbook.xml', data: encoder.encode(workbookXml) },
  { name: 'xl/_rels/workbook.xml.rels', data: encoder.encode(workbookRelsXml) },
  { name: 'xl/styles.xml', data: encoder.encode(buildStylesXml()) },
  { name: 'xl/worksheets/sheet1.xml', data: encoder.encode(buildWorksheetXml(rows)) },
]);

const downloadRestaurantExcel = () => {
  const rows = collectRestaurantRows();
  if (!rows.length) {
    window.alert('Нет данных для выгрузки. Проверьте фильтр или дождитесь загрузки справочника.');
    return;
  }
  const workbook = createWorkbook(rows);
  const blob = new Blob([workbook], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  const date = new Date().toLocaleDateString('sv-SE');
  link.href = url;
  link.download = `Рестораны_и_директора_${date}.xlsx`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
};

const restaurantsTabIsActive = (): boolean => {
  const activeTab = document.querySelector<HTMLButtonElement>('.directory-tabs button.active');
  return normalizeCellText(activeTab?.textContent).includes('Рестораны и директора');
};

const syncExportButton = () => {
  const existing = document.getElementById(EXPORT_BUTTON_ID);
  if (!restaurantsTabIsActive()) {
    existing?.remove();
    return;
  }
  if (existing) return;
  const toolbar = document.querySelector<HTMLElement>('.directory-toolbar');
  if (!toolbar) return;
  const button = document.createElement('button');
  button.id = EXPORT_BUTTON_ID;
  button.type = 'button';
  button.className = 'directory-secondary-button';
  button.textContent = 'Выгрузить Excel';
  button.title = 'Выгрузить текущую выборку «Рестораны и директора» в Excel';
  button.addEventListener('click', downloadRestaurantExcel);
  toolbar.appendChild(button);
};

export const installRestaurantExcelExport = () => {
  syncExportButton();
  let scheduled = false;
  const observer = new MutationObserver(() => {
    if (scheduled) return;
    scheduled = true;
    window.requestAnimationFrame(() => {
      scheduled = false;
      syncExportButton();
    });
  });
  observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
};
