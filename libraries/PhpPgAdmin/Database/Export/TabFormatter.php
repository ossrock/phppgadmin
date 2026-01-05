<?php

namespace PhpPgAdmin\Database\Export;

/**
 * Tab-Delimited Format Formatter
 * Converts table data to tab-separated values with quoted fields
 */
class TabFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/plain; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'txt';
    /** @var bool */
    protected $supportsGzip = true;

    /**
     * Format ADORecordSet as tab-delimited
     * @param \ADORecordSet $recordset ADORecordSet
     * @param array $metadata Optional (unused, columns come from recordset)
     */
    public function format($recordset, $metadata = [])
    {
        if (!$recordset || $recordset->EOF) {
            return;
        }

        $col_count = count($recordset->fields);

        // Header
        $header = '';
        $sep = '';

        for ($i = 0; $i < $col_count; $i++) {
            $finfo = $recordset->fetchField($i);
            $name = $finfo->name ?? "Column $i";

            $header .= $sep;
            $header .= $this->escapeTabField($name);
            $sep = "\t";
        }

        $this->write($header . "\r\n");

        // Rows
        while (!$recordset->EOF) {
            $line = '';
            $sep = '';

            foreach ($recordset->fields as $value) {
                $line .= $sep;

                if ($value !== null) {
                    $line .= $this->escapeTabField($value);
                }

                $sep = "\t";
            }

            $this->write("$line\r\n");
            $recordset->moveNext();
        }
    }

    /**
     * Fast tab-field escaping
     */
    private function escapeTabField($value): string
    {
        $value = (string) $value;

        if (strpbrk($value, "\t\"\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

}
