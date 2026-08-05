import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const input = await FileBlob.load("D:/Project/Assets 2026/Sistem Warung/STOK.xlsx");
const workbook = await SpreadsheetFile.importXlsx(input);

const summary = await workbook.inspect({
  kind: "workbook,sheet,table,formula",
  maxChars: 12000,
  tableMaxRows: 20,
  tableMaxCols: 16,
  tableMaxCellChars: 160,
});

const stockTable = await workbook.inspect({
  kind: "region",
  sheetId: "BAHAN BAKU",
  range: "A1:O15",
  maxChars: 10000,
});

console.log(summary.ndjson);
console.log("---STOCK_TABLE---");
console.log(stockTable.ndjson);
