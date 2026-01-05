<?php

namespace PhpPgAdmin\Database\Export;

/**
 * CSV Format Formatter
 * Converts table data to RFC 4180 compliant CSV
 */
class CsvFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/csv; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'csv';
    /** @var bool */
    protected $supportsGzip = true;

    /** @var \PhpPgAdmin\Database\Postgres */
    private $pg;

    /**
     * Format ADORecordSet as CSV
     * @param \ADORecordSet $recordset ADORecordSet
     * @param array $metadata Optional (unused, columns come from recordset)
     */
    public function format($recordset, $metadata = [])
    {
        if (!$recordset || $recordset->EOF) {
            return;
        }

        $this->pg = $this->postgres();

        // Detect bytea columns once
        $is_bytea = [];
        $col_count = count($recordset->fields);

        for ($i = 0; $i < $col_count; $i++) {
            $finfo = $recordset->fetchField($i);
            $type = strtolower($finfo->type ?? '');
            $is_bytea[$i] = ($type === 'bytea');
        }

        // Header
        $columns = [];
        for ($i = 0; $i < $col_count; $i++) {
            $finfo = $recordset->fetchField($i);
            $columns[$i] = $finfo->name ?? "Column $i";
        }
        $this->write($this->csvLineRaw($columns));

        // Rows
        while (!$recordset->EOF) {
            $this->write($this->csvLineRecord($recordset->fields, $is_bytea));
            $recordset->moveNext();
        }
    }

    /**
     * CSV line for header (no bytea)
     */
    private function csvLineRaw(array $fields): string
    {
        $out = '';
        $sep = '';

        foreach ($fields as $field) {
            $out .= $sep;
            $out .= $this->csvField($field);
            $sep = ',';
        }

        return $out . "\r\n";
    }

    /**
     * CSV line for data rows (with bytea support)
     */
    private function csvLineRecord(array $fields, array $is_bytea): string
    {
        $out = '';
        $sep = '';

        foreach ($fields as $i => $value) {
            $out .= $sep;

            if ($value === null) {
                // empty field
                // nothing appended
            } else {
                if ($is_bytea[$i]) {
                    // bytea → escapeBytea → then CSV-escape
                    $value = $this->pg->escapeBytea($value);
                }
                $out .= $this->csvField($value);
            }

            $sep = ',';
        }

        return $out . "\r\n";
    }

    /**
     * Fast CSV field escaping
     */
    private function csvField($value): string
    {
        $value = (string) $value;

        // One scan instead of 3× strpos()
        if (strpbrk($value, ",\"\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

}
